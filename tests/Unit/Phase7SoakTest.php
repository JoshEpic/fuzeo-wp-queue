<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Runtime\MemoryMonitor;
use Fuzeo\Queue\Runtime\ProcessLifecycle;
use Fuzeo\Queue\Runtime\RecycleReason;
use Fuzeo\Queue\Runtime\RuntimeGeneration;
use Fuzeo\Queue\Runtime\FakeWordPressRuntime;
use Fuzeo\Queue\Tests\Support\MemoryLeakHandler;
use Fuzeo\Queue\Tests\Support\MemoryLeakJob;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Worker\JobExecutor;
use Fuzeo\Queue\Worker\MappedSiteSwitcher;
use Fuzeo\Queue\Worker\WorkerIdentity;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;
use PHPUnit\Framework\TestCase;

final class Phase7SoakTest extends TestCase
{
    protected function setUp(): void
    {
        Coordinator::bootForTesting();
        ProcessOrderHandler::reset();
        MemoryLeakHandler::$leak = [];
    }

    protected function tearDown(): void
    {
        Coordinator::reset();
        MemoryLeakHandler::$leak = [];
    }

    public function testThousandLightweightJobsComplete(): void
    {
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        for ($i = 1; $i <= 1000; $i++) {
            Queue::dispatch(new ProcessOrderJob($i));
        }
        $worker = new WorkerLoop(
            Coordinator::get()->driver(),
            new JobExecutor(Coordinator::get()->jobs()),
            new MappedSiteSwitcher([1 => true]),
            new WorkerOptions(sleepSeconds: 0, maxJobs: 1000, memoryBytes: 1024 * 1024 * 1024, generationCheckInterval: 0, gcInterval: 1000),
            WorkerIdentity::generate(),
        );
        $started = hrtime(true);
        $worker->run();
        $elapsedMs = (hrtime(true) - $started) / 1e6;
        self::assertSame(1000, $worker->processed());
        self::assertSame(1000, ProcessOrderHandler::$handled);
        self::assertLessThan(30000, $elapsedMs);
    }

    public function testMemoryThresholdRecyclesAndReplacementCompletesQueue(): void
    {
        Queue::register(MemoryLeakJob::class, new Origin('acme/shop', '1.0.0'), MemoryLeakHandler::class);
        for ($i = 0; $i < 8; $i++) {
            Queue::dispatch(new MemoryLeakJob());
        }
        $usage = 0;
        $monitor = new MemoryMonitor(0, static function () use (&$usage): int {
            return $usage;
        });
        $wp = new FakeWordPressRuntime();
        $options = new WorkerOptions(sleepSeconds: 0, maxJobs: 0, memoryBytes: 4);
        $lifecycle = new ProcessLifecycle(
            $options,
            new RuntimeGeneration($wp),
            $monitor,
            bootGeneration: 'a',
        );
        $worker = new WorkerLoop(
            Coordinator::get()->driver(),
            new JobExecutor(Coordinator::get()->jobs()),
            new MappedSiteSwitcher([1 => true]),
            $options,
            WorkerIdentity::generate(),
            lifecycle: $lifecycle,
        );
        $usage = 10;
        $worker->run();
        self::assertSame(RecycleReason::Memory, $worker->recycleReason());

        $replacement = new WorkerLoop(
            Coordinator::get()->driver(),
            new JobExecutor(Coordinator::get()->jobs()),
            new MappedSiteSwitcher([1 => true]),
            new WorkerOptions(sleepSeconds: 0, maxJobs: 20, memoryBytes: 1024 * 1024 * 1024),
            WorkerIdentity::generate(),
        );
        $replacement->run();
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(\Fuzeo\Queue\Drivers\Memory\MemoryDriver::class, $driver);
        $pending = 0;
        foreach ($driver->all() as $job) {
            if ($job->state->value === 'pending') {
                $pending++;
            }
        }
        self::assertSame(0, $pending);
    }
}
