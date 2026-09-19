<?php

/**
 * Plugin Name: Acme Interop Demo
 * Description: Runtime resolver, fallback, and explicit WP-Cron / Action Scheduler descriptors.
 */

declare(strict_types=1);

use Fuzeo\Queue\Interop\Interop;
use Fuzeo\Queue\Interop\RuntimePolicy;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\Origin;

add_action('fuzeo_queue_ready', static function ($runtime): void {
    $origin = new Origin('acme/interop-demo', '1.1.0');
    $runtime->consumers()->register($origin);
    $runtime->jobs()->registerJob(
        Acme\QueueDemo\ProcessOrder::class,
        $origin,
        Acme\QueueDemo\ProcessOrderHandler::class
    );
    Interop::cron(
        origin: $origin,
        hook: 'acme_hourly_sync',
        jobClass: Acme\QueueDemo\ProcessOrder::class,
        mapper: static fn (array $args): Job => new Acme\QueueDemo\ProcessOrder((int) ($args[0] ?? 0)),
        intervalSeconds: 3600,
        scheduleName: 'acme-hourly-sync',
    );
    Interop::actionScheduler(
        origin: $origin,
        hook: 'acme_process_order',
        jobClass: Acme\QueueDemo\ProcessOrder::class,
        mapper: static fn (array $args): Job => new Acme\QueueDemo\ProcessOrder((int) ($args['order_id'] ?? 0)),
        group: 'acme-shop',
        scheduleName: 'acme-process-order',
        intervalSeconds: 3600,
    );
});

add_action('init', static function (): void {
    if (!isset($_GET['acme_interop_dispatch']) || !current_user_can('manage_options')) {
        return;
    }
    $origin = new Origin('acme/interop-demo', '1.1.0');
    Interop::runtime($origin, RuntimePolicy::PreferQueue)->dispatch(new Acme\QueueDemo\ProcessOrder(99));
});
