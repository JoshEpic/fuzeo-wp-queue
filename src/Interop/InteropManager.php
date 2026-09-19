<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Core\QueueManager;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Operations\AuditEvent;
use Fuzeo\Queue\Support\SecretRedactor;
use Fuzeo\Queue\Support\SystemClock;

final class InteropManager
{
    /** @var array<string, mixed>|null */
    private ?array $asCache = null;

    private int $asCacheAt = 0;

    private ?FakeAsyncRuntime $fake = null;

    public function __construct(
        private readonly QueueManager $manager,
        private readonly MigrationRegistry $registry,
        private readonly MigrationStore $store,
        private readonly ActionSchedulerGateway $actions,
        private readonly CronGateway $cron,
        private readonly Clock $clock = new SystemClock(),
        private readonly SecretRedactor $redactor = new SecretRedactor(),
    ) {
    }

    public static function fromManager(QueueManager $manager, MigrationStore $store, ?ActionSchedulerGateway $actions = null, ?CronGateway $cron = null): self
    {
        return new self(
            $manager,
            new MigrationRegistry(),
            $store,
            $actions ?? new NativeActionSchedulerGateway(),
            $cron ?? new WordPressCronGateway(),
            $manager->clock(),
        );
    }

    public function registerActionScheduler(ActionSchedulerDescriptor $descriptor): void
    {
        $this->registry->registerActionScheduler($descriptor);
    }

    public function registerCron(CronDescriptor $descriptor): void
    {
        $this->registry->registerCron($descriptor);
    }

    public function registry(): MigrationRegistry
    {
        return $this->registry;
    }

    public function store(): MigrationStore
    {
        return $this->store;
    }

    public function fake(): FakeAsyncRuntime
    {
        return $this->fake ??= new FakeAsyncRuntime();
    }

    public function clearFake(): void
    {
        $this->fake = null;
    }

    public function resolver(Origin $origin, ?ActionSchedulerGateway $actions = null): RuntimeResolver
    {
        return new RuntimeResolver($this->manager, $actions ?? $this->actions, $origin);
    }

    public function runtime(Origin $origin, RuntimePolicy $policy = RuntimePolicy::PreferQueue): AsyncRuntime
    {
        if ($this->fake !== null) {
            return $this->fake;
        }

        return $this->resolver($origin)->resolve($policy);
    }

