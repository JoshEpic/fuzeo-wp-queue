<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Deployment;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Persistence\Connection;
use Fuzeo\Queue\Persistence\Schema;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\SystemClock;

final class MysqlDeploymentStore implements DeploymentStore
{
    public const KEY_RESTART_GEN = 'deploy_restart_gen';

    public const KEY_RESTART_AT = 'deploy_restart_at';

    public const KEY_DRAIN_GEN = 'deploy_drain_gen';

    public const KEY_DRAIN_AT = 'deploy_drain_at';

    public const KEY_MAINT_OWNER = 'deploy_maint_owner';

    public const KEY_MAINT_EXP = 'deploy_maint_exp';

    public const KEY_MAINT_REASON = 'deploy_maint_reason';

    public const KEY_CURRENT_GEN = 'deploy_current_gen';

    public function __construct(
        private readonly Connection $connection,
        private readonly string $lockName = 'fuzeo_queue_deploy',
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function snapshot(): DeploymentState
    {
        $rows = $this->connection->select('SELECT `meta_key`, `meta_value` FROM ' . $this->table());
        $map = [];
        foreach ($rows as $row) {
            $key = (string) ($row['meta_key'] ?? '');
            if ($key !== '') {
                $map[$key] = (string) ($row['meta_value'] ?? '');
            }
        }

        return $this->fromMap($map);
    }

    public function requestRestart(string $generation, \DateTimeImmutable $at): void
    {
        $this->withLock(function () use ($generation, $at): void {
            $this->put(self::KEY_RESTART_GEN, $generation);
            $this->put(self::KEY_RESTART_AT, (string) $at->getTimestamp());
        });
    }

    public function requestDrain(string $generation, \DateTimeImmutable $at): void
    {
        $this->withLock(function () use ($generation, $at): void {
            $this->put(self::KEY_DRAIN_GEN, $generation);
            $this->put(self::KEY_DRAIN_AT, (string) $at->getTimestamp());
        });
    }

    public function cancelDrain(): void
    {
        $this->withLock(function (): void {
            $this->put(self::KEY_DRAIN_GEN, '');
            $this->put(self::KEY_DRAIN_AT, '');
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
            $this->put(self::KEY_MAINT_OWNER, $owner);
            $this->put(self::KEY_MAINT_EXP, (string) $expiresAt->getTimestamp());
            $this->put(self::KEY_MAINT_REASON, substr($reason, 0, 180));

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
            $this->put(self::KEY_MAINT_OWNER, '');
            $this->put(self::KEY_MAINT_EXP, '');
            $this->put(self::KEY_MAINT_REASON, '');

            return true;
        });
    }

    public function setCurrentGeneration(string $generation): void
    {
        $this->withLock(function () use ($generation): void {
            $this->put(self::KEY_CURRENT_GEN, $generation);
        });
    }

    public function withLock(callable $callback): mixed
    {
        $row = $this->connection->selectOne('SELECT GET_LOCK(?, ?) AS `locked`', [$this->lockName, 10]);
        if ((int) ($row['locked'] ?? 0) !== 1) {
            throw new DriverException('Could not acquire deployment lock.');
        }
        try {
            return $callback();
        } finally {
            $this->connection->selectOne('SELECT RELEASE_LOCK(?) AS `released`', [$this->lockName]);
        }
    }

    /**
     * @param array<string, string> $map
     */
    private function fromMap(array $map): DeploymentState
    {
        return new DeploymentState(
            restartGeneration: $map[self::KEY_RESTART_GEN] ?? '',
            restartRequestedAt: $this->time($map[self::KEY_RESTART_AT] ?? ''),
            drainGeneration: $map[self::KEY_DRAIN_GEN] ?? '',
            drainRequestedAt: $this->time($map[self::KEY_DRAIN_AT] ?? ''),
            maintenanceOwner: $map[self::KEY_MAINT_OWNER] ?? '',
            maintenanceExpiresAt: $this->time($map[self::KEY_MAINT_EXP] ?? ''),
            maintenanceReason: $map[self::KEY_MAINT_REASON] ?? '',
            currentGeneration: $map[self::KEY_CURRENT_GEN] ?? '',
        );
    }

    private function time(string $unix): ?\DateTimeImmutable
    {
        if ($unix === '' || !is_numeric($unix)) {
            return null;
        }

        return Dates::utc((new \DateTimeImmutable('@' . (int) $unix))->setTimezone(new \DateTimeZone('UTC')));
    }

    private function put(string $key, string $value): void
    {
        $this->connection->execute(
            'INSERT INTO ' . $this->table() . ' (`meta_key`, `meta_value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `meta_value` = VALUES(`meta_value`)',
            [$key, $value]
        );
    }

    private function table(): string
    {
        return Schema::quoteTable($this->connection->prefix(), Schema::META);
    }
}
