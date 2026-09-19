<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Deployment\DeploymentGeneration;
use Fuzeo\Queue\Deployment\DeploymentWatch;
use Fuzeo\Queue\Deployment\MemoryDeploymentStore;
use Fuzeo\Queue\Deployment\ProcessExitCode;
use Fuzeo\Queue\Deployment\ReadinessReason;
use Fuzeo\Queue\Deployment\RuntimeCompatibility;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Operations\AccessDenied;
use Fuzeo\Queue\Operations\Operator;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Runtime\FakeWordPressRuntime;
use Fuzeo\Queue\Runtime\MemoryMonitor;
use Fuzeo\Queue\Runtime\ProcessLifecycle;
use Fuzeo\Queue\Runtime\RecycleReason;
use Fuzeo\Queue\Runtime\RuntimeGeneration;
use Fuzeo\Queue\Support\Ulid;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Tests\Support\RestartOnceHandler;
use Fuzeo\Queue\Tests\Support\UniqueProductJob;
use Fuzeo\Queue\WordPress\QueueAccess;
use Fuzeo\Queue\WordPress\WordPressBootstrap;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;
use PHPUnit\Framework\TestCase;

final class Phase9DeploymentTest extends TestCase
{
    protected function setUp(): void
    {
        Coordinator::bootForTesting();
        ProcessOrderHandler::reset();
        RestartOnceHandler::reset();
        $GLOBALS['fuzeo_wp_caps'] = ['manage_options'];
        $GLOBALS['fuzeo_wp_multisite'] = false;
        $GLOBALS['fuzeo_wp_blog_id'] = 1;
    }

    protected function tearDown(): void
    {
        Coordinator::reset();
        unset($GLOBALS['fuzeo_wp_caps'], $GLOBALS['fuzeo_wp_multisite'], $GLOBALS['fuzeo_wp_blog_id']);
    }

