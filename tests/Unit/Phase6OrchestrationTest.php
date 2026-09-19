<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Drivers\CancelResult;
use Fuzeo\Queue\Drivers\Memory\MemoryDriver;
use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Orchestration\BatchFailurePolicy;
use Fuzeo\Queue\Orchestration\BatchMemberRecord;
use Fuzeo\Queue\Orchestration\BatchRecord;
use Fuzeo\Queue\Orchestration\BatchState;
use Fuzeo\Queue\Orchestration\ChainState;
use Fuzeo\Queue\Orchestration\MemberStatus;
use Fuzeo\Queue\Orchestration\OrchestrationIdentity;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Support\Ulid;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\FailUntilHandler;
use Fuzeo\Queue\Tests\Support\ImportRecordJob;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Tests\Support\RetrySoonJob;
use Fuzeo\Queue\Tests\Support\TerminalFailHandler;
use Fuzeo\Queue\Tests\Support\UniqueProductJob;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;
use PHPUnit\Framework\TestCase;

final class Phase6OrchestrationTest extends TestCase
{
    private Origin $origin;

    protected function setUp(): void
    {
        FailUntilHandler::reset();
        ProcessOrderHandler::reset();
        Coordinator::bootForTesting();
        $this->origin = new Origin('acme/shop', '1.0.0');
        Queue::register(ProcessOrderJob::class, $this->origin, ProcessOrderHandler::class);
        Queue::register(ImportRecordJob::class, $this->origin, ProcessOrderHandler::class);
        Queue::register(UniqueProductJob::class, $this->origin, ProcessOrderHandler::class);
    }

    protected function tearDown(): void
    {
        Coordinator::reset();
    }

    public function testChainRunsStepsSequentially(): void
    {
        $chain = Queue::chain([
            new ProcessOrderJob(1),
            new ImportRecordJob('a'),
            new ProcessOrderJob(2),
        ])->dispatch();
        self::assertSame(ChainState::Active, $chain->state);
        self::assertSame(1, Coordinator::get()->driver()->size('default'));
        $this->work(3);
        $fresh = Coordinator::get()->orchestrator()->store()->getChain($chain->chainId);
        self::assertNotNull($fresh);
        self::assertSame(ChainState::Completed, $fresh->state);
        self::assertSame(3, ProcessOrderHandler::$handled);
    }

    public function testChainDoesNotAdvanceOnRetryableFailure(): void
    {
        Coordinator::reset();
        Coordinator::bootForTesting();
        Queue::register(RetrySoonJob::class, $this->origin, FailUntilHandler::class);
        Queue::register(ImportRecordJob::class, $this->origin, ProcessOrderHandler::class);
        FailUntilHandler::$failUntilAttempt = 2;
        $chain = Queue::chain([
            new RetrySoonJob(1),
            new ImportRecordJob('next'),
        ])->dispatch();
        $this->work(1);
        $fresh = Coordinator::get()->orchestrator()->store()->getChain($chain->chainId);
        self::assertNotNull($fresh);
        self::assertSame(ChainState::Active, $fresh->state);
        self::assertSame(1, $fresh->currentStep);
        $this->work(2);
        $fresh = Coordinator::get()->orchestrator()->store()->getChain($chain->chainId);
        self::assertNotNull($fresh);
        self::assertSame(ChainState::Completed, $fresh->state);
        self::assertGreaterThanOrEqual(1, ProcessOrderHandler::$handled);
    }

