<?php

declare(strict_types=1);

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Persistence\PdoConnection;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ChildDatabase;
use Fuzeo\Queue\Tests\Support\FileRecordingHandler;
use Fuzeo\Queue\Tests\Support\RecordJob;
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
$maxJobs = (int) ($argv[1] ?? 1);

$connection = PdoConnection::fromDsn($dsn, $user, ChildDatabase::passwordFromEnvironment(), 'wp_');
Coordinator::bootForTesting(['driver' => 'mysql'], connection: $connection);
Queue::register(RecordJob::class, new Origin('acme/shop', '1.0.0'), FileRecordingHandler::class);

$runtime = Coordinator::get();
$worker = new WorkerLoop(
    $runtime->driver(),
    new JobExecutor($runtime->jobs()),
    new MappedSiteSwitcher([1 => true]),
    new WorkerOptions(sleepSeconds: 0, maxJobs: $maxJobs, leaseSeconds: 20, timeoutSeconds: 10),
    WorkerIdentity::generate(),
);
$worker->run();
echo 'processed=' . $worker->processed() . PHP_EOL;
