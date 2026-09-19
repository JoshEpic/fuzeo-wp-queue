<?php

declare(strict_types=1);

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\UniqueProductJob;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$host = getenv('FUZEO_QUEUE_TEST_REDIS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('FUZEO_QUEUE_TEST_REDIS_PORT') ?: '6379');
$database = (int) (getenv('FUZEO_QUEUE_TEST_REDIS_DB') ?: '15');
$namespace = getenv('FUZEO_QUEUE_TEST_REDIS_NS');
if (!is_string($namespace) || $namespace === '') {
    fwrite(STDERR, "missing NS\n");
    exit(1);
}

Coordinator::bootForTesting([
    'driver' => 'redis',
    'redis_dsn' => 'redis://' . $host . ':' . $port . '/' . $database,
    'redis_namespace' => $namespace,
]);
Queue::register(UniqueProductJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
$result = Queue::dispatchResult(new UniqueProductJob(1));
echo ($result->accepted ? 'accepted' : 'duplicate') . ' ' . $result->jobId() . PHP_EOL;
