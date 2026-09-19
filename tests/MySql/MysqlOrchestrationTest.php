<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\MySql;

use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Orchestration\BatchState;
use Fuzeo\Queue\Orchestration\ChainState;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ImportRecordJob;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;

final class MysqlOrchestrationTest extends MysqlTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testChainCrashReconcileDispatchesNextStepOnce(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        $origin = new Origin('acme/shop', '1.0.0');
        Queue::register(ProcessOrderJob::class, $origin, ProcessOrderHandler::class);
        Queue::register(ImportRecordJob::class, $origin, ProcessOrderHandler::class);
        $chain = Queue::chain([
            new ProcessOrderJob(1),
            new ImportRecordJob('b'),
        ])->dispatch();
        $driver = Coordinator::get()->driver();
        $reserved = $driver->reserve(new ReserveRequest('default', 'w1', 30));
        self::assertNotNull($reserved);
        $driver->acknowledge($reserved);
        Coordinator::get()->orchestrator()->reconcile(20);
        Coordinator::get()->orchestrator()->reconcile(20);
        $fresh = Coordinator::get()->orchestrator()->store()->getChain($chain->chainId);
        self::assertNotNull($fresh);
        self::assertSame(2, $fresh->currentStep);
        self::assertSame(ChainState::Active, $fresh->state);
        self::assertSame(1, $driver->size('default'));
    }

    public function testBatchCompletesOnMysql(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        $origin = new Origin('acme/shop', '1.0.0');
        Queue::register(ProcessOrderJob::class, $origin, ProcessOrderHandler::class);
        Queue::register(ImportRecordJob::class, $origin, ProcessOrderHandler::class);
        $batch = Queue::batch([
            new ProcessOrderJob(1),
            new ProcessOrderJob(2),
            new ProcessOrderJob(3),
        ])->then(new ImportRecordJob('done'))->dispatch();
        $worker = WorkerLoop::fromManager(Coordinator::get(), new WorkerOptions(sleepSeconds: 0, maxJobs: 0));
        $worker->run(10);
        $fresh = Coordinator::get()->orchestrator()->store()->getBatch($batch->batchId);
        self::assertNotNull($fresh);
        self::assertSame(BatchState::Completed, $fresh->state);
        self::assertSame(3, $fresh->completedJobs);
        self::assertNotNull($fresh->thenJobId);
    }
}
