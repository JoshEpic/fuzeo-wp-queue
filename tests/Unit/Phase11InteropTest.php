<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Drivers\UnavailableDriver;
use Fuzeo\Queue\Exceptions\DescriptorConflictException;
use Fuzeo\Queue\Exceptions\RuntimeUnavailableException;
use Fuzeo\Queue\Interop\ActionSchedulerDescriptor;
use Fuzeo\Queue\Interop\Compatibility;
use Fuzeo\Queue\Interop\CronDescriptor;
use Fuzeo\Queue\Interop\FakeActionSchedulerGateway;
use Fuzeo\Queue\Interop\FakeAsyncRuntime;
use Fuzeo\Queue\Interop\FakeCronGateway;
use Fuzeo\Queue\Interop\InteropManager;
use Fuzeo\Queue\Interop\MemoryMigrationStore;
use Fuzeo\Queue\Interop\MigrationStatus;
use Fuzeo\Queue\Interop\RuntimeName;
use Fuzeo\Queue\Interop\RuntimePolicy;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use PHPUnit\Framework\TestCase;

final class Phase11InteropTest extends TestCase
{
    private Origin $origin;

    protected function setUp(): void
    {
        Coordinator::bootForTesting(['driver' => 'memory']);
        $this->origin = new Origin('acme/shop', '1.0.0');
        Queue::register(ProcessOrderJob::class, $this->origin, ProcessOrderHandler::class);
        ProcessOrderHandler::reset();
    }

    protected function tearDown(): void
    {
        Coordinator::reset();
    }

    public function testPreferQueueWhenHealthy(): void
    {
        $runtime = $this->manager()->runtime($this->origin, RuntimePolicy::PreferQueue);
        self::assertSame(RuntimeName::FuzeoQueue, $runtime->name());
        self::assertTrue($runtime->supports('batch'));
        $runtime->dispatch(new ProcessOrderJob(1));
        self::assertSame(1, Coordinator::get()->driver()->size('default'));
    }

    public function testFallbackWhenQueueUnhealthy(): void
    {
        Coordinator::reset();
        Coordinator::bootForTesting(['driver' => 'memory'], new UnavailableDriver());
        Queue::register(ProcessOrderJob::class, $this->origin, ProcessOrderHandler::class);
        $as = new FakeActionSchedulerGateway();
        $manager = $this->wired($as, new FakeCronGateway());
        $runtime = $manager->runtime($this->origin, RuntimePolicy::PreferQueue);
        self::assertSame(RuntimeName::ActionScheduler, $runtime->name());
        self::assertFalse($runtime->supports('batch'));
        $runtime->dispatch(new ProcessOrderJob(9));
        self::assertSame(1, $as->count(['status' => 'pending']));
        self::assertSame(RuntimeName::ActionScheduler, $runtime->name());
    }

    public function testRequireQueueFailsClosed(): void
    {
        Coordinator::reset();
        Coordinator::bootForTesting(['driver' => 'memory'], new UnavailableDriver());
        Queue::register(ProcessOrderJob::class, $this->origin, ProcessOrderHandler::class);
        $this->expectException(RuntimeUnavailableException::class);
        $this->wired(new FakeActionSchedulerGateway(), new FakeCronGateway())
            ->runtime($this->origin, RuntimePolicy::RequireQueue);
    }

    public function testQueueInstalledLaterDoesNotMigrateInFlight(): void
    {
        $as = new FakeActionSchedulerGateway();
        Coordinator::reset();
        Coordinator::bootForTesting(['driver' => 'memory'], new UnavailableDriver());
        Queue::register(ProcessOrderJob::class, $this->origin, ProcessOrderHandler::class);
        $fallback = $this->wired($as, new FakeCronGateway());
        for ($i = 0; $i < 10; $i++) {
            $fallback->runtime($this->origin)->dispatch(new ProcessOrderJob($i + 1));
        }
        self::assertSame(10, $as->count(['status' => 'pending']));

        Coordinator::reset();
        Coordinator::bootForTesting(['driver' => 'memory']);
        Queue::register(ProcessOrderJob::class, $this->origin, ProcessOrderHandler::class);
        $queue = $this->wired($as, new FakeCronGateway());
        for ($i = 0; $i < 10; $i++) {
            $queue->runtime($this->origin)->dispatch(new ProcessOrderJob($i + 100));
        }
        self::assertSame(10, $as->count(['status' => 'pending']));
        self::assertSame(10, Coordinator::get()->driver()->size('default'));
        $status = $queue->status($this->origin);
        self::assertSame('fuzeo_queue', $status['active_runtime']);
        self::assertSame(10, $status['legacy_pending']);
        self::assertFalse($status['fully_transitioned']);
    }

