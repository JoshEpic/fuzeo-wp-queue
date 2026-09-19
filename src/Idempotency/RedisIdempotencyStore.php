<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Idempotency;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Redis\RedisClient;
use Fuzeo\Queue\Redis\RedisKeys;
use Fuzeo\Queue\Redis\RedisScripts;
use Fuzeo\Queue\Redis\ScriptCache;
use Fuzeo\Queue\Support\SystemClock;

final class RedisIdempotencyStore implements IdempotencyStore
{
    public function __construct(
        private readonly RedisClient $redis,
        private readonly RedisKeys $keys,
        private readonly ScriptCache $scripts,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function begin(IdempotencyIdentity $identity, string $ownerToken, int $leaseSeconds): IdempotencyBeginResult
    {
        $raw = $this->scripts->run(
            'idemp_begin',
            RedisScripts::IDEMPOTENCY_BEGIN,
            [$this->keys->idempotency($identity->hash), $this->keys->idempotencyOwner($ownerToken)],
            [$ownerToken, $identity->key, (string) $this->clock->now()->getTimestamp(), (string) max(1, $leaseSeconds)]
        );
        $owned = is_array($raw) ? (int) ($raw[0] ?? 0) : 0;
        $statusRaw = is_array($raw) ? (string) ($raw[1] ?? 'started') : 'started';
        $status = IdempotencyStatus::tryFrom($statusRaw) ?? IdempotencyStatus::Started;
        $resultJson = is_array($raw) ? (string) ($raw[2] ?? '') : '';
        $result = null;
        if ($resultJson !== '') {
            $decoded = json_decode($resultJson, true);
            $result = is_array($decoded) ? $decoded : null;
        }
        if ($owned === 1) {
            return new IdempotencyBeginResult(true, IdempotencyStatus::Started, $ownerToken, null);
        }

        return new IdempotencyBeginResult(false, $status, null, $result);
    }

    public function complete(string $ownerToken, array $result, int $retainSeconds): void
    {
        $key = $this->keyForOwner($ownerToken);
        if ($key === null) {
            throw new DriverException('Stale idempotency owner cannot complete this key.');
        }
        $now = $this->clock->now()->getTimestamp();
        $ok = $this->scripts->run(
            'idemp_complete',
            RedisScripts::IDEMPOTENCY_COMPLETE,
            [$key, $this->keys->idempotencyOwner($ownerToken)],
            [
                $ownerToken,
                json_encode($result, JSON_THROW_ON_ERROR),
                (string) $now,
                (string) max(1, $retainSeconds),
            ]
        );
        if ((int) $ok !== 1) {
            throw new DriverException('Stale idempotency owner cannot complete this key.');
        }
    }

    public function fail(string $ownerToken): void
    {
        $key = $this->keyForOwner($ownerToken);
        if ($key === null) {
            throw new DriverException('Stale idempotency owner cannot fail this key.');
        }
        $owner = $this->redis->command('HGET', [$key, 'owner']);
        $status = $this->redis->command('HGET', [$key, 'status']);
        if ($owner !== $ownerToken || $status !== IdempotencyStatus::Started->value) {
            throw new DriverException('Stale idempotency owner cannot fail this key.');
        }
        $this->redis->command('DEL', [$key, $this->keys->idempotencyOwner($ownerToken)]);
    }

    public function heartbeat(string $ownerToken, int $leaseSeconds): bool
    {
        $key = $this->keyForOwner($ownerToken);
        if ($key === null) {
            return false;
        }
        $owner = $this->redis->command('HGET', [$key, 'owner']);
        $status = $this->redis->command('HGET', [$key, 'status']);
        if ($owner !== $ownerToken || $status !== IdempotencyStatus::Started->value) {
            return false;
        }
        $ttl = max(1, $leaseSeconds);
        $this->redis->command('EXPIRE', [$key, $ttl]);
        $this->redis->command('EXPIRE', [$this->keys->idempotencyOwner($ownerToken), $ttl]);

        return true;
    }

    public function lookup(IdempotencyIdentity $identity): ?IdempotencyRecord
    {
        return $this->hydrate($this->keys->idempotency($identity->hash), $identity);
    }

    public function list(int $limit = 50): array
    {
        unset($limit);

        return [];
    }

    private function keyForOwner(string $ownerToken): ?string
    {
        $key = $this->redis->command('GET', [$this->keys->idempotencyOwner($ownerToken)]);

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function hydrate(string $key, IdempotencyIdentity $identity): ?IdempotencyRecord
    {
        $statusRaw = $this->redis->command('HGET', [$key, 'status']);
        if (!is_string($statusRaw) || $statusRaw === '') {
            return null;
        }
        $status = IdempotencyStatus::tryFrom($statusRaw);
        if ($status === null) {
            return null;
        }
        $owner = (string) $this->redis->command('HGET', [$key, 'owner']);
        $resultJson = (string) $this->redis->command('HGET', [$key, 'result']);
        $result = null;
        if ($resultJson !== '') {
            $decoded = json_decode($resultJson, true);
            $result = is_array($decoded) ? $decoded : null;
        }
        $started = (int) $this->redis->command('HGET', [$key, 'started_at']);
        $expires = (int) $this->redis->command('HGET', [$key, 'expires_at']);
        $completedRaw = $this->redis->command('HGET', [$key, 'completed_at']);
        $completed = is_numeric($completedRaw) ? (int) $completedRaw : null;

        return new IdempotencyRecord(
            $identity->hash,
            $identity->key,
            $status,
            $owner,
            (new \DateTimeImmutable('@' . max(0, $started)))->setTimezone(new \DateTimeZone('UTC')),
            $completed !== null ? (new \DateTimeImmutable('@' . $completed))->setTimezone(new \DateTimeZone('UTC')) : null,
            (new \DateTimeImmutable('@' . max(0, $expires)))->setTimezone(new \DateTimeZone('UTC')),
            $result,
        );
    }
}
