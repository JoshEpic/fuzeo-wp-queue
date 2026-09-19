<?php

declare(strict_types=1);

use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ChildDatabase;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$dsn = getenv('FUZEO_QUEUE_TEST_DSN');
if (!is_string($dsn) || $dsn === '') {
    fwrite(STDERR, "missing DSN\n");
    exit(1);
}
$user = getenv('FUZEO_QUEUE_TEST_DB_USER') ?: 'root';
$connection = Fuzeo\Queue\Persistence\PdoConnection::fromDsn($dsn, $user, ChildDatabase::passwordFromEnvironment(), 'wp_');
Coordinator::bootForTesting(['driver' => 'mysql'], connection: $connection);
$api = Queue::idempotency();
$result = $api->begin('race-key');
echo ($result->owned ? 'owned' : 'denied') . PHP_EOL;
