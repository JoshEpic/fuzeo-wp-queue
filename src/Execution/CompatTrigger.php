<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Execution;

use Fuzeo\Queue\Core\QueueManager;
use Fuzeo\Queue\Runtime\Coordinator;

/**
 * Network-owned WP-Cron trigger. One event for the Queue runtime, never one event per job.
 */
final class CompatTrigger
{
    public const HOOK = 'fuzeo_queue_compat_tick';
    public const SCHEDULE = 'fuzeo_queue_compat_interval';

    public static function register(): void
    {
        if (!function_exists('add_action') || !function_exists('add_filter')) {
            return;
        }
        add_filter('cron_schedules', [self::class, 'schedules']);
        add_action(self::HOOK, [self::class, 'handle']);
        add_action('init', [self::class, 'bootReconcile'], 20);
    }

    /**
     * @param array<string, mixed> $schedules
     * @return array<string, mixed>
     */
    public static function schedules(array $schedules): array
    {
        if (!isset($schedules[self::SCHEDULE])) {
            $schedules[self::SCHEDULE] = [
                'interval' => 60,
                'display' => 'Fuzeo Queue compatibility tick (60 seconds)',
            ];
        }

        return $schedules;
    }

    public static function handle(): void
    {
        if (!Coordinator::isBooted()) {
            return;
        }
        Coordinator::get()->execution()->tick();
    }

    public static function bootReconcile(): void
    {
        if (!Coordinator::isBooted()) {
            return;
        }
        $runtime = Coordinator::get()->execution();
        self::reconcile(Coordinator::get(), $runtime->state());
    }

    public static function reconcile(QueueManager $manager, CompatState $state): void
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }
        $enabled = $manager->execution()->isEnabled();
        if (!$enabled) {
            self::unschedule();
            self::markConfigured($manager, false);

            return;
        }
        if (function_exists('is_multisite') && is_multisite() && function_exists('get_current_blog_id') && function_exists('get_main_site_id')) {
            $main = (int) get_main_site_id();
            if ((int) get_current_blog_id() !== $main && $main > 0) {
                return;
            }
        }
        if (wp_next_scheduled(self::HOOK) === false) {
            wp_schedule_event(time() + 60, self::SCHEDULE, self::HOOK);
        }
        self::markConfigured($manager, true);
    }

    public static function unschedule(): void
    {
        if (!function_exists('wp_clear_scheduled_hook')) {
            return;
        }
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function isInternalHook(string $hook): bool
    {
        return $hook === self::HOOK;
    }

    private static function markConfigured(QueueManager $manager, bool $configured): void
    {
        $store = $manager->execution()->store();
        $current = $store->load();
        if ($current->wpCronConfigured === $configured) {
            return;
        }
        $store->save(new CompatState(
            enabled: $current->enabled,
            lastTickAt: $current->lastTickAt,
            lastSuccessAt: $current->lastSuccessAt,
            lastJobsProcessed: $current->lastJobsProcessed,
            lastOutcome: $current->lastOutcome,
            lastCronWorkerAt: $current->lastCronWorkerAt,
            lastBlockedReason: $current->lastBlockedReason,
            lastBlockedJobType: $current->lastBlockedJobType,
            lastBlockedQueue: $current->lastBlockedQueue,
            wpCronConfigured: $configured,
            enabledExplicit: $current->enabledExplicit,
        ));
    }
}