    public function selection(Origin $origin, RuntimePolicy $policy = RuntimePolicy::PreferQueue): RuntimeSelection
    {
        return $this->resolver($origin)->select($policy);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(Origin $origin, int $siteId = 1, int $networkId = 1): array
    {
        $selection = $this->selection($origin);
        $as = $this->actionSchedulerSummary();
        $cron = $this->cronInspector($siteId, $networkId)->summary();
        $legacy = (new ActionSchedulerInspector($this->actions, $siteId, $networkId))
            ->ownedPending(FallbackEnvelope::groupFor($origin));

        return [
            'preferred_runtime' => $selection->preferred->value,
            'active_runtime' => $selection->active->value,
            'fallback' => $selection->fallback?->value,
            'reason' => $selection->reason,
            'queue_healthy' => $selection->queueHealthy,
            'queue_available' => $selection->queueAvailable,
            'legacy_runtime' => RuntimeName::ActionScheduler->value,
            'legacy_pending' => $legacy,
            'fully_transitioned' => $selection->active === RuntimeName::FuzeoQueue && $legacy === 0,
            'action_scheduler' => $as,
            'wp_cron' => $cron,
            'descriptors' => $this->registry->count(),
            'automatic_cron_spawning_disabled' => (bool) ($cron['automatic_spawning_disabled'] ?? false),
            'capabilities' => $this->runtime($origin)->capabilities()->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function actionSchedulerSummary(): array
    {
        $now = time();
        if ($this->asCache !== null && ($now - $this->asCacheAt) < 30) {
            return $this->asCache;
        }
        $summary = (new ActionSchedulerInspector($this->actions))->summary();
        $this->asCache = $summary;
        $this->asCacheAt = $now;

        return $summary;
    }

    public function cronInspector(int $siteId = 1, int $networkId = 1): CronInspector
    {
        return new CronInspector($this->cron, $siteId, $networkId);
    }

    public function actionInspector(int $siteId = 1, int $networkId = 1): ActionSchedulerInspector
    {
        return new ActionSchedulerInspector($this->actions, $siteId, $networkId);
    }

    public function planner(int $siteId = 1, int $networkId = 1): MigrationPlanner
    {
        return new MigrationPlanner(
            $this->manager,
            $this->registry,
            $this->store,
            $this->cronInspector($siteId, $networkId),
            $this->actionInspector($siteId, $networkId),
            $this->redactor,
        );
    }

    public function executor(): MigrationExecutor
    {
        $audit = function (AuditEvent $event): void {
            try {
                $this->manager->operations();
                $this->manager->metrics()->increment(
                    $event->action === 'migration.completed' ? 'interop_migrations_completed' : (
                        $event->action === 'migration.failed' ? 'interop_migrations_failed' : 'interop_migrations_events'
                    )
                );
            } catch (\Throwable) {
            }
        };

        return new MigrationExecutor(
            $this->manager,
            $this->registry,
            $this->store,
            $this->cron,
            $this->actions,
            $this->clock,
            $audit,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cronCandidates(int $siteId, int $networkId): array
    {
        $planner = $this->planner($siteId, $networkId);
        $out = [];
        foreach ($this->cronInspector($siteId, $networkId)->page(50, 0) as $event) {
            $history = $this->store->findBySource(SourceSystem::WpCron, 'cron:' . $event->hook . ':' . $siteId, $siteId, $networkId);
            $compat = $planner->classifyCron($event, $history);
            $out[] = [
                'hook' => $event->hook,
                'timestamp' => $event->timestamp,
                'recurrence' => $event->recurrence,
                'compatibility' => $compat->value,
                'migratable' => $compat === Compatibility::DeclaredCompatible,
                'site_id' => $event->siteId,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function actionSchedulerCandidates(int $siteId, int $networkId, int $limit = 50, int $offset = 0): array
    {
        $planner = $this->planner($siteId, $networkId);
        $out = [];
        foreach ($this->actionInspector($siteId, $networkId)->page(null, null, null, $limit, $offset) as $record) {
            $history = $this->store->findBySource(
                SourceSystem::ActionScheduler,
                $record->isRecurring() ? 'as-recurring:' . $record->hook . ':' . $record->group : 'as-action:' . $record->id,
                $siteId,
                $networkId
            );
            $compat = $planner->classifyAction($record, $history);
            $out[] = [
                'id' => $record->id,
                'hook' => $record->hook,
                'group' => $record->group,
                'status' => $record->status,
                'compatibility' => $compat->value,
                'migratable' => $compat === Compatibility::DeclaredCompatible && !$record->isInProgress(),
                'recurring' => $record->isRecurring(),
                'in_progress' => $record->isInProgress(),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $siteId, int $limit = 50, int $offset = 0): array
    {
        $rows = [];
        foreach ($this->store->list($siteId, $limit, $offset) as $record) {
            $row = $record->toArray();
            $snapshot = is_array($row['source_snapshot'] ?? null) ? $row['source_snapshot'] : [];
            if (isset($snapshot['args']) && is_array($snapshot['args'])) {
                $snapshot['args'] = $this->redactor->redactMap($snapshot['args']);
            }
            $row['source_snapshot'] = $snapshot;
            $rows[] = $row;
        }

        return $rows;
    }

    public function planCron(string $hook, ExecutionContext $context): MigrationPlan
    {
        return $this->planner($context->siteId, $context->networkId)->planCron($hook, $context);
    }

    public function planActionScheduler(string $hook, ExecutionContext $context, ?string $actionId = null, bool $pending = false): MigrationPlan
    {
        return $this->planner($context->siteId, $context->networkId)->planActionScheduler($hook, $context, $actionId, $pending);
    }

    public function migrate(MigrationPlan $plan, bool $execute = false): MigrationRecord
    {
        return $this->executor()->execute($plan, dryRun: !$execute);
    }

    public function rollback(string $migrationId): MigrationRecord
    {
        return $this->executor()->rollback($migrationId);
    }

    public function reconcile(string $migrationId): MigrationRecord
    {
        return $this->executor()->reconcile(migrationId: $migrationId);
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $as = $this->actionSchedulerSummary();

        return [
            'fuzeo_queue_runtime' => \Fuzeo\Queue\Runtime\Coordinator::isBooted() ? 'booted' : 'absent',
            'action_scheduler_detected' => (bool) ($as['detected'] ?? false),
            'action_scheduler_version' => $as['version'] ?? null,
            'action_scheduler_healthy' => (bool) ($as['healthy'] ?? false),
            'wp_cron_detected' => $this->cron->eventsPresent(),
            'wp_cron_automatic_spawning_disabled' => $this->cron->automaticSpawningDisabled(),
            'registered_migration_descriptors' => $this->registry->count(),
            'legacy_as_pending' => (int) ($as['pending'] ?? 0),
            'legacy_cron_events' => (int) ($this->cronInspector()->summary()['events'] ?? 0),
        ];
    }
}