    public function testChainStopsOnDeadStep(): void
    {
        Coordinator::reset();
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, $this->origin, ProcessOrderHandler::class);
        Queue::register(ImportRecordJob::class, $this->origin, TerminalFailHandler::class);
        $chain = Queue::chain([
            new ProcessOrderJob(1),
            new ImportRecordJob('dead'),
            new ProcessOrderJob(2),
        ])->dispatch();
        $this->work(5);
        $fresh = Coordinator::get()->orchestrator()->store()->getChain($chain->chainId);
        self::assertNotNull($fresh);
        self::assertSame(ChainState::Failed, $fresh->state);
        self::assertSame(2, $fresh->failedStep);
        self::assertSame(1, ProcessOrderHandler::$handled);
    }

    public function testChainCancelPreventsNextStep(): void
    {
        $chain = Queue::chain([
            new ProcessOrderJob(1),
            new ImportRecordJob('later'),
        ])->dispatch();
        Coordinator::get()->orchestrator()->cancelChain($chain->chainId);
        $this->work(3);
        $fresh = Coordinator::get()->orchestrator()->store()->getChain($chain->chainId);
        self::assertSame(ChainState::Cancelled, $fresh?->state);
        self::assertSame(0, ProcessOrderHandler::$handled);
    }

    public function testBatchCompletesAndDispatchesFollowUpOnce(): void
    {
        $batch = Queue::batch([
            new ProcessOrderJob(1),
            new ProcessOrderJob(2),
            new ImportRecordJob('x'),
        ])->then(new ImportRecordJob('done'))->dispatch();
        self::assertSame(BatchState::Active, $batch->state);
        $this->work(10);
        $fresh = Coordinator::get()->orchestrator()->store()->getBatch($batch->batchId);
        self::assertSame(BatchState::Completed, $fresh?->state);
        $follow = 0;
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MemoryDriver::class, $driver);
        foreach ($driver->all() as $envelope) {
            if (($envelope->metadata['_follow_up'] ?? null) === 'then') {
                $follow++;
            }
        }
        self::assertSame(1, $follow);
    }

    public function testBatchCollectAllFailsAfterDeadMember(): void
    {
        Coordinator::reset();
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, $this->origin, ProcessOrderHandler::class);
        Queue::register(ImportRecordJob::class, $this->origin, TerminalFailHandler::class);
        $batch = Queue::batch([
            new ProcessOrderJob(1),
            new ImportRecordJob('bad'),
            new ProcessOrderJob(2),
        ])->dispatch();
        $this->work(10);
        $fresh = Coordinator::get()->orchestrator()->store()->getBatch($batch->batchId);
        self::assertNotNull($fresh);
        self::assertSame(BatchState::Failed, $fresh->state);
        self::assertSame(1, $fresh->failedJobs);
        self::assertSame(2, $fresh->completedJobs);
    }

    public function testPendingCancelWinsOverLaterReserve(): void
    {
        $envelope = Queue::dispatch(new ProcessOrderJob(9));
        $result = Queue::cancel($envelope->jobId);
        self::assertSame(CancelResult::CANCELLED, $result->outcome);
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MemoryDriver::class, $driver);
        $reserved = $driver->reserve(new \Fuzeo\Queue\Drivers\ReserveRequest('default', 'w1', 30));
        self::assertNull($reserved);
        self::assertSame(JobState::Cancelled, $driver->job($envelope->jobId)->state);
    }

    public function testReservedCancelIsCooperative(): void
    {
        Queue::dispatch(new ProcessOrderJob(9));
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MemoryDriver::class, $driver);
        $reserved = $driver->reserve(new \Fuzeo\Queue\Drivers\ReserveRequest('default', 'w1', 30));
        self::assertNotNull($reserved);
        $result = Queue::cancel($reserved->envelope->jobId);
        self::assertSame(CancelResult::CANCEL_REQUESTED, $result->outcome);
        self::assertTrue($driver->isCancellationRequested($reserved->envelope->jobId));
        $driver->settleCancelled($reserved);
        self::assertSame(JobState::Cancelled, $driver->job($reserved->envelope->jobId)->state);
    }

    public function testFakeQueueSeesBatchDispatch(): void
    {
        Queue::fake();
        Queue::register(ProcessOrderJob::class, $this->origin, ProcessOrderHandler::class);
        Queue::batch([new ProcessOrderJob(1), new ProcessOrderJob(2)])->dispatch();
        Queue::assertBatchDispatched();
        Coordinator::get()->fake()->assertBatchSize(2);
        self::assertCount(2, Coordinator::get()->fake()->dispatched());
    }

    public function testChainRaceAdvancesOnce(): void
    {
        $chain = Queue::chain([
            new ProcessOrderJob(1),
            new ImportRecordJob('b'),
        ])->dispatch();
        $this->work(1);
        $orch = Coordinator::get()->orchestrator();
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MemoryDriver::class, $driver);
        $job = $driver->all()[0];
        $orch->onCompleted($job);
        $orch->onCompleted($job);
        $fresh = $orch->store()->getChain($chain->chainId);
        self::assertSame(2, $fresh?->currentStep);
        $b = 0;
        foreach ($driver->all() as $envelope) {
            if ($envelope->jobType === ImportRecordJob::type()) {
                $b++;
            }
        }
        self::assertSame(1, $b);
    }

    public function testBatchCreationCrashResumeDoesNotDuplicate(): void
    {
        $jobs = [];
        for ($i = 0; $i < 25; $i++) {
            $jobs[] = new ProcessOrderJob($i);
        }
        $batch = Queue::batch($jobs)->dispatch();
        Coordinator::get()->orchestrator()->reconcile(50);
        $waiting = Coordinator::get()->orchestrator()->store()->waitingMembers($batch->batchId, 100);
        self::assertSame([], $waiting);
        self::assertSame(25, Coordinator::get()->driver()->size('default'));
    }

    public function testLongChainCompletes(): void
    {
        $jobs = [];
        for ($i = 0; $i < 100; $i++) {
            $jobs[] = new ProcessOrderJob($i);
        }
        $chain = Queue::chain($jobs)->dispatch();
        $this->work(120);
        $fresh = Coordinator::get()->orchestrator()->store()->getChain($chain->chainId);
        self::assertSame(ChainState::Completed, $fresh?->state);
    }

    public function testLargeBatchCounters(): void
    {
        $jobs = [];
        for ($i = 0; $i < 250; $i++) {
            $jobs[] = new ProcessOrderJob($i);
        }
        $batch = Queue::batch($jobs)->dispatch();
        $this->work(300);
        $fresh = Coordinator::get()->orchestrator()->store()->getBatch($batch->batchId);
        self::assertNotNull($fresh);
        self::assertSame(BatchState::Completed, $fresh->state);
        self::assertSame(250, $fresh->completedJobs);
    }

    public function testUniqueMemberConflictDoesNotReduceTotal(): void
    {
        Queue::dispatch(new UniqueProductJob(5));
        $batch = Queue::batch([new UniqueProductJob(5), new ProcessOrderJob(1)])->dispatch();
        $fresh = Coordinator::get()->orchestrator()->store()->getBatch($batch->batchId);
        self::assertNotNull($fresh);
        self::assertSame(2, $fresh->totalJobs);
        self::assertGreaterThanOrEqual(1, $fresh->failedJobs);
    }

    public function testChainCrashBeforeAdvanceIsReconciled(): void
    {
        $chain = Queue::chain([
            new ProcessOrderJob(1),
            new ImportRecordJob('b'),
        ])->dispatch();
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MemoryDriver::class, $driver);
        $reserved = $driver->reserve(new ReserveRequest('default', 'w1', 30));
        self::assertNotNull($reserved);
        $driver->acknowledge($reserved);
        $orch = Coordinator::get()->orchestrator();
        $stuck = $orch->store()->getChain($chain->chainId);
        self::assertSame(1, $stuck?->currentStep);
        $orch->reconcile(10);
        $fresh = $orch->store()->getChain($chain->chainId);
        self::assertSame(2, $fresh?->currentStep);
        $b = 0;
        foreach ($driver->all() as $envelope) {
            if ($envelope->jobType === ImportRecordJob::type()) {
                $b++;
            }
        }
        self::assertSame(1, $b);
    }

    public function testBatchFollowUpRaceDispatchesOnce(): void
    {
        $batch = Queue::batch([
            new ProcessOrderJob(1),
            new ProcessOrderJob(2),
        ])->then(new ImportRecordJob('done'))->dispatch();
        $this->work(5);
        $orch = Coordinator::get()->orchestrator();
        $orch->reconcile(20);
        $orch->reconcile(20);
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MemoryDriver::class, $driver);
        $follow = 0;
        foreach ($driver->all() as $envelope) {
            if (($envelope->metadata['_follow_up'] ?? null) === 'then') {
                $follow++;
            }
        }
        self::assertSame(1, $follow);
        $fresh = $orch->store()->getBatch($batch->batchId);
        self::assertSame(BatchState::Completed, $fresh?->state);
    }

    public function testPartialBatchMaterializationResumesWithoutDuplicates(): void
    {
        $now = new \DateTimeImmutable('2026-06-01T00:00:00Z');
        $ms = OrchestrationIdentity::timestampMs($now);
        $batchId = Ulid::generate($ms);
        $header = new BatchRecord(
            $batchId,
            $this->origin,
            ExecutionContext::site(1, 1),
            BatchState::Creating,
            8,
            0,
            0,
            0,
            BatchFailurePolicy::CollectAll,
            $now,
            'crash-resume',
        );
        $store = Coordinator::get()->orchestrator()->store();
        $store->createHeader($header);
        $members = [];
        for ($i = 0; $i < 8; $i++) {
            $members[] = new BatchMemberRecord(
                $batchId,
                $i,
                ProcessOrderJob::type(),
                1,
                ['order_id' => $i],
                'default',
                0,
                3,
                60,
                MemberStatus::Waiting,
                ['_batch_index' => $i],
                ['batch'],
                null,
                OrchestrationIdentity::batchMember($batchId, $i, $ms),
            );
        }
        $store->saveMembers($members);
        self::assertSame(0, Coordinator::get()->driver()->size('default'));
        Coordinator::get()->orchestrator()->reconcile(20);
        $fresh = $store->getBatch($batchId);
        self::assertNotNull($fresh);
        self::assertSame(BatchState::Active, $fresh->state);
        self::assertSame([], $store->waitingMembers($batchId, 20));
        self::assertSame(8, Coordinator::get()->driver()->size('default'));
        Coordinator::get()->orchestrator()->reconcile(20);
        self::assertSame(8, Coordinator::get()->driver()->size('default'));
    }

    private function work(int $maxJobs): void
    {
        $worker = WorkerLoop::fromManager(
            Coordinator::get(),
            new WorkerOptions(sleepSeconds: 0, maxJobs: 0)
        );
        $worker->run($maxJobs);
    }
}