    public function testRestartGenerationDoesNotRecycleProcessThatBootedOnIt(): void
    {
        $store = new MemoryDeploymentStore();
        $gen = new DeploymentGeneration(new RuntimeGeneration(new FakeWordPressRuntime()));
        $store->requestRestart('R2', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $watch = DeploymentWatch::boot($store, $gen);
        $life = new ProcessLifecycle(
            new WorkerOptions(sleepSeconds: 0, generationCheckInterval: 1),
            $gen,
            new MemoryMonitor(memory_get_usage(true)),
            bootGeneration: $gen->current(),
            deployment: $watch,
        );
        $life->refreshGeneration();
        self::assertFalse($life->shouldExit(0, false));
        self::assertTrue($life->mayReserve());
        self::assertSame(RecycleReason::None, $life->reason());
    }

    public function testRestartGenerationRecyclesOlderProcess(): void
    {
        $store = new MemoryDeploymentStore();
        $gen = new DeploymentGeneration(new RuntimeGeneration(new FakeWordPressRuntime()));
        $watch = DeploymentWatch::boot($store, $gen);
        $life = new ProcessLifecycle(
            new WorkerOptions(sleepSeconds: 0, generationCheckInterval: 1),
            $gen,
            new MemoryMonitor(memory_get_usage(true)),
            bootGeneration: $gen->current(),
            deployment: $watch,
        );
        $store->requestRestart('R2', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        self::assertTrue($life->shouldExit(0, false));
        self::assertSame(RecycleReason::RestartRequested, $life->reason());
        self::assertFalse($life->mayReserve());
        self::assertSame(ProcessExitCode::OK, ProcessExitCode::fromReason($life->reason()));
    }

    public function testDrainStopsReservationsWithoutKillingSemantics(): void
    {
        $store = new MemoryDeploymentStore();
        $gen = new DeploymentGeneration(new RuntimeGeneration(new FakeWordPressRuntime()));
        $watch = DeploymentWatch::boot($store, $gen);
        $life = new ProcessLifecycle(
            new WorkerOptions(sleepSeconds: 0),
            $gen,
            new MemoryMonitor(memory_get_usage(true)),
            bootGeneration: $gen->current(),
            deployment: $watch,
        );
        $store->requestDrain('D1', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        self::assertFalse($life->mayReserve());
        self::assertTrue($life->shouldExit(0, false));
        self::assertSame(RecycleReason::Drain, $life->reason());
    }

    public function testProcessStartedDuringDrainStaysAliveAndDoesNotReserve(): void
    {
        $store = new MemoryDeploymentStore();
        $store->requestDrain('D1', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $gen = new DeploymentGeneration(new RuntimeGeneration(new FakeWordPressRuntime()));
        $watch = DeploymentWatch::boot($store, $gen);
        $life = new ProcessLifecycle(
            new WorkerOptions(sleepSeconds: 0),
            $gen,
            new MemoryMonitor(memory_get_usage(true)),
            bootGeneration: $gen->current(),
            deployment: $watch,
        );
        $life->refreshGeneration();
        self::assertFalse($life->shouldExit(0, false));
        self::assertFalse($life->mayReserve());
    }

    public function testDeploymentTokenChangesGeneration(): void
    {
        $wp = new FakeWordPressRuntime();
        $runtime = new RuntimeGeneration($wp);
        $a = new DeploymentGeneration($runtime, '');
        $b = new DeploymentGeneration($runtime, '20260919-abc123');
        self::assertNotSame($a->current(), $b->current());
        $c = new DeploymentGeneration($runtime, '20260919-abc123');
        self::assertSame($b->current(), $c->current());
    }

    public function testNoChangeDoesNotRestart(): void
    {
        $wp = new FakeWordPressRuntime();
        $gen = new DeploymentGeneration(new RuntimeGeneration($wp), 'token');
        $life = new ProcessLifecycle(
            new WorkerOptions(sleepSeconds: 0, generationCheckInterval: 1),
            $gen,
            new MemoryMonitor(memory_get_usage(true)),
            bootGeneration: $gen->current(),
        );
        $life->afterJob();
        self::assertFalse($life->shouldExit(0, false));
    }

    public function testCompatibilityEvaluatorSchemaAndMaintenance(): void
    {
        $mismatch = (new RuntimeCompatibility(
            loadedSchema: 6,
            storedSchema: 7,
            targetSchema: 7,
            exactSchema: true,
        ))->evaluate();
        self::assertFalse($mismatch->canReserve);
        self::assertFalse($mismatch->canBoot);
        self::assertSame(ReadinessReason::SchemaMismatch->value, $mismatch->primaryReason);

        $migrate = (new RuntimeCompatibility(
            loadedSchema: 6,
            storedSchema: 5,
            targetSchema: 6,
            exactSchema: true,
        ))->evaluate();
        self::assertTrue($migrate->mustMigrate);
        self::assertFalse($migrate->canReserve);

        $store = new MemoryDeploymentStore();
        $owner = Ulid::generate();
        $ok = $store->enterMaintenance($owner, (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+5 minutes'), 'schema_migration');
        self::assertTrue($ok);
        self::assertFalse($store->enterMaintenance(Ulid::generate(), (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+5 minutes'), 'other'));
        self::assertTrue($store->releaseMaintenance($owner));
        self::assertFalse($store->releaseMaintenance('stale-owner'));
    }

    public function testReadinessReasonsAndHealthDistinction(): void
    {
        $ops = Coordinator::get()->operations();
        $ready = $ops->readiness(Operator::cli());
        self::assertTrue($ready->ready);
        self::assertSame(ReadinessReason::Ready->value, $ready->reason);
        self::assertSame(0, $ready->exitCode);
        $ops->requestDrain(Operator::cli());
        $draining = $ops->readiness(Operator::cli());
        self::assertFalse($draining->ready);
        self::assertSame(ReadinessReason::Draining->value, $draining->reason);
        $health = $ops->queueHealth(Operator::cli());
        self::assertSame('draining', $health->operationalState);
        self::assertNotSame('critical', $health->status->value);
        $ops->cancelDrain(Operator::cli());
        self::assertTrue($ops->readiness(Operator::cli())->ready);
    }

    public function testRestartUnderLoadAndUniquenessSurvives(): void
    {
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), RestartOnceHandler::class);
        Queue::register(UniqueProductJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        RestartOnceHandler::reset();
        for ($i = 1; $i <= 8; $i++) {
            Queue::dispatch(new ProcessOrderJob($i));
        }
        $first = Queue::dispatchResult(new UniqueProductJob(1));
        self::assertTrue($first->accepted);
        $this->worker()->run();
        $held = Queue::dispatchResult(new UniqueProductJob(1));
        self::assertFalse($held->accepted);
        $this->worker()->run();
        self::assertSame(8, RestartOnceHandler::$handled);
    }

    public function testChainAndBatchSurviveRestart(): void
    {
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::chain([new ProcessOrderJob(1), new ProcessOrderJob(2)])->dispatch();
        Queue::batch([new ProcessOrderJob(3), new ProcessOrderJob(4)])->dispatch();
        Coordinator::get()->operations()->requestRestart(Operator::cli());
        $this->worker()->run();
        Coordinator::get()->operations()->requestRestart(Operator::cli());
        $this->worker()->run();
        self::assertSame(4, ProcessOrderHandler::$handled);
    }

    public function testSiteAdminCannotRestartNetworkFleet(): void
    {
        $GLOBALS['fuzeo_wp_multisite'] = true;
        $GLOBALS['fuzeo_wp_caps'] = ['manage_options', 'fuzeo_queue_manage'];
        $operator = new Operator(new QueueAccess(), 1, 1, false);
        $this->expectException(AccessDenied::class);
        Coordinator::get()->operations()->requestRestart($operator);
    }

    public function testPluginLifecycleRequestsRestart(): void
    {
        WordPressBootstrap::onCodeChanged();
        $state = Coordinator::get()->deployment()->snapshot();
        self::assertNotSame('', $state->restartGeneration);
    }

    public function testCliReadyAndRestartJson(): void
    {
        $ready = Coordinator::get()->operations()->readiness(Operator::cli());
        self::assertTrue($ready->ready);
        $cmd = new \Fuzeo\Queue\WordPress\Cli\QueueCommand();
        $cmd->restart([], ['format' => 'json']);
        self::assertNotSame('', Coordinator::get()->deployment()->snapshot()->restartGeneration);
        $check = Coordinator::get()->operations()->migrate(Operator::cli(), true);
        self::assertArrayHasKey('required', $check);
    }

    public function testGenerationCheckIsCheap(): void
    {
        $gen = new DeploymentGeneration(new RuntimeGeneration(new FakeWordPressRuntime()), 'token');
        $started = hrtime(true);
        for ($i = 0; $i < 5000; $i++) {
            $gen->current();
        }
        $ms = (hrtime(true) - $started) / 1e6;
        self::assertLessThan(500, $ms);
    }

    public function testDispatchRefusedDuringMaintenance(): void
    {
        $store = Coordinator::get()->deployment();
        $owner = Ulid::generate();
        $store->enterMaintenance($owner, (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+5 minutes'), 'schema_migration');
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $this->expectException(\Fuzeo\Queue\Exceptions\DriverException::class);
        Queue::dispatch(new ProcessOrderJob(1));
    }

    private function worker(): WorkerLoop
    {
        $runtime = Coordinator::get();

        return WorkerLoop::fromManager(
            $runtime,
            new WorkerOptions(sleepSeconds: 0, maxJobs: 50, generationCheckInterval: 1),
        );
    }
}
