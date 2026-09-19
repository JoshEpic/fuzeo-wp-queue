<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

/**
 * Uses Action Scheduler public functions/classes when present.
 */
final class NativeActionSchedulerGateway implements ActionSchedulerGateway
{
    public function detected(): bool
    {
        return function_exists('as_enqueue_async_action')
            || function_exists('as_schedule_single_action')
            || class_exists('ActionScheduler');
    }

    public function version(): ?string
    {
        if (class_exists('ActionScheduler') && is_callable(['ActionScheduler', 'version'])) {
            $version = \ActionScheduler::version();

            return is_string($version) && $version !== '' ? $version : null;
        }
        if (defined('ACTION_SCHEDULER_VERSION')) {
            return (string) ACTION_SCHEDULER_VERSION;
        }

        return $this->detected() ? 'unknown' : null;
    }

    public function datastoreAvailable(): bool
    {
        if (!class_exists('ActionScheduler_Store')) {
            return function_exists('as_get_scheduled_actions');
        }
        try {
            \ActionScheduler_Store::instance();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public function enqueueAsync(string $hook, array $args, string $group): int|string
    {
        if (!function_exists('as_enqueue_async_action')) {
            throw new \Fuzeo\Queue\Exceptions\RuntimeUnavailableException('Action Scheduler as_enqueue_async_action is not available.');
        }

        return as_enqueue_async_action($hook, $args, $group);
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public function scheduleSingle(int $timestamp, string $hook, array $args, string $group): int|string
    {
        if (!function_exists('as_schedule_single_action')) {
            throw new \Fuzeo\Queue\Exceptions\RuntimeUnavailableException('Action Scheduler as_schedule_single_action is not available.');
        }

        return as_schedule_single_action($timestamp, $hook, $args, $group);
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public function scheduleRecurring(int $timestamp, int $intervalSeconds, string $hook, array $args, string $group): int|string
    {
        if (!function_exists('as_schedule_recurring_action')) {
            throw new \Fuzeo\Queue\Exceptions\RuntimeUnavailableException('Action Scheduler as_schedule_recurring_action is not available.');
        }

        return as_schedule_recurring_action($timestamp, $intervalSeconds, $hook, $args, $group);
    }

    public function unschedule(string $hook, ?array $args, string $group): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions($hook, $args ?? [], $group);

            return;
        }
        if (function_exists('as_unschedule_action')) {
            as_unschedule_action($hook, $args ?? [], $group);
        }
    }

    public function query(array $query, int $limit, int $offset): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $query['per_page'] = $limit;
        $query['offset'] = $offset;
        if (function_exists('as_get_scheduled_actions')) {
            $rows = as_get_scheduled_actions($query, 'ARRAY_A');
            if (!is_array($rows)) {
                return [];
            }
            $out = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }

            return $out;
        }

        return [];
    }

    public function count(array $query): int
    {
        if (class_exists('ActionScheduler_Store')) {
            try {
                $store = \ActionScheduler_Store::instance();
                $count = $store->query_actions($query, 'count');

                return is_numeric($count) ? (int) $count : 0;
            } catch (\Throwable) {
            }
        }
        $query['per_page'] = 1;
        if (function_exists('as_get_scheduled_actions')) {
            $rows = as_get_scheduled_actions($query, 'ids');

            return is_array($rows) ? count($rows) : 0;
        }

        return 0;
    }
}
