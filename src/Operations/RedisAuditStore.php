<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Operations;

use Fuzeo\Queue\Redis\RedisClient;
use Fuzeo\Queue\Redis\RedisKeys;
use Fuzeo\Queue\Support\Dates;

final class RedisAuditStore implements AuditStore
{
    public function __construct(
        private readonly RedisClient $redis,
        private readonly RedisKeys $keys,
        private readonly int $maxEvents = 2000,
        private readonly int $ttlSeconds = 2592000,
    ) {
    }

    public function record(AuditEvent $event): void
    {
        $payload = json_encode([
            'occurred_at' => Dates::toAtom($event->occurredAt),
            'category' => $event->category,
            'action' => $event->action,
            'user_id' => $event->userId,
            'resource_type' => $event->resourceType,
            'resource_id' => $event->resourceId,
            'network_id' => $event->networkId,
            'site_id' => $event->siteId,
            'details' => $event->details,
        ], JSON_THROW_ON_ERROR);
        $key = $this->keys->audit();
        $this->redis->command('LPUSH', [$key, $payload]);
        $this->redis->command('LTRIM', [$key, '0', (string) ($this->maxEvents - 1)]);
        $this->redis->command('EXPIRE', [$key, (string) $this->ttlSeconds]);
    }

    public function recent(int $limit = 50, string $category = ''): array
    {
        $limit = max(1, min(200, $limit));
        $rows = $this->redis->command('LRANGE', [$this->keys->audit(), '0', (string) ($limit * 3)]);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_string($row)) {
                continue;
            }
            $decoded = json_decode($row, true);
            if (!is_array($decoded)) {
                continue;
            }
            $event = $this->fromArray($decoded);
            if ($category !== '' && $event->category !== $category) {
                continue;
            }
            $out[] = $event;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function prune(\DateTimeImmutable $before, int $batchSize = 500): int
    {
        unset($before, $batchSize);

        return 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fromArray(array $data): AuditEvent
    {
        $details = $data['details'] ?? [];
        if (!is_array($details)) {
            $details = [];
        }
        /** @var array<string, scalar|null> $details */
        return new AuditEvent(
            Dates::fromAtom((string) ($data['occurred_at'] ?? 'now')),
            (string) ($data['category'] ?? 'event'),
            (string) ($data['action'] ?? ''),
            (int) ($data['user_id'] ?? 0),
            (string) ($data['resource_type'] ?? ''),
            (string) ($data['resource_id'] ?? ''),
            (int) ($data['network_id'] ?? 1),
            (int) ($data['site_id'] ?? 0),
            $details,
        );
    }
}
