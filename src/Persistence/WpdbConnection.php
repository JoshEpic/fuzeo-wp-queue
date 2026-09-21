<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Exceptions\SchemaException;

/**
 * WordPress $wpdb adapter. Uses the network/base prefix so queue tables are shared.
 */
final class WpdbConnection implements Connection
{
    /**
     * @param \wpdb $wpdb
     */
    public function __construct(private \wpdb $wpdb)
    {
        if (!isset($this->wpdb->base_prefix) && !isset($this->wpdb->prefix)) {
            throw new SchemaException('WordPress database handle is missing a table prefix.');
        }
    }

    public static function fromGlobals(): self
    {
        $wpdb = $GLOBALS['wpdb'];
        if (!$wpdb instanceof \wpdb) {
            throw new DriverException('WordPress database ($wpdb) is not available.');
        }

        return new self($wpdb);
    }

    public function prefix(): string
    {
        $prefix = $this->wpdb->base_prefix ?? $this->wpdb->prefix ?? 'wp_';
        if (!is_string($prefix) || !preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
            throw new SchemaException('WordPress table prefix is invalid.');
        }

        return $prefix;
    }

    public function select(string $sql, array $bindings = []): array
    {
        $prepared = $this->prepare($sql, $bindings);
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);
        if (!is_array($rows)) {
            $this->throwLastError();
        }

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * @param list<mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $prepared = $this->prepare($sql, $bindings);
        $row = $this->wpdb->get_row($prepared, ARRAY_A);
        if ($row === null) {
            return null;
        }
        if (!is_array($row)) {
            $this->throwLastError();
        }

        return $row;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $prepared = $this->prepare($sql, $bindings);
        $result = $this->wpdb->query($prepared);
        if ($result === false) {
            $this->throwLastError();
        }

        return is_int($result) ? $result : 0;
    }

    public function begin(): void
    {
        $this->wpdb->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->wpdb->query('START TRANSACTION');
    }

    public function commit(): void
    {
        $this->wpdb->query('COMMIT');
    }

    public function rollBack(): void
    {
        $this->wpdb->query('ROLLBACK');
    }

    public function ping(): bool
    {
        $result = $this->wpdb->query('SELECT 1');

        return $result !== false;
    }

    public function reconnect(): void
    {
        if (method_exists($this->wpdb, 'check_connection')) {
            $this->wpdb->check_connection(false);
        }
        if (method_exists($this->wpdb, 'db_connect')) {
            $this->wpdb->db_connect(false);
        }
    }

    public function supportsSkipLocked(): bool
    {
        $version = method_exists($this->wpdb, 'get_var') ? $this->wpdb->get_var('SELECT VERSION()') : null;

        return is_string($version) && PdoConnection::detectSkipLockedFromVersion($version);
    }

    /**
     * @param list<mixed> $bindings
     */
    private function prepare(string $sql, array $bindings): string
    {
        if ($bindings === []) {
            return $sql;
        }
        if (!method_exists($this->wpdb, 'prepare')) {
            throw new DriverException('wpdb::prepare is unavailable.');
        }
        $placeholders = [];
        $prepareArgs = [];
        foreach ($bindings as $binding) {
            if ($binding === null) {
                $placeholders[] = 'NULL';
                continue;
            }
            if (is_int($binding)) {
                $placeholders[] = '%d';
            } else {
                $placeholders[] = '%s';
            }
            $prepareArgs[] = $binding;
        }
        $withPlaceholders = $this->replaceQuestionMarks($sql, $placeholders);
        if ($prepareArgs === []) {
            return $withPlaceholders;
        }
        $prepared = $this->wpdb->prepare($withPlaceholders, ...$prepareArgs);
        if (!is_string($prepared)) {
            throw new DriverException('Failed to prepare SQL statement.');
        }

        return $prepared;
    }

    /**
     * @param list<string> $placeholders
     */
    private function replaceQuestionMarks(string $sql, array $placeholders): string
    {
        $index = 0;

        return (string) preg_replace_callback('/\?/', static function () use (&$index, $placeholders): string {
            $token = $placeholders[$index] ?? '%s';
            $index++;

            return $token;
        }, $sql);
    }

    private function throwLastError(): never
    {
        $error = $this->wpdb->last_error ?? 'unknown database error';
        if (!is_string($error) || $error === '') {
            $error = 'unknown database error';
        }
        $message = strtolower($error);
        if (str_contains($message, 'gone away') || str_contains($message, 'lost connection')) {
            throw new DriverException('Database connection lost: ' . $error);
        }

        throw new DriverException('WordPress database query failed: ' . $error);
    }
}