    public function testUnhealthyAfterDispatchDoesNotFailover(): void
    {
        $as = new FakeActionSchedulerGateway();
        $runtime = $this->wired($as, new FakeCronGateway())->runtime($this->origin);
        $runtime->dispatch(new ProcessOrderJob(3));
        self::assertSame(1, Coordinator::get()->driver()->size('default'));
        self::assertSame(0, $as->count([]));
    }

    public function testDescriptorConflictFailsLoudly(): void
    {
        $registry = Coordinator::get()->interop()->registry();
        $registry->registerActionScheduler(new ActionSchedulerDescriptor(
            $this->origin,
            'acme_process_order',
            ProcessOrderJob::class,
            static fn (array $args): Job => new ProcessOrderJob((int) ($args['order_id'] ?? 0)),
        ));
        $this->expectException(DescriptorConflictException::class);
        $registry->registerActionScheduler(new ActionSchedulerDescriptor(
            new Origin('other/plugin', '1.0.0'),
            'acme_process_order',
            ProcessOrderJob::class,
            static fn (array $args): Job => new ProcessOrderJob(1),
        ));
    }

    public function testUnknownWorkloadIsNotMigratable(): void
    {
        $as = new FakeActionSchedulerGateway();
        $as->enqueueAsync('third_party_hook', ['x' => 1], 'woocommerce');
        $manager = $this->wired($as, new FakeCronGateway());
        $rows = $manager->actionSchedulerCandidates(1, 1);
        self::assertSame(Compatibility::Unknown->value, $rows[0]['compatibility']);
        self::assertFalse($rows[0]['migratable']);
    }

    public function testUnsafeMappingFailsCleanly(): void
    {
        $descriptor = new CronDescriptor(
            $this->origin,
            'acme_hourly_sync',
            ProcessOrderJob::class,
            static function (array $args): Job {
                unset($args);
                return new class implements Job {
                    public static function type(): string
                    {
                        return 'acme.process_order';
                    }

                    public static function schemaVersion(): int
                    {
                        return 1;
                    }

                    public function payload(): array
                    {
                        return ['obj' => (object) ['no' => true]];
                    }
                };
            },
            intervalSeconds: 3600,
        );
        $this->expectException(\Fuzeo\Queue\Exceptions\SerializationException::class);
        $descriptor->map([], Coordinator::get()->serializer());
    }

    public function testCronMigrationDryRunDoesNotMutate(): void
    {
        $cron = new FakeCronGateway();
        $next = time() + 3600;
        $cron->scheduleRecurring($next, 'hourly', 'acme_hourly_sync', [42]);
        $manager = $this->wired(new FakeActionSchedulerGateway(), $cron);
        $manager->registerCron(new CronDescriptor(
            $this->origin,
            'acme_hourly_sync',
            ProcessOrderJob::class,
            static fn (array $args): Job => new ProcessOrderJob((int) ($args[0] ?? 0)),
            intervalSeconds: 3600,
            scheduleName: 'acme-hourly-sync',
        ));
        $plan = $manager->planCron('acme_hourly_sync', ExecutionContext::singleSite());
        self::assertSame(Compatibility::DeclaredCompatible, $plan->compatibility);
        $record = $manager->migrate($plan, false);
        self::assertSame(MigrationStatus::Planned, $record->status);
        self::assertNotEmpty($cron->cronArray());
        self::assertSame([], Coordinator::get()->schedules()->all());
    }

    public function testCronRecurringMigrationAndRollback(): void
    {
        $cron = new FakeCronGateway();
        $next = time() + 3600;
        $cron->scheduleRecurring($next, 'hourly', 'acme_hourly_sync', [42]);
        $manager = $this->wired(new FakeActionSchedulerGateway(), $cron);
        $manager->registerCron(new CronDescriptor(
            $this->origin,
            'acme_hourly_sync',
            ProcessOrderJob::class,
            static fn (array $args): Job => new ProcessOrderJob((int) ($args[0] ?? 0)),
            intervalSeconds: 3600,
            scheduleName: 'acme-hourly-sync',
        ));
        $plan = $manager->planCron('acme_hourly_sync', ExecutionContext::singleSite());
        $record = $manager->migrate($plan, true);
        self::assertTrue($record->rollbackAvailable);
        self::assertSame([], $cron->cronArray());
        $schedule = Coordinator::get()->schedules()->get($record->destinationId);
        self::assertNotNull($schedule);
        self::assertTrue($schedule->enabled);
        $second = $manager->migrate($plan, true);
        self::assertSame($record->migrationId, $second->migrationId);
        $rolled = $manager->rollback($record->migrationId);
        self::assertSame(MigrationStatus::RolledBack, $rolled->status);
        self::assertNotEmpty($cron->cronArray());
        self::assertNull(Coordinator::get()->schedules()->get($record->destinationId));
    }

