<?php

/**
 * Plugin Name: FuzeoWP Bridge-like (example)
 * Description: Queue-first / Action Scheduler-fallback consumer pattern. Not Fuzeo Bridge.
 */

declare(strict_types=1);

use Fuzeo\Queue\Interop\Interop;
use Fuzeo\Queue\Interop\RuntimePolicy;
use Fuzeo\Queue\Jobs\Origin;

add_action('fuzeo_queue_ready', static function ($runtime): void {
    $origin = new Origin('example/bridge-like', '1.1.0');
    $runtime->consumers()->register($origin);
    $runtime->jobs()->registerJob(
        Acme\QueueDemo\ProcessOrder::class,
        $origin,
        Acme\QueueDemo\ProcessOrderHandler::class
    );
});

add_action('init', static function (): void {
    if (!isset($_GET['bridge_like_dispatch']) || !current_user_can('manage_options')) {
        return;
    }
    if (!class_exists(Interop::class)) {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('example_bridge_like_order', ['order_id' => 1], 'fuzeo-bridge');
        }
        return;
    }
    $origin = new Origin('example/bridge-like', '1.1.0');
    Interop::runtime($origin, RuntimePolicy::PreferQueue)->dispatch(new Acme\QueueDemo\ProcessOrder(1));
});
