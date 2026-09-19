<?php

declare(strict_types=1);

use Fuzeo\Queue\Runtime\Coordinator;

if (getenv('FUZEO_QUEUE_DISABLE_ALARMS') === false) {
    putenv('FUZEO_QUEUE_DISABLE_ALARMS=1');
}

$wpTests = getenv('WP_TESTS_DIR');
if (is_string($wpTests) && $wpTests !== '' && is_file($wpTests . '/includes/functions.php')) {
    require dirname(__DIR__) . '/vendor/autoload.php';
    require __DIR__ . '/Support/Phase7Jobs.php';
    require $wpTests . '/includes/functions.php';
    tests_add_filter('muplugins_loaded', static function (): void {
        // Composer autoload already registered Fuzeo Queue. WordPress will fire plugins_loaded.
    });
    require $wpTests . '/includes/bootstrap.php';
    Coordinator::reset();

    return;
}

require __DIR__ . '/Support/WordPressStubs.php';
require __DIR__ . '/Support/WpdbStub.php';
require __DIR__ . '/Support/RedisStubs.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Support/Phase7Jobs.php';

fuzeo_queue_register_plugins_loaded_listener();
Coordinator::reset();
