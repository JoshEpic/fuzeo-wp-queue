<?php

declare(strict_types=1);

use Fuzeo\Queue\Runtime\Coordinator;

require __DIR__ . '/Support/WordPressStubs.php';
require dirname(__DIR__) . '/vendor/autoload.php';

fuzeo_queue_register_plugins_loaded_listener();
Coordinator::reset();