    public function testSingleCronBecomesDelayedJob(): void
    {
        $cron = new FakeCronGateway();
        $when = time() + 120;
        $cron->scheduleSingle($when, 'acme_once', [7]);
        $manager = $this->wired(new FakeActionSchedulerGateway(), $cron);
        $manager->registerCron(new CronDescriptor(
            $this->origin,
            'acme_once',
            ProcessOrderJob::class,
            static fn (array $args): Job => new ProcessOrderJob((int) ($args[0] ?? 0)),
        ));
        $record = $manager->migrate($manager->planCron('acme_once', ExecutionContext::singleSite()), true);
        self::assertSame('delayed_job', $record->destinationType);
        self::assertFalse($record->rollbackAvailable);
        self::assertNotSame('', $record->destinationId);
        self::assertSame(MigrationStatus::Completed, $record->status);
    }

    public function testMigrationRaceOneWinner(): void
    {
        $cron = new FakeCronGateway();
        $cron->scheduleRecurring(time() + 60, 'hourly', 'acme_hourly_sync', [1]);
        $manager = $this->wired(new FakeActionSchedulerGateway(), $cron);
        $manager->registerCron(new CronDescriptor(
            $this->origin,
            'acme_hourly_sync',
            ProcessOrderJob::class,
            static fn (array $args): Job => new ProcessOrderJob(1),
            intervalSeconds: 3600,
            scheduleName: 'acme-hourly-sync',
        ));
        $plan = $manager->planCron('acme_hourly_sync', ExecutionContext::singleSite());
        $a = $manager->migrate($plan, true);
        $b = $manager->migrate($plan, true);
        self::assertSame($a->migrationId, $b->migrationId);
        self::assertCount(1, Coordinator::get()->schedules()->all());
    }

    public function testCrashAfterDestinationBeforeSourceRemoval(): void
    {
        $cron = new FakeCronGateway();
        $cron->scheduleRecurring(time() + 90, 'hourly', 'acme_hourly_sync', [1]);
        $manager = $this->wired(new FakeActionSchedulerGateway(), $cron);
        $manager->registerCron(new CronDescriptor(
            $this->origin,
            'acme_hourly_sync',
            ProcessOrderJob::class,
            static fn (array $args): Job => new ProcessOrderJob(1),
            intervalSeconds: 3600,
            scheduleName: 'acme-hourly-sync',
        ));
        $plan = $manager->planCron('acme_hourly_sync', ExecutionContext::singleSite());
        $pending = Coordinator::get()->schedules()->job('acme-hourly-sync', new ProcessOrderJob(1))
            ->everySeconds(3600)
            ->disabled()
            ->save();
        $store = $manager->store();
        $record = new \Fuzeo\Queue\Interop\MigrationRecord(
            $pending->scheduleId,
            $plan->descriptorId,
            1,
            \Fuzeo\Queue\Interop\SourceSystem::WpCron,
            $plan->sourceIdentifier,
            $plan->sourceSnapshot,
            'schedule',
            $pending->scheduleId,
            $this->origin->package,
            1,
            1,
            MigrationStatus::Planned,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            null,
            [],
            false,
        );
        $store->insert($record);
        self::assertNotEmpty($cron->cronArray());
        $reconciled = $manager->reconcile($record->migrationId);
        self::assertSame(MigrationStatus::Planned, $reconciled->status);
        $schedule = Coordinator::get()->schedules()->get($pending->scheduleId);
        self::assertNotNull($schedule);
        self::assertFalse($schedule->enabled);
    }

    public function testInProgressActionsNotEligible(): void
    {
        $as = new FakeActionSchedulerGateway();
        $id = (int) $as->enqueueAsync('acme_process_order', ['order_id' => 1], '');
        $as->markInProgress($id);
        $manager = $this->wired($as, new FakeCronGateway());
        $manager->registerActionScheduler(new ActionSchedulerDescriptor(
            $this->origin,
            'acme_process_order',
            ProcessOrderJob::class,
            static fn (array $args): Job => new ProcessOrderJob((int) ($args['order_id'] ?? 0)),
        ));
        $rows = $manager->actionSchedulerCandidates(1, 1);
        self::assertSame(Compatibility::NotEligible->value, $rows[0]['compatibility']);
        $this->expectException(\Fuzeo\Queue\Exceptions\InteropException::class);
        $manager->planActionScheduler('acme_process_order', ExecutionContext::singleSite());
    }

