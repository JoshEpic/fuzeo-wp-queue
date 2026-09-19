<?php

declare(strict_types=1);

use Fuzeo\Queue\Drivers\Redis\RedisDriver;
use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Redis\PhpRedisConnection;
use Fuzeo\Queue\Redis\RedisSettings;
use Fuzeo\Queue\Runtime\Coordinator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$host = getenv('FUZEO_QUEUE_TEST_REDIS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('FUZEO_QUEUE_TEST_REDIS_PORT') ?: '6379');
$database = (int) (getenv('FUZEO_QUEUE_TEST_REDIS_DB') ?: '15');
$namespace = getenv('FUZEO_QUEUE_TEST_REDIS_NAMESPACE');
if (!is_string($namespace) || $namespace === '') {
    fwrite(STDERR, "missing namespace\n");
    exit(1);
}
$worker = $argv[1] ?? 'worker';
$sleep = (int) ($argv[2] ?? 0);

$settings = new RedisSettings(host: $host, port: $port, database: $database, namespace: $namespace);
$driver = new RedisDriver(new PhpRedisConnection($settings), $settings);
Coordinator::bootForTesting(['driver' => 'redis'], $driver);
if (!$driver instanceof RedisDriver) {
    fwrite(STDERR, "expected redis driver\n");
    exit(1);
}
$reserved = $driver->reserve(new ReserveRequest('default', $worker, 15));
if ($reserved === null) {
    echo "none\n";
    exit(0);
}
echo $reserved->envelope->jobId . ' ' . $reserved->token->value . PHP_EOL;
if ($sleep > 0) {
    sleep($sleep);
}
