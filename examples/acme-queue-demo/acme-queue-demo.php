<?php

/**
 * Plugin Name: Acme Queue Demo
 * Description: Minimal Fuzeo Queue consumer (example only).
 * Version: 1.0.0
 */

declare(strict_types=1);

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

require_once __DIR__ . '/src/ProcessOrder.php';
require_once __DIR__ . '/src/ProcessOrderHandler.php';

add_action('fuzeo_queue_ready', static function ($runtime): void {
    $origin = new Fuzeo\Queue\Jobs\Origin('acme/queue-demo', '1.0.0');
    $runtime->consumers()->register($origin);
    $runtime->jobs()->registerJob(
        Acme\QueueDemo\ProcessOrder::class,
        $origin,
        Acme\QueueDemo\ProcessOrderHandler::class
    );
});

add_action('init', static function (): void {
    if (!isset($_GET['acme_queue_demo']) || !current_user_can('manage_options')) {
        return;
    }
    if (!class_exists(Fuzeo\Queue\Queue::class)) {
        return;
    }
    Fuzeo\Queue\Queue::dispatch(new Acme\QueueDemo\ProcessOrder(123));
});