    public function testRecurringActionSchedulerMigration(): void
    {
        $as = new FakeActionSchedulerGateway();
        $as->scheduleRecurring(time() + 50, 3600, 'acme_process_order', ['order_id' => 5], 'acme-shop');
        $manager = $this->wired($as, new FakeCronGateway());
        $manager->registerActionScheduler(new ActionSchedulerDescriptor(
            $this->origin,
            'acme_process_order',
            ProcessOrderJob::class,
            static fn (array $args): Job => new ProcessOrderJob((int) ($args['order_id'] ?? 0)),
            group: 'acme-shop',
            scheduleName: 'acme-process-order',
            intervalSeconds: 3600,
        ));
        $record = $manager->migrate($manager->planActionScheduler('acme_process_order', ExecutionContext::singleSite()), true);
        self::assertTrue($record->rollbackAvailable);
        self::assertSame(0, $as->count(['hook' => 'acme_process_order']));
        self::assertNotNull(Coordinator::get()->schedules()->get($record->destinationId));
    }

    public function testPendingAsRequiresExplicitFlag(): void
    {
        $as = new FakeActionSchedulerGateway();
        $as->enqueueAsync('acme_process_order', ['order_id' => 5], '');
        $manager = $this->wired($as, new FakeCronGateway());
        $manager->registerActionScheduler(new ActionSchedulerDescriptor(
            $this->origin,
            'acme_process_order',
            ProcessOrderJob::class,
            static fn (array $args): Job => new ProcessOrderJob((int) ($args['order_id'] ?? 0)),
        ));
        $this->expectException(\Fuzeo\Queue\Exceptions\InteropException::class);
        $manager->planActionScheduler('acme_process_order', ExecutionContext::singleSite());
    }

    public function testDisabledWpCronStillInspects(): void
    {
        $cron = new FakeCronGateway();
        $cron->automaticDisabled = true;
        $cron->scheduleRecurring(time() + 10, 'hourly', 'keep_me', []);
        $summary = $this->wired(new FakeActionSchedulerGateway(), $cron)->cronInspector()->summary();
        self::assertTrue($summary['automatic_spawning_disabled']);
        self::assertSame(1, $summary['events']);
    }

    public function testDiscoveryIsBounded(): void
    {
        $as = new FakeActionSchedulerGateway();
        for ($i = 0; $i < 250; $i++) {
            $as->enqueueAsync('noise_' . $i, ['n' => $i], 'other');
        }
        $page = $this->wired($as, new FakeCronGateway())->actionSchedulerCandidates(1, 1, 50, 0);
        self::assertCount(50, $page);
    }

    public function testRedactionOnHistory(): void
    {
        $cron = new FakeCronGateway();
        $cron->scheduleSingle(time() + 30, 'acme_once', [1]);
        $manager = $this->wired(new FakeActionSchedulerGateway(), $cron);
        $manager->registerCron(new CronDescriptor(
            $this->origin,
            'acme_once',
            ProcessOrderJob::class,
            static fn (array $args): Job => new ProcessOrderJob(1),
        ));
        $manager->migrate($manager->planCron('acme_once', ExecutionContext::singleSite()), true);
        $history = $manager->history(1);
        $args = $history[0]['source_snapshot']['args'] ?? [];
        self::assertIsArray($args);
        if (isset($args['api_key'])) {
            self::assertSame('[REDACTED]', $args['api_key']);
        }
    }

    public function testFakeRuntimeAssertions(): void
    {
        $fake = new FakeAsyncRuntime();
        $fake->impersonate(RuntimeName::ActionScheduler)->dispatch(new ProcessOrderJob(1));
        $fake->assertUsed(RuntimeName::ActionScheduler);
        $fake->assertDispatched(ProcessOrderJob::class);
        self::assertFalse($fake->withoutBatches()->supports('batch'));
    }

    public function testActionSchedulerAbsenceDoesNotFatal(): void
    {
        $native = new \Fuzeo\Queue\Interop\NativeActionSchedulerGateway();
        self::assertFalse($native->detected());
        self::assertSame(0, $native->count([]));
        self::assertSame([], $native->query([], 10, 0));
    }

    public function testNoGlobalInterceptionHelpers(): void
    {
        self::assertFalse(function_exists('fuzeo_queue_override_cron'));
        self::assertTrue(class_exists(\Fuzeo\Queue\Interop\Interop::class));
    }

    private function manager(): InteropManager
    {
        return Coordinator::get()->interop();
    }

    private function wired(FakeActionSchedulerGateway $as, FakeCronGateway $cron): InteropManager
    {
        $interop = new InteropManager(
            Coordinator::get(),
            Coordinator::get()->interop()->registry(),
            Coordinator::get()->interop()->store(),
            $as,
            $cron,
            Coordinator::get()->clock(),
        );
        Coordinator::get()->useInterop($interop);

        return $interop;
    }
}
