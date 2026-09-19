<?php

declare(strict_types=1);

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Persistence\PdoConnection;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\CrashHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Worker\JobExecutor;
use Fuzeo\Queue\Worker\MappedSiteSwitcher;
use Fuzeo\Queue\Worker\WorkerIdentity;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$dsn = getenv('FUZEO_QUEUE_TEST_DSN');
if (!is_string($dsn) || $dsn === '') {
    fwrite(STDERR, "missing DSN\n");
    exit(1);
}
$user = getenv('FUZEO_QUEUE_TEST_DB_USER') ?: 'root';
$password = getenv('FUZEO_QUEUE_TEST_DB_PASS');
if ($password === false) {
    $password = 'root';
}

$connection = PdoConnection::fromDsn($dsn, $user, $password, 'wp_');
Coordinator::bootForTesting(['driver' => 'mysql'], connection: $connection);
Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), CrashHandler::class);

$runtime = Coordinator::get();
$worker = new WorkerLoop(
    $runtime->driver(),
    new JobExecutor($runtime->jobs()),
    new MappedSiteSwitcher([1 => true]),
    new WorkerOptions(sleepSeconds: 0, maxJobs: 1, leaseSeconds: 8, timeoutSeconds: 5),
    WorkerIdentity::generate(),
);
$worker->run();
echo 'processed=' . $worker->processed() . PHP_EOL;
