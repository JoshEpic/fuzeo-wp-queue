<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Inspection;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Redis\RedisClient;
use Fuzeo\Queue\Redis\RedisKeys;
use Fuzeo\Queue\Support\SystemClock;

final class RedisJobCatalog implements JobCatalog
{
    private const SCAN_CAP = 2000;

    public function __construct(
        private readonly RedisClient $redis,
        private readonly RedisKeys $keys,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function inspect(string $jobId): JobInspection
    {
        $raw = $this->redis->command('HGETALL', [$this->keys->job($jobId)]);
        if (!is_array($raw) || $raw === []) {
            throw new DriverException('Unknown job ' . $jobId . '.');
        }
        $map = $this->pairs($raw);
        $envelope = $this->decode((string) ($map['envelope'] ?? ''));
        $reserved = $map['reserved_at'] ?? null;
        $lease = $map['lease_expires_at'] ?? null;
        $worker = $map['worker_id'] ?? null;
        $cancel = $this->redis->command('EXISTS', [$this->keys->jobCancel($jobId)]);

        return new JobInspection(
            $envelope,
            is_string($worker) && $worker !== '' ? $worker : null,
            is_string($reserved) && $reserved !== '' ? new \DateTimeImmutable($reserved) : null,
            is_string($lease) && $lease !== '' ? new \DateTimeImmutable($lease) : null,
            (int) $cancel === 1,
        );
    }

    public function list(JobQuery $query): JobPage
    {
        $query = $query->bounded();
        if ($query->jobId !== null) {
            try {
                $item = $this->inspect($query->jobId)->envelope;
            } catch (DriverException) {
                return new JobPage([], $query->limit, $query->offset, 0);
            }

            return new JobPage([$item], $query->limit, $query->offset, 1);
        }
        $ids = $this->idsForState($query->state);
        $matched = [];
        $inspected = 0;
        $truncated = false;
        foreach ($ids as $id) {
            if ($inspected++ >= self::SCAN_CAP) {
                $truncated = true;
                break;
            }
            try {
                $envelope = $this->inspect($id)->envelope;
            } catch (DriverException) {
                continue;
            }
            if ($this->matches($envelope, $query)) {
                $matched[] = $envelope;
            }
        }
        $total = count($matched);
        $page = array_slice($matched, $query->offset, $query->limit);

        return new JobPage($page, $query->limit, $query->offset, $total, $truncated);
    }

    public function queueSnapshots(?int $siteId = null): array
    {
        $now = $this->clock->now();
        $queues = $this->redis->command('SMEMBERS', [$this->keys->queues()]);
        if (!is_array($queues)) {
            $queues = [];
        }
        $out = [];
        foreach ($queues as $queue) {
            if (!is_string($queue) || $queue === '') {
                continue;
            }
            $pending = 0;
            $retrying = 0;
            $oldest = null;
            foreach ([$this->keys->ready($queue), $this->keys->delayed($queue)] as $zset) {
                $ids = $this->redis->command('ZRANGE', [$zset, '0', '200']);
                if (!is_array($ids)) {
                    continue;
                }
                foreach ($ids as $id) {
                    if (!is_string($id)) {
                        continue;
                    }
                    try {
                        $envelope = $this->inspect($id)->envelope;
                    } catch (DriverException) {
                        continue;
                    }
                    if ($siteId !== null && $envelope->context->siteId !== $siteId) {
                        continue;
                    }
                    if ($envelope->availableAt > $now) {
                        $retrying++;
                    } else {
                        $pending++;
                        $age = $now->getTimestamp() - $envelope->availableAt->getTimestamp();
                        $oldest = $oldest === null ? $age : min($oldest, $age);
                    }
                }
            }
            $reserved = 0;
            $dead = 0;
            $cancelled = 0;
            $out[] = new QueueSnapshot($queue, $pending, $reserved, $retrying, $dead, $cancelled, $oldest);
        }

        return $out;
    }

    public function oldestEligibleAgeSeconds(?string $queue = null, ?int $siteId = null): ?int
    {
        $oldest = null;
        foreach ($this->queueSnapshots($siteId) as $snapshot) {
            if ($queue !== null && $snapshot->queue !== $queue) {
                continue;
            }
            if ($snapshot->oldestPendingAgeSeconds === null) {
                continue;
            }
            $oldest = $oldest === null
                ? $snapshot->oldestPendingAgeSeconds
                : min($oldest, $snapshot->oldestPendingAgeSeconds);
        }

        return $oldest;
    }

    /**
     * @return list<string>
     */
    private function idsForState(?JobState $state): array
    {
        if ($state === JobState::Dead || $state === JobState::Failed) {
            return $this->zrev($this->keys->dead());
        }
        if ($state === JobState::Completed) {
            return $this->zrev($this->keys->completed());
        }
        if ($state === JobState::Reserved) {
            return $this->zrev($this->keys->reserved());
        }
        $ids = [];
        $queues = $this->redis->command('SMEMBERS', [$this->keys->queues()]);
        if (is_array($queues)) {
            foreach ($queues as $queue) {
                if (!is_string($queue)) {
                    continue;
                }
                $ids = array_merge($ids, $this->zrev($this->keys->ready($queue)), $this->zrev($this->keys->delayed($queue)));
            }
        }
        if ($state === JobState::Pending) {
            return $ids;
        }

        return array_merge($ids, $this->zrev($this->keys->reserved()), $this->zrev($this->keys->dead()), $this->zrev($this->keys->completed()));
    }

    /**
     * @return list<string>
     */
    private function zrev(string $key): array
    {
        $ids = $this->redis->command('ZREVRANGE', [$key, '0', (string) (self::SCAN_CAP - 1)]);
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            if (is_string($id)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    private function matches(Envelope $envelope, JobQuery $query): bool
    {
        if ($query->state !== null && $envelope->state !== $query->state) {
            return false;
        }
        if ($query->queue !== null && $envelope->queue !== $query->queue) {
            return false;
        }
        if ($query->jobType !== null && $envelope->jobType !== $query->jobType) {
            return false;
        }
        if ($query->origin !== null && $envelope->origin->package !== $query->origin) {
            return false;
        }
        if ($query->siteId !== null && $envelope->context->siteId !== $query->siteId) {
            return false;
        }
        if ($query->tag !== null && !in_array($query->tag, $envelope->tags, true)) {
            return false;
        }

        return true;
    }

    private function decode(string $raw): Envelope
    {
        if ($raw === '') {
            throw new DriverException('Unknown job.');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DriverException('Corrupt job envelope.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new DriverException('Corrupt job envelope.');
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

    /**
     * @param array<int|string, mixed> $row
     * @return array<string, string>
     */
    private function pairs(array $row): array
    {
        if ($row !== [] && array_is_list($row)) {
            $map = [];
            for ($i = 0; $i + 1 < count($row); $i += 2) {
                $map[(string) $row[$i]] = (string) $row[$i + 1];
            }

            return $map;
        }
        $out = [];
        foreach ($row as $key => $value) {
            $out[(string) $key] = is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }
}
