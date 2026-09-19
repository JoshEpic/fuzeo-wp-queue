<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Redis\RedisClient;
use Fuzeo\Queue\Redis\RedisKeys;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\SystemClock;

final class RedisScheduleStore implements ScheduleStore
{
    public function __construct(
        private readonly RedisClient $redis,
        private readonly RedisKeys $keys,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function save(ScheduleDefinition $schedule): void
    {
        $json = json_encode($schedule->toArray(), JSON_THROW_ON_ERROR);
        $this->redis->command('SET', [$this->keys->schedule($schedule->scheduleId), $json]);
        $this->redis->command('SADD', [$this->keys->schedules(), $schedule->scheduleId]);
        if ($schedule->enabled && $schedule->blockedReason === null) {
            $this->redis->command('ZADD', [$this->keys->scheduleDue(), (string) $schedule->nextRunAt->getTimestamp(), $schedule->scheduleId]);
        } else {
            $this->redis->command('ZREM', [$this->keys->scheduleDue(), $schedule->scheduleId]);
        }
    }

    public function get(string $scheduleId): ?ScheduleDefinition
    {
        $raw = $this->redis->command('GET', [$this->keys->schedule($scheduleId)]);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return $this->decode($raw);
    }

    public function all(): array
    {
        $ids = $this->redis->command('SMEMBERS', [$this->keys->schedules()]);
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            if (is_string($id)) {
                $row = $this->get($id);
                if ($row !== null) {
                    $out[] = $row;
                }
            }
        }

        return $out;
    }

    public function due(\DateTimeImmutable $now, int $limit = 50): array
    {
        $ids = $this->redis->command('ZRANGEBYSCORE', [
            $this->keys->scheduleDue(),
            '-inf',
            (string) $now->getTimestamp(),
            'LIMIT',
            0,
            $limit,
        ]);
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            if (is_string($id)) {
                $row = $this->get($id);
                if ($row !== null) {
                    $out[] = $row;
                }
            }
        }

        return $out;
    }

    public function delete(string $scheduleId): void
    {
        $this->redis->command('DEL', [$this->keys->schedule($scheduleId)]);
        $this->redis->command('SREM', [$this->keys->schedules(), $scheduleId]);
        $this->redis->command('ZREM', [$this->keys->scheduleDue(), $scheduleId]);
    }

    public function claimOccurrence(
        string $occurrenceId,
        string $scheduleId,
        \DateTimeImmutable $intendedRunAt,
        string $ownerToken,
        \DateTimeImmutable $leaseExpiresAt,
    ): bool {
        unset($scheduleId, $intendedRunAt);
        $key = $this->keys->scheduleClaim($occurrenceId);
        $ttl = max(1, $leaseExpiresAt->getTimestamp() - $this->clock->now()->getTimestamp());
        $ok = $this->redis->command('SET', [$key, 'claimed:' . $ownerToken, 'NX', 'EX', $ttl]);
        if ($ok === true || $ok === 'OK') {
            return true;
        }
        $current = $this->redis->command('GET', [$key]);
        if (is_string($current) && str_starts_with($current, 'dispatched:')) {
            return false;
        }

        return false;
    }

    public function markDispatched(string $occurrenceId, string $ownerToken, string $jobId): bool
    {
        $key = $this->keys->scheduleClaim($occurrenceId);
        $current = $this->redis->command('GET', [$key]);
        if ($current !== 'claimed:' . $ownerToken) {
            return false;
        }
        $this->redis->command('SET', [$key, 'dispatched:' . $jobId, 'EX', 1209600]);

        return true;
    }

    public function getClaim(string $occurrenceId): ?ScheduleClaim
    {
        $raw = $this->redis->command('GET', [$this->keys->scheduleClaim($occurrenceId)]);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $dispatched = str_starts_with($raw, 'dispatched:');
        $token = $dispatched ? '' : substr($raw, strlen('claimed:'));
        $jobId = $dispatched ? substr($raw, strlen('dispatched:')) : null;

        return new ScheduleClaim(
            $occurrenceId,
            '',
            $this->clock->now(),
            $token,
            $dispatched ? 'dispatched' : 'claimed',
            $jobId !== '' ? $jobId : null,
            $this->clock->now()->add(new \DateInterval('PT1S')),
        );
    }

    public function heartbeat(string $schedulerId, array $row): void
    {
        $row['scheduler_id'] = $schedulerId;
        $row['last_heartbeat_at'] = Dates::toAtom($this->clock->now());
        $this->redis->command('HSET', [$this->keys->scheduler($schedulerId), 'json', json_encode($row, JSON_THROW_ON_ERROR)]);
        $this->redis->command('SADD', [$this->keys->schedulers(), $schedulerId]);
        $this->redis->command('EXPIRE', [$this->keys->scheduler($schedulerId), 120]);
    }

    public function schedulers(): array
    {
        $ids = $this->redis->command('SMEMBERS', [$this->keys->schedulers()]);
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            if (!is_string($id)) {
                continue;
            }
            $json = $this->redis->command('HGET', [$this->keys->scheduler($id), 'json']);
            if (is_string($json) && $json !== '') {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    $out[] = $decoded;
                }
            }
        }

        return $out;
    }

    private function decode(string $json): ?ScheduleDefinition
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }
        if (isset($data['job_schema_version']) && is_numeric($data['job_schema_version'])) {
            $data['job_schema_version'] = (int) $data['job_schema_version'];
        }
        foreach (['priority', 'network_id', 'site_id'] as $intKey) {
            if (isset($data[$intKey]) && is_numeric($data[$intKey])) {
                $data[$intKey] = (int) $data[$intKey];
            }
        }

        return ScheduleDefinition::fromArray($data);
    }
}
