<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Process;

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Operations\Operator;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\MySql\MysqlTestCase;
use Fuzeo\Queue\Tests\Support\FileRecordingHandler;
use Fuzeo\Queue\Tests\Support\RecordJob;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;

final class Phase9DeploymentTest extends MysqlTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testRestartUnderLoadDoesNotLoseJobs(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        Queue::register(RecordJob::class, new Origin('acme/shop', '1.0.0'), FileRecordingHandler::class);
        $path = sys_get_temp_dir() . '/fuzeo-queue-phase9-' . getmypid() . '.log';
        @unlink($path);
        $count = 12;
        for ($i = 0; $i < $count; $i++) {
            Queue::dispatch(new RecordJob($path, (string) $i));
        }
        $ops = Coordinator::get()->operations();
        $worker = WorkerLoop::fromManager(
            Coordinator::get(),
            new WorkerOptions(sleepSeconds: 0, maxJobs: 4, generationCheckInterval: 1)
        );
        $worker->run();
        $ops->requestRestart(Operator::cli());
        $worker = WorkerLoop::fromManager(
            Coordinator::get(),
            new WorkerOptions(sleepSeconds: 0, maxJobs: 20, generationCheckInterval: 1)
        );
        $worker->run();
        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        self::assertIsArray($lines);
        self::assertCount($count, $lines);
        @unlink($path);
    }

    public function testDrainTimeoutListsActiveWorkWithoutForceKill(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        $ops = Coordinator::get()->operations();
        $ops->requestDrain(Operator::cli());
        $progress = $ops->waitForDrain(1);
        self::assertTrue($progress['draining']);
        self::assertArrayHasKey('timed_out', $progress);
        self::assertArrayHasKey('active', $progress);
    }
}
