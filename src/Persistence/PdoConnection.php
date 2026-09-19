<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Exceptions\SchemaException;
use PDO;
use PDOException;

final class PdoConnection implements Connection
{
    private bool $supportsSkipLocked = true;

    public function __construct(
        private PDO $pdo,
        private readonly string $prefix = 'wp_',
        private readonly ?string $dsn = null,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
    ) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $this->prefix)) {
            throw new SchemaException('Database table prefix is invalid.');
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->supportsSkipLocked = self::detectSkipLockedFromVersion($this->versionString());
        $this->applyReadCommittedIsolation();
    }

    public static function fromDsn(string $dsn, string $username, string $password, string $prefix = 'wp_'): self
    {
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        return new self($pdo, $prefix, $dsn, $username, $password);
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->run($sql, $bindings);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows === false ? [] : $rows;
    }

    /**
     * @param list<mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $rows = $this->select($sql, $bindings);

        return $rows[0] ?? null;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    public function begin(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->applyReadCommittedIsolation();
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function ping(): bool
    {
        try {
            $this->pdo->query('SELECT 1');

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    public function reconnect(): void
    {
        if ($this->dsn === null || $this->username === null) {
            throw new DriverException('This database connection cannot reconnect.');
        }
        if ($this->pdo->inTransaction()) {
            try {
                $this->pdo->rollBack();
            } catch (PDOException) {
            }
        }
        $this->pdo = new PDO($this->dsn, $this->username, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->supportsSkipLocked = self::detectSkipLockedFromVersion($this->versionString());
        $this->applyReadCommittedIsolation();
    }

    public function supportsSkipLocked(): bool
    {
        return $this->supportsSkipLocked;
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * @param list<mixed> $bindings
     */
    private function run(string $sql, array $bindings, bool $retried = false): \PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($this->stringify($bindings));

            return $statement;
        } catch (PDOException $exception) {
            if (!$retried && $this->isGoneAway($exception) && !$this->pdo->inTransaction()) {
                $this->reconnect();

                return $this->run($sql, $bindings, true);
            }

            throw new DriverException('Database query failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param list<mixed> $bindings
     * @return list<mixed>
     */
    private function stringify(array $bindings): array
    {
        return $bindings;
    }

    private function isGoneAway(PDOException $exception): bool
    {
        $code = (string) $exception->getCode();
        $message = strtolower($exception->getMessage());

        return in_array($code, ['2006', '2013', 'HY000'], true)
            || str_contains($message, 'gone away')
            || str_contains($message, 'lost connection');
    }

    public static function detectSkipLockedFromVersion(string $version): bool
    {
        if (preg_match('/(\d+\.\d+\.\d+)/', $version, $matches) !== 1) {
            return false;
        }
        if (stripos($version, 'mariadb') !== false) {
            return version_compare($matches[1], '10.6.0', '>=');
        }

        return version_compare($matches[1], '8.0.1', '>=');
    }

    private function versionString(): string
    {
        $statement = $this->pdo->query('SELECT VERSION()');
        if ($statement === false) {
            return '';
        }
        $version = $statement->fetchColumn();

        return is_string($version) ? $version : '';
    }

    /**
     * Session isolation, not SET TRANSACTION for the next statement.
     * PDO::beginTransaction() can emit START TRANSACTION in a way that
     * swallows next-transaction isolation, leaving REPEATABLE READ gap locks
     * on every pending row for a queue.
     */
    private function applyReadCommittedIsolation(): void
    {
        $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }
}
