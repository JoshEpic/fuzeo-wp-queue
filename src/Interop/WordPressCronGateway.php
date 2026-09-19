<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

/**
 * Isolates the WordPress `_get_cron_array()` internal behind this adapter.
 */
final class WordPressCronGateway implements CronGateway
{
    public function eventsPresent(): bool
    {
        return function_exists('_get_cron_array') || function_exists('wp_next_scheduled');
    }

    public function automaticSpawningDisabled(): bool
    {
        return defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    }

    public function cronArray(): array
    {
        if (!function_exists('_get_cron_array')) {
            return [];
        }
        $cron = _get_cron_array();
        if (!is_array($cron)) {
            return [];
        }
        /** @var array<int, array<string, array<string, array<string, mixed>>>> $cron */
        return $cron;
    }

    public function schedules(): array
    {
        if (!function_exists('wp_get_schedules')) {
            return [];
        }
        $schedules = wp_get_schedules();

        return is_array($schedules) ? $schedules : [];
    }

    public function unschedule(int $timestamp, string $hook, array $args): bool
    {
        if (function_exists('wp_unschedule_event')) {
            return (bool) wp_unschedule_event($timestamp, $hook, $args);
        }

        return false;
    }

    public function scheduleSingle(int $timestamp, string $hook, array $args): bool
    {
        if (function_exists('wp_schedule_single_event')) {
            return (bool) wp_schedule_single_event($timestamp, $hook, $args);
        }

        return false;
    }

    public function scheduleRecurring(int $timestamp, string $recurrence, string $hook, array $args): bool
    {
        if (function_exists('wp_schedule_event')) {
            return (bool) wp_schedule_event($timestamp, $recurrence, $hook, $args);
        }

        return false;
    }
}
