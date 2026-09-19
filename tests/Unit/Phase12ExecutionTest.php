<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Execution\ExecutionClass;
use Fuzeo\Queue\Execution\ExecutionMode;
use Fuzeo\Queue\Execution\ExecutionRuntime;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\FailingOrderHandler;
use Fuzeo\Queue\Tests\Support\ImportCatalogJob;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;
use PHPUnit\Framework\TestCase;

final class Phase12ExecutionTest extends TestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        ProcessOrderHandler::reset();
        parent::tearDown();
    }

    public function testDefaultJobsAreStandardClass(): void
    {
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $envelope = Queue::dispatch(new ProcessOrderJob(1));
        self::assertSame(ExecutionClass::Standard, $envelope->executionClass());
    }

    public function testPersistentMarkerIsStored(): void
    {
        Coordinator::bootForTesting();
        Queue::register(ImportCatalogJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $envelope = Queue::dispatch(new ImportCatalogJob(9));
        self::assertSame(ExecutionClass::Persistent, $envelope->executionClass());
    }

    public function testDispatchOptionRequiresPersistentWorker(): void
    {
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $envelope = Queue::on('default')->requiresPersistentWorker()->dispatch(new ProcessOrderJob(2));
        self::assertSame(ExecutionClass::Persistent, $envelope->executionClass());
    }

    public function testPersistentQueueConfigClassifiesJobs(): void
    {
        Coordinator::bootForTesting(['persistent_queues' => ['imports']]);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $envelope = Queue::on('imports')->dispatch(new ProcessOrderJob(3));
        self::assertSame(ExecutionClass::Persistent, $envelope->executionClass());
    }

    public function testCompatTickProcessesEligibleJobsAndSkipsPersistent(): void
    {
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::register(ImportCatalogJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ImportCatalogJob(1));
        Queue::dispatch(new ProcessOrderJob(2));
        $result = Queue::execution()->tick(true, true);
        self::assertSame('ok', $result->outcome);
        self::assertSame(1, $result->jobsProcessed);
        self::assertSame(1, ProcessOrderHandler::$handled);
        $driver = Coordinator::get()->driver();
        $import = $driver->reserve(new ReserveRequest('default', 'persistent-w', 30));
        self::assertNotNull($import);
        self::assertSame('acme.import_catalog', $import->envelope->jobType);
        self::assertSame(ExecutionClass::Persistent, $import->envelope->executionClass());
    }

    public function testCompatMaxJobsBound(): void
    {
        Coordinator::bootForTesting(['compatibility_max_jobs' => 2]);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        for ($i = 0; $i < 5; $i++) {
            Queue::dispatch(new ProcessOrderJob($i));
        }
        $result = Queue::execution()->tick(true);
        self::assertSame(2, $result->jobsProcessed);
        self::assertSame(2, ProcessOrderHandler::$handled);
    }

    public function testRunnerLockSkipsOverlappingTick(): void
    {
        Coordinator::bootForTesting();
        $exec = Queue::execution();
        $token = 'owner-a';
        self::assertTrue($exec->lock()->acquire(ExecutionRuntime::RUNNER_LOCK, $token, 30));
        $result = $exec->tick(true);
        self::assertSame('skipped', $result->outcome);
        self::assertSame('runner_lock_held', $result->reason);
        $exec->lock()->release(ExecutionRuntime::RUNNER_LOCK, $token);
    }

    public function testDisabledCompatDoesNotProcessUnlessForced(): void
    {
        Coordinator::bootForTesting(['compatibility_enabled' => false]);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(1));
        $skipped = Queue::execution()->tick(false);
        self::assertSame('skipped', $skipped->outcome);
        $forced = Queue::execution()->tick(true);
        self::assertSame('ok', $forced->outcome);
        self::assertSame(1, $forced->jobsProcessed);
    }

    public function testHighTimeoutStandardJobSkippedByCompatProfile(): void
    {
        Coordinator::bootForTesting(['compatibility_max_job_timeout' => 60]);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::on('default')->withTimeout(120)->dispatch(new ProcessOrderJob(1));
        Queue::dispatch(new ProcessOrderJob(2));
        $result = Queue::execution()->tick(true);
        self::assertSame(1, $result->jobsProcessed);
        $left = Coordinator::get()->driver()->reserve(new ReserveRequest(
            'default',
            'w',
            30,
            0,
            ExecutionClass::Standard->value,
            120,
        ));
        self::assertNotNull($left);
        self::assertSame(120, $left->envelope->timeoutSeconds);
    }

    public function testOneShotWorkerDrains(): void
    {
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(1));
        Queue::dispatch(new ProcessOrderJob(2));
        $options = WorkerOptions::fromCli(['once' => true], 'default', Coordinator::get()->config());
        self::assertSame(0, $options->sleepSeconds);
        self::assertSame(\Fuzeo\Queue\Execution\ProcessType::CronCli, $options->processType);
        $worker = WorkerLoop::fromManager(Coordinator::get(), $options);
        $worker->run();
        self::assertSame(2, $worker->processed());
        self::assertSame(ExecutionMode::CronCli, Queue::execution()->mode());
    }

    public function testRetryRemainsOnQueueAfterCompatFailure(): void
    {
        $clock = new \Fuzeo\Queue\Support\FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        Coordinator::bootForTesting(['compatibility_max_jobs' => 1], clock: $clock);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), FailingOrderHandler::class);
        Queue::on('default')->withRetryPolicy(new \Fuzeo\Queue\Retry\RetryPolicy(3, new \Fuzeo\Queue\Retry\FixedBackoff(0)))->dispatch(new ProcessOrderJob(1));
        $result = Queue::execution()->tick(true);
        self::assertSame(1, $result->jobsProcessed);
        $clock->set($clock->now()->add(new \DateInterval('PT1S')));
        $again = Coordinator::get()->driver()->reserve(new ReserveRequest('default', 'w', 30));
        self::assertNotNull($again);
        self::assertGreaterThan(0, $again->envelope->attempt);
    }

    public function testRuntimeCapabilitiesExposeExecutionMode(): void
    {
        Coordinator::bootForTesting();
        $caps = Coordinator::get()->interop()->runtime(new Origin('acme/shop', '1.0.0'))->capabilities();
        self::assertFalse($caps->supportsLongRunningJobs());
        self::assertSame('none', $caps->executionMode());
        self::assertTrue($caps->supports('dispatch'));
    }

    public function testInternalCompatHookIsNotAMigrationCandidate(): void
    {
        Coordinator::bootForTesting();
        $planner = new \Fuzeo\Queue\Interop\MigrationPlanner(
            Coordinator::get(),
            new \Fuzeo\Queue\Interop\MigrationRegistry(),
            new \Fuzeo\Queue\Interop\MemoryMigrationStore(),
            new \Fuzeo\Queue\Interop\CronInspector(new \Fuzeo\Queue\Interop\FakeCronGateway()),
            new \Fuzeo\Queue\Interop\ActionSchedulerInspector(new \Fuzeo\Queue\Interop\FakeActionSchedulerGateway()),
        );
        $event = new \Fuzeo\Queue\Interop\CronEvent(
            hook: \Fuzeo\Queue\Execution\CompatTrigger::HOOK,
            timestamp: time(),
            args: [],
            recurrence: false,
            intervalSeconds: null,
            siteId: 1,
            networkId: 1,
            eventKey: 'k',
        );
        self::assertSame(
            \Fuzeo\Queue\Interop\Compatibility::NotEligible,
            $planner->classifyCron($event, null)
        );
    }
}
