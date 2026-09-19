<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Deployment;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Redis\RedisClient;
use Fuzeo\Queue\Redis\RedisKeys;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\SystemClock;
use Fuzeo\Queue\Support\Ulid;

final class RedisDeploymentStore implements DeploymentStore
{
    public function __construct(
        private readonly RedisClient $redis,
        private readonly RedisKeys $keys,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function snapshot(): DeploymentState
    {
        $raw = $this->redis->command('HGETALL', [$this->keys->deployment()]);
        $map = $this->pairs(is_array($raw) ? $raw : []);

        return new DeploymentState(
            restartGeneration: $map['restart_generation'] ?? '',
            restartRequestedAt: $this->time($map['restart_requested_at'] ?? ''),
            drainGeneration: $map['drain_generation'] ?? '',
            drainRequestedAt: $this->time($map['drain_requested_at'] ?? ''),
            maintenanceOwner: $map['maintenance_owner'] ?? '',
            maintenanceExpiresAt: $this->time($map['maintenance_expires_at'] ?? ''),
            maintenanceReason: $map['maintenance_reason'] ?? '',
            currentGeneration: $map['current_generation'] ?? '',
        );
    }

    public function requestRestart(string $generation, \DateTimeImmutable $at): void
    {
        $this->withLock(function () use ($generation, $at): void {
            $this->redis->command('HSET', [
                $this->keys->deployment(),
                'restart_generation',
                $generation,
                'restart_requested_at',
                (string) $at->getTimestamp(),
            ]);
        });
    }

    public function requestDrain(string $generation, \DateTimeImmutable $at): void
    {
        $this->withLock(function () use ($generation, $at): void {
            $this->redis->command('HSET', [
                $this->keys->deployment(),
                'drain_generation',
                $generation,
                'drain_requested_at',
                (string) $at->getTimestamp(),
            ]);
        });
    }

    public function cancelDrain(): void
    {
        $this->withLock(function (): void {
            $this->redis->command('HSET', [
                $this->keys->deployment(),
                'drain_generation',
                '',
                'drain_requested_at',
                '',
            ]);
        });
    }

    public function enterMaintenance(string $owner, \DateTimeImmutable $expiresAt, string $reason): bool
    {
        return $this->withLock(function () use ($owner, $expiresAt, $reason): bool {
            $state = $this->snapshot();
            $now = $this->clock->now();
            if ($state->isMaintenanceActive($now) && $state->maintenanceOwner !== $owner) {
                return false;
            }
            $this->redis->command('HSET', [
                $this->keys->deployment(),
                'maintenance_owner',
                $owner,
                'maintenance_expires_at',
                (string) $expiresAt->getTimestamp(),
                'maintenance_reason',
                substr($reason, 0, 180),
            ]);

            return true;
        });
    }

    public function releaseMaintenance(string $owner): bool
    {
        return $this->withLock(function () use ($owner): bool {
            $state = $this->snapshot();
            if ($state->maintenanceOwner !== $owner) {
                return false;
            }
            $this->redis->command('HSET', [
                $this->keys->deployment(),
                'maintenance_owner',
                '',
                'maintenance_expires_at',
                '',
                'maintenance_reason',
                '',
            ]);

            return true;
        });
    }

    public function setCurrentGeneration(string $generation): void
    {
        $this->withLock(function () use ($generation): void {
            $this->redis->command('HSET', [$this->keys->deployment(), 'current_generation', $generation]);
        });
    }

    public function withLock(callable $callback): mixed
    {
        $token = Ulid::generate();
        $key = $this->keys->lock('deploy');
        $ok = $this->redis->command('SET', [$key, $token, 'NX', 'EX', 15]);
        if ($ok !== true && $ok !== 'OK') {
            throw new DriverException('Could not acquire deployment lock.');
        }
        try {
            return $callback();
        } finally {
            $held = $this->redis->command('GET', [$key]);
            if ($held === $token) {
                $this->redis->command('DEL', [$key]);
            }
        }
    }

    /**
     * @param array<mixed> $row
     * @return array<string, string>
     */
    private function pairs(array $row): array
    {
        $out = [];
        if (array_is_list($row)) {
            for ($i = 0; $i + 1 < count($row); $i += 2) {
                $key = $row[$i];
                $value = $row[$i + 1];
                if (is_string($key) && (is_string($value) || is_numeric($value))) {
                    $out[$key] = (string) $value;
                }
            }

            return $out;
        }
        foreach ($row as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    private function time(string $unix): ?\DateTimeImmutable
    {
        if ($unix === '' || !is_numeric($unix)) {
            return null;
        }

        return Dates::utc((new \DateTimeImmutable('@' . (int) $unix))->setTimezone(new \DateTimeZone('UTC')));
    }
}
