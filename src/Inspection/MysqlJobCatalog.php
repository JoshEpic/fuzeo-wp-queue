<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Inspection;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\QueueName;
use Fuzeo\Queue\Persistence\Connection;
use Fuzeo\Queue\Persistence\Schema;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\SystemClock;

final class MysqlJobCatalog implements JobCatalog
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function inspect(string $jobId): JobInspection
    {
        $row = $this->connection->selectOne('SELECT * FROM ' . $this->jobs() . ' WHERE `job_id` = ?', [$jobId]);
        if ($row === null) {
            throw new DriverException('Unknown job ' . $jobId . '.');
        }

        return $this->inspection($row);
    }

    public function list(JobQuery $query): JobPage
    {
        $query = $query->bounded();
        if ($query->jobId !== null) {
            $row = $this->connection->selectOne('SELECT * FROM ' . $this->jobs() . ' WHERE `job_id` = ?', [$query->jobId]);
            $items = $row === null ? [] : [$this->envelope($row)];
            if ($items !== [] && !$this->matchesEnvelope($items[0], $query)) {
                $items = [];
            }

            return new JobPage($items, $query->limit, $query->offset, count($items));
        }
        [$where, $bindings] = $this->where($query);
        $countRow = $this->connection->selectOne(
            'SELECT COUNT(*) AS `c` FROM ' . $this->jobs() . $where,
            $bindings
        );
        $total = (int) ($countRow['c'] ?? 0);
        $rows = $this->connection->select(
            'SELECT * FROM ' . $this->jobs() . $where . ' ORDER BY `created_at` DESC LIMIT ' . $query->limit . ' OFFSET ' . $query->offset,
            $bindings
        );
        $items = [];
        foreach ($rows as $row) {
            $envelope = $this->envelope($row);
            if ($query->tag !== null && !in_array($query->tag, $envelope->tags, true)) {
                continue;
            }
            $items[] = $envelope;
        }

        return new JobPage($items, $query->limit, $query->offset, $total);
    }

    public function queueSnapshots(?int $siteId = null): array
    {
        $now = Dates::toDatabase($this->clock->now());
        $siteSql = $siteId === null ? '' : ' AND `site_id` = ?';
        $bindings = $siteId === null ? [] : [$siteId];
        $rows = $this->connection->select(
            'SELECT `queue`,
                    SUM(CASE WHEN `state` = \'pending\' AND `available_at` <= ? THEN 1 ELSE 0 END) AS `pending`,
                    SUM(CASE WHEN `state` = \'reserved\' THEN 1 ELSE 0 END) AS `reserved`,
                    SUM(CASE WHEN `state` = \'pending\' AND `available_at` > ? THEN 1 ELSE 0 END) AS `retrying`,
                    SUM(CASE WHEN `state` IN (\'dead\', \'failed\') THEN 1 ELSE 0 END) AS `dead`,
                    SUM(CASE WHEN `state` = \'cancelled\' THEN 1 ELSE 0 END) AS `cancelled`,
                    MIN(CASE WHEN `state` = \'pending\' AND `available_at` <= ? THEN `available_at` ELSE NULL END) AS `oldest`
             FROM ' . $this->jobs() . '
             WHERE 1=1' . $siteSql . '
             GROUP BY `queue`
             ORDER BY `queue` ASC',
            array_merge([$now, $now, $now], $bindings)
        );
        $clockNow = $this->clock->now()->getTimestamp();
        $out = [];
        foreach ($rows as $row) {
            $oldest = $row['oldest'] ?? null;
            $age = is_string($oldest) && $oldest !== ''
                ? $clockNow - (new \DateTimeImmutable($oldest, new \DateTimeZone('UTC')))->getTimestamp()
                : null;
            $out[] = new QueueSnapshot(
                (string) ($row['queue'] ?? ''),
                (int) ($row['pending'] ?? 0),
                (int) ($row['reserved'] ?? 0),
                (int) ($row['retrying'] ?? 0),
                (int) ($row['dead'] ?? 0),
                (int) ($row['cancelled'] ?? 0),
                $age,
            );
        }

        return $out;
    }

    public function oldestEligibleAgeSeconds(?string $queue = null, ?int $siteId = null): ?int
    {
        $now = Dates::toDatabase($this->clock->now());
        $sql = 'SELECT MIN(`available_at`) AS `oldest` FROM ' . $this->jobs() . ' WHERE `state` = ? AND `available_at` <= ?';
        $bindings = [JobState::Pending->value, $now];
        if ($queue !== null) {
            QueueName::assertValid($queue);
            $sql .= ' AND `queue` = ?';
            $bindings[] = $queue;
        }
        if ($siteId !== null) {
            $sql .= ' AND `site_id` = ?';
            $bindings[] = $siteId;
        }
        $row = $this->connection->selectOne($sql, $bindings);
        $oldest = $row['oldest'] ?? null;
        if (!is_string($oldest) || $oldest === '') {
            return null;
        }

        return $this->clock->now()->getTimestamp() - (new \DateTimeImmutable($oldest, new \DateTimeZone('UTC')))->getTimestamp();
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function where(JobQuery $query): array
    {
        $parts = [' WHERE 1=1'];
        $bindings = [];
        if ($query->state !== null) {
            $parts[] = ' AND `state` = ?';
            $bindings[] = $query->state->value;
        }
        if ($query->queue !== null) {
            QueueName::assertValid($query->queue);
            $parts[] = ' AND `queue` = ?';
            $bindings[] = $query->queue;
        }
        if ($query->jobType !== null) {
            $parts[] = ' AND `job_type` = ?';
            $bindings[] = $query->jobType;
        }
        if ($query->origin !== null) {
            $parts[] = ' AND `origin_package` = ?';
            $bindings[] = $query->origin;
        }
        if ($query->siteId !== null) {
            $parts[] = ' AND `site_id` = ?';
            $bindings[] = $query->siteId;
        }
        if ($query->createdAfter !== null) {
            $parts[] = ' AND `created_at` >= ?';
            $bindings[] = Dates::toDatabase($query->createdAfter);
        }
        if ($query->createdBefore !== null) {
            $parts[] = ' AND `created_at` <= ?';
            $bindings[] = Dates::toDatabase($query->createdBefore);
        }

        return [implode('', $parts), $bindings];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function inspection(array $row): JobInspection
    {
        $reserved = $row['reserved_at'] ?? null;
        $lease = $row['lease_expires_at'] ?? null;
        $worker = $row['worker_id'] ?? null;
        $failure = $row['failure_message'] ?? null;
        $class = $row['failure_class'] ?? null;

        return new JobInspection(
            $this->envelope($row),
            is_string($worker) && $worker !== '' ? $worker : null,
            is_string($reserved) && $reserved !== '' ? Dates::fromDatabase($reserved) : null,
            is_string($lease) && $lease !== '' ? Dates::fromDatabase($lease) : null,
            (int) ($row['cancel_requested'] ?? 0) === 1,
            is_string($class) && $class !== '' ? $class : null,
            is_string($failure) && $failure !== '' ? $failure : null,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function envelope(array $row): Envelope
    {
        $json = $row['envelope'] ?? null;
        if (!is_string($json) || $json === '') {
            throw new DriverException('Persisted job is missing envelope JSON.');
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DriverException('Persisted job envelope JSON is corrupt.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new DriverException('Persisted job envelope JSON must be an object.');
        }
        if (isset($decoded['envelope_version']) && is_numeric($decoded['envelope_version'])) {
            $decoded['envelope_version'] = (int) $decoded['envelope_version'];
        }
        foreach (['schema_version', 'priority', 'attempt', 'max_attempts', 'timeout_seconds', 'network_id', 'site_id'] as $intKey) {
            if (isset($decoded[$intKey]) && is_numeric($decoded[$intKey])) {
                $decoded[$intKey] = (int) $decoded[$intKey];
            }
        }

        return Envelope::fromArray($decoded);
    }

    private function matchesEnvelope(Envelope $envelope, JobQuery $query): bool
    {
        if ($query->state !== null && $envelope->state !== $query->state) {
            return false;
        }
        if ($query->queue !== null && $envelope->queue !== $query->queue) {
            return false;
        }
        if ($query->origin !== null && $envelope->origin->package !== $query->origin) {
            return false;
        }
        if ($query->siteId !== null && $envelope->context->siteId !== $query->siteId) {
            return false;
        }

        return true;
    }

    private function jobs(): string
    {
        return Schema::quoteTable($this->connection->prefix(), Schema::JOBS);
    }
}
