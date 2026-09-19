<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

/**
 * In-memory Action Scheduler for tests. No WordPress or WooCommerce required.
 */
final class FakeActionSchedulerGateway implements ActionSchedulerGateway
{
    /** @var array<int, array<string, mixed>> */
    private array $actions = [];

    private int $nextId = 1;

    public bool $detected = true;

    public ?string $version = '3.7.0';

    public bool $datastoreAvailable = true;

    public function detected(): bool
    {
        return $this->detected;
    }

    public function version(): ?string
    {
        return $this->detected ? $this->version : null;
    }

    public function datastoreAvailable(): bool
    {
        return $this->detected && $this->datastoreAvailable;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public function enqueueAsync(string $hook, array $args, string $group): int|string
    {
        return $this->push($hook, $args, $group, 'pending', time(), null);
    }

    public function scheduleSingle(int $timestamp, string $hook, array $args, string $group): int|string
    {
        return $this->push($hook, $args, $group, 'pending', $timestamp, null);
    }

    public function scheduleRecurring(int $timestamp, int $intervalSeconds, string $hook, array $args, string $group): int|string
    {
        return $this->push($hook, $args, $group, 'pending', $timestamp, $intervalSeconds);
    }

    public function unschedule(string $hook, ?array $args, string $group): void
    {
        foreach ($this->actions as $id => $row) {
            if (($row['hook'] ?? '') !== $hook) {
                continue;
            }
            if ($group !== '' && ($row['group'] ?? '') !== $group) {
                continue;
            }
            if ($args !== null && ($row['args'] ?? []) !== $args) {
                continue;
            }
            unset($this->actions[$id]);
        }
    }

    public function query(array $query, int $limit, int $offset): array
    {
        $rows = $this->filter($query);
        $rows = array_slice($rows, max(0, $offset), max(1, min(200, $limit)));

        return array_values($rows);
    }

    public function count(array $query): int
    {
        return count($this->filter($query));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_values($this->actions);
    }

    public function markInProgress(int $id): void
    {
        if (isset($this->actions[$id])) {
            $this->actions[$id]['status'] = 'in-progress';
        }
    }

    public function markFailed(int $id): void
    {
        if (isset($this->actions[$id])) {
            $this->actions[$id]['status'] = 'failed';
        }
    }

    public function drain(int $id): void
    {
        unset($this->actions[$id]);
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function push(string $hook, array $args, string $group, string $status, int $timestamp, ?int $interval): int
    {
        $id = $this->nextId++;
        $this->actions[$id] = [
            'action_id' => $id,
            'hook' => $hook,
            'args' => $args,
            'group' => $group,
            'status' => $status,
            'scheduled_date_gmt' => gmdate('Y-m-d H:i:s', $timestamp),
            'schedule' => $interval,
        ];

        return $id;
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    private function filter(array $query): array
    {
        $out = [];
        foreach ($this->actions as $row) {
            if (isset($query['hook']) && ($row['hook'] ?? '') !== $query['hook']) {
                continue;
            }
            if (isset($query['group']) && ($row['group'] ?? '') !== $query['group']) {
                continue;
            }
            if (isset($query['status']) && ($row['status'] ?? '') !== $query['status']) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }
}
