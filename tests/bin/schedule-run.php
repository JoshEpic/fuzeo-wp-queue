<?php

declare(strict_types=1);

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ChildDatabase;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$dsn = getenv('FUZEO_QUEUE_TEST_DSN');
if (!is_string($dsn) || $dsn === '') {
    fwrite(STDERR, "missing DSN\n");
    exit(1);
}
$user = getenv('FUZEO_QUEUE_TEST_DB_USER') ?: 'root';
$connection = Fuzeo\Queue\Persistence\PdoConnection::fromDsn($dsn, $user, ChildDatabase::passwordFromEnvironment(), 'wp_');
Coordinator::bootForTesting(['driver' => 'mysql'], connection: $connection);
Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
$n = Coordinator::get()->scheduler()->runDue();
echo 'dispatched=' . $n . PHP_EOL;
