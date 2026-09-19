<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Operations;

use Fuzeo\Queue\Persistence\Connection;
use Fuzeo\Queue\Persistence\Schema;
use Fuzeo\Queue\Support\Dates;

final class MysqlAuditStore implements AuditStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function record(AuditEvent $event): void
    {
        $this->connection->execute(
            'INSERT INTO ' . $this->table() . ' (
                `occurred_at`, `category`, `action`, `user_id`, `resource_type`, `resource_id`,
                `network_id`, `site_id`, `details`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                Dates::toDatabase($event->occurredAt),
                $event->category,
                $event->action,
                $event->userId,
                $event->resourceType,
                $event->resourceId,
                $event->networkId,
                $event->siteId,
                json_encode($event->details, JSON_THROW_ON_ERROR),
            ]
        );
    }

    public function recent(int $limit = 50, string $category = ''): array
    {
        $limit = max(1, min(200, $limit));
        if ($category !== '') {
            $rows = $this->connection->select(
                'SELECT * FROM ' . $this->table() . ' WHERE `category` = ? ORDER BY `id` DESC LIMIT ' . $limit,
                [$category]
            );
        } else {
            $rows = $this->connection->select(
                'SELECT * FROM ' . $this->table() . ' ORDER BY `id` DESC LIMIT ' . $limit
            );
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    public function prune(\DateTimeImmutable $before, int $batchSize = 500): int
    {
        return $this->connection->execute(
            'DELETE FROM ' . $this->table() . ' WHERE `occurred_at` < ? LIMIT ' . max(1, min(5000, $batchSize)),
            [Dates::toDatabase($before)]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AuditEvent
    {
        $details = [];
        $raw = $row['details'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $details = is_array($decoded) ? $decoded : [];
        }
        /** @var array<string, scalar|null> $details */
        return new AuditEvent(
            Dates::fromDatabase((string) ($row['occurred_at'] ?? 'now')),
            (string) ($row['category'] ?? 'event'),
            (string) ($row['action'] ?? ''),
            (int) ($row['user_id'] ?? 0),
            (string) ($row['resource_type'] ?? ''),
            (string) ($row['resource_id'] ?? ''),
            (int) ($row['network_id'] ?? 1),
            (int) ($row['site_id'] ?? 0),
            $details,
        );
    }

    private function table(): string
    {
        return Schema::quoteTable($this->connection->prefix(), Schema::AUDIT);
    }
}
