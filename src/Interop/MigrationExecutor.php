<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Core\QueueManager;
use Fuzeo\Queue\Exceptions\InteropException;
use Fuzeo\Queue\Jobs\DispatchOptions;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Operations\AuditEvent;
use Fuzeo\Queue\Schedule\CatchUpPolicy;
use Fuzeo\Queue\Schedule\OverlapPolicy;
use Fuzeo\Queue\Schedule\ScheduleDefinition;
use Fuzeo\Queue\Schedule\ScheduleExpression;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\Ulid;

final class MigrationExecutor
{
    public function __construct(
        private readonly QueueManager $manager,
        private readonly MigrationRegistry $registry,
        private readonly MigrationStore $store,
        private readonly CronGateway $cron,
        private readonly ActionSchedulerGateway $actions,
        private readonly Clock $clock,
        private readonly mixed $audit = null,
    ) {
    }

    public function execute(MigrationPlan $plan, bool $dryRun = true): MigrationRecord
    {
        if ($plan->compatibility === Compatibility::AlreadyMigrated) {
            $existing = $this->store->findBySource($plan->sourceSystem, $plan->sourceIdentifier, $plan->siteId, $plan->networkId);
            if ($existing !== null) {
                return $existing;
            }
        }
        if ($plan->compatibility !== Compatibility::DeclaredCompatible) {
            throw new InteropException('Only declared-compatible workloads may be migrated.');
        }
        $now = $this->clock->now();
        $id = Ulid::fromMaterial($plan->sourceSystem->value . '|' . $plan->sourceIdentifier . '|' . $plan->siteId, (int) $now->getTimestamp() * 1000);
        $record = new MigrationRecord(
            migrationId: $id,
            descriptorId: $plan->descriptorId,
            descriptorVersion: $plan->descriptorVersion,
            sourceSystem: $plan->sourceSystem,
            sourceIdentifier: $plan->sourceIdentifier,
            sourceSnapshot: $plan->sourceSnapshot,
            destinationType: $plan->destinationType,
            destinationId: $plan->destinationId,
            originPackage: $plan->originPackage,
            siteId: $plan->siteId,
            networkId: $plan->networkId,
            status: MigrationStatus::Planned,
            migratedAt: $now,
            rolledBackAt: null,
            metadata: ['queue' => $plan->queue, 'dry_run' => $dryRun],
            rollbackAvailable: false,
        );
        if ($dryRun) {
            $this->emit('migration.planned', $record);

            return $record;
        }
        $inserted = $this->store->insert($record);
        if (!$inserted) {
            $existing = $this->store->findBySource($plan->sourceSystem, $plan->sourceIdentifier, $plan->siteId, $plan->networkId);
            if ($existing !== null) {
                return $this->reconcile($existing);
            }
            throw new InteropException('Migration insert raced and no existing record was found.');
        }
        $this->emit('migration.started', $record);
        try {
            $record = $this->apply($plan, $record);
            $this->store->update($record);
            $this->emit('migration.completed', $record);

            return $record;
        } catch (\Throwable $exception) {
            $failed = $record->withStatus(MigrationStatus::Failed);
            $this->store->update($failed);
            $this->emit('migration.failed', $failed);
            throw new InteropException('Migration failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    public function reconcile(?MigrationRecord $record = null, ?string $migrationId = null): MigrationRecord
    {
        $record ??= $migrationId !== null ? $this->store->find($migrationId) : null;
        if ($record === null) {
            throw new InteropException('Unknown migration record.');
        }
        $destExists = $record->destinationId !== '' && $record->destinationId !== 'pending'
            && $this->manager->schedules()->get($record->destinationId) !== null;
        $sourceExists = $this->sourceExists($record);
        if ($record->status === MigrationStatus::Completed || $record->status === MigrationStatus::RollbackAvailable) {
            if ($sourceExists && $destExists) {
                $conflicted = $record->withStatus(MigrationStatus::Conflicted);
                $this->store->update($conflicted);

                return $conflicted;
            }

            return $record;
        }
        if ($record->status === MigrationStatus::Planned || $record->status === MigrationStatus::Failed) {
            if ($destExists && !$sourceExists) {
                $schedule = $this->manager->schedules()->get($record->destinationId);
                if ($schedule !== null && !$schedule->enabled) {
                    $this->manager->schedules()->enable($record->destinationId);
                }
                $done = $record->withStatus(MigrationStatus::RollbackAvailable, rollbackAvailable: true);
                $this->store->update($done);

                return $done;
            }
            if ($destExists && $sourceExists) {
                $schedule = $this->manager->schedules()->get($record->destinationId);
                if ($schedule !== null && $schedule->enabled) {
                    $this->manager->schedules()->disable($record->destinationId);
                }

                return $record;
            }
        }

        return $record;
    }

    public function rollback(string $migrationId): MigrationRecord
    {
        $record = $this->store->find($migrationId);
        if ($record === null) {
            throw new InteropException('Unknown migration ' . $migrationId . '.');
        }
        if (!$record->rollbackAvailable) {
            throw new InteropException('Rollback is not available for this migration.');
        }
        if ($record->destinationType === 'schedule' && $record->destinationId !== '') {
            $this->manager->schedules()->disable($record->destinationId);
            $this->manager->schedules()->store()->delete($record->destinationId);
        }
        $this->restoreSource($record);
        $done = $record->withStatus(MigrationStatus::RolledBack, $this->clock->now(), false);
        $this->store->update($done);
        $this->emit('migration.rolled_back', $done);

        return $done;
    }

    private function apply(MigrationPlan $plan, MigrationRecord $record): MigrationRecord
    {
        $context = ExecutionContext::site($plan->networkId, $plan->siteId === 0 ? 1 : $plan->siteId);
        if ($plan->sourceSystem === SourceSystem::WpCron) {
            return $this->applyCron($plan, $record, $context);
        }

        return $this->applyActionScheduler($plan, $record, $context);
    }

    private function applyCron(MigrationPlan $plan, MigrationRecord $record, ExecutionContext $context): MigrationRecord
    {
        $descriptor = $this->registry->cronFor((string) ($plan->sourceSnapshot['hook'] ?? ''));
        if ($descriptor === null) {
            throw new InteropException('Descriptor unavailable during migration.');
        }
        $args = $plan->sourceSnapshot['args'] ?? [];
        $job = $descriptor->map(is_array($args) ? array_values($args) : [], $this->manager->serializer());
        $timestamp = (int) ($plan->sourceSnapshot['timestamp'] ?? time());
        $when = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
        if ($plan->destinationType === 'schedule') {
            $destId = $this->createDisabledSchedule($descriptor, $job, $plan, $when, $context);
            $updated = new MigrationRecord(
                $record->migrationId,
                $record->descriptorId,
                $record->descriptorVersion,
                $record->sourceSystem,
                $record->sourceIdentifier,
                $record->sourceSnapshot,
                'schedule',
                $destId,
                $record->originPackage,
                $record->siteId,
                $record->networkId,
                MigrationStatus::Planned,
                $record->migratedAt,
                null,
                $record->metadata,
                false,
            );
            $this->store->update($updated);
            $this->removeCron($plan);
            $this->manager->schedules()->enable($destId);

            return $updated->withStatus(MigrationStatus::RollbackAvailable, rollbackAvailable: true);
        }
        $envelope = $this->manager->dispatcher()->later($when, $job);
        $this->removeCron($plan);

        return new MigrationRecord(
            $record->migrationId,
            $record->descriptorId,
            $record->descriptorVersion,
            $record->sourceSystem,
            $record->sourceIdentifier,
            $record->sourceSnapshot,
            'delayed_job',
            $envelope->jobId,
            $record->originPackage,
            $record->siteId,
            $record->networkId,
            MigrationStatus::Completed,
            $record->migratedAt,
            null,
            $record->metadata,
            false,
        );
    }

    private function applyActionScheduler(MigrationPlan $plan, MigrationRecord $record, ExecutionContext $context): MigrationRecord
    {
        $hook = (string) ($plan->sourceSnapshot['hook'] ?? '');
        $descriptor = $this->registry->actionSchedulerFor($hook, (string) ($plan->sourceSnapshot['group'] ?? ''));
        if ($descriptor === null) {
            throw new InteropException('Descriptor unavailable during Action Scheduler migration.');
        }
        $rawArgs = $plan->sourceSnapshot['args'] ?? [];
        $job = $descriptor->map(is_array($rawArgs) ? $rawArgs : [], $this->manager->serializer());
        $when = $plan->nextRun !== null
            ? new \DateTimeImmutable($plan->nextRun)
            : $this->clock->now();
        if ($plan->destinationType === 'schedule') {
            $destId = $this->createDisabledScheduleFromAs($descriptor, $job, $plan, Dates::utc($when), $context);
            $updated = new MigrationRecord(
                $record->migrationId,
                $record->descriptorId,
                $record->descriptorVersion,
                $record->sourceSystem,
                $record->sourceIdentifier,
                $record->sourceSnapshot,
                'schedule',
                $destId,
                $record->originPackage,
                $record->siteId,
                $record->networkId,
                MigrationStatus::Planned,
                $record->migratedAt,
                null,
                $record->metadata,
                false,
            );
            $this->store->update($updated);
            $this->actions->unschedule($hook, null, (string) ($plan->sourceSnapshot['group'] ?? ''));
            $this->manager->schedules()->enable($destId);

            return $updated->withStatus(MigrationStatus::RollbackAvailable, rollbackAvailable: true);
        }
        $envelope = $this->manager->dispatcher()->later($when, $job);
        $this->actions->unschedule($hook, is_array($rawArgs) ? $rawArgs : null, (string) ($plan->sourceSnapshot['group'] ?? ''));

        return new MigrationRecord(
            $record->migrationId,
            $record->descriptorId,
            $record->descriptorVersion,
            $record->sourceSystem,
            $record->sourceIdentifier,
            $record->sourceSnapshot,
            'delayed_job',
            $envelope->jobId,
            $record->originPackage,
            $record->siteId,
            $record->networkId,
            MigrationStatus::Completed,
            $record->migratedAt,
            null,
            $record->metadata,
            false,
        );
    }

    private function createDisabledSchedule(
        CronDescriptor $descriptor,
        Job $job,
        MigrationPlan $plan,
        \DateTimeImmutable $next,
        ExecutionContext $context,
    ): string {
        $expression = $this->cronExpression($descriptor, $plan);
        $pending = $this->manager->schedules()->job($descriptor->destinationName(), $job)
            ->everySeconds(max(1, $descriptor->intervalSeconds ?? 3600))
            ->catchUp(CatchUpPolicy::Skip)
            ->overlap(OverlapPolicy::Skip)
            ->onQueue($descriptor->queue)
            ->timezone($descriptor->timezone)
            ->disabled();
        if ($descriptor->cronExpression !== null) {
            $pending = $this->manager->schedules()->job($descriptor->destinationName(), $job)
                ->cron($descriptor->cronExpression)
                ->catchUp(CatchUpPolicy::Skip)
                ->overlap(OverlapPolicy::Skip)
                ->onQueue($descriptor->queue)
                ->timezone($descriptor->timezone)
                ->disabled();
        } elseif ($descriptor->dailyAt !== null) {
            $pending = $this->manager->schedules()->job($descriptor->destinationName(), $job)
                ->dailyAt($descriptor->dailyAt)
                ->catchUp(CatchUpPolicy::Skip)
                ->overlap(OverlapPolicy::Skip)
                ->onQueue($descriptor->queue)
                ->timezone($descriptor->timezone)
                ->disabled();
        }
        unset($expression);
        $definition = $pending->save();
        $saved = new ScheduleDefinition(
            scheduleId: $definition->scheduleId,
            name: $definition->name,
            origin: $definition->origin,
            jobType: $definition->jobType,
            schemaVersion: $definition->schemaVersion,
            payload: $definition->payload,
            queue: $definition->queue,
            priority: $definition->priority,
            context: $context,
            expression: $definition->expression,
            timezone: $definition->timezone,
            nextRunAt: Dates::utc($next),
            lastRunAt: null,
            lastOccurrenceId: null,
            enabled: false,
            overlap: OverlapPolicy::Skip,
            catchUp: CatchUpPolicy::Skip,
            blockedReason: null,
            metadata: array_merge($definition->metadata, ['_interop' => $plan->descriptorId]),
            createdAt: $definition->createdAt,
            updatedAt: $this->clock->now(),
        );
        $this->manager->schedules()->store()->save($saved);

        return $saved->scheduleId;
    }

    private function createDisabledScheduleFromAs(
        ActionSchedulerDescriptor $descriptor,
        Job $job,
        MigrationPlan $plan,
        \DateTimeImmutable $next,
        ExecutionContext $context,
    ): string {
        $name = $descriptor->scheduleName ?? substr(preg_replace('/[^a-z0-9_-]+/i', '-', $descriptor->hook) ?? 'migrated', 0, 64);
        $interval = $descriptor->intervalSeconds ?? (int) ($plan->sourceSnapshot['recurrence'] ?? 3600);
        $definition = $this->manager->schedules()->job($name, $job)
            ->everySeconds(max(1, $interval))
            ->catchUp(CatchUpPolicy::Skip)
            ->overlap(OverlapPolicy::Skip)
            ->onQueue($descriptor->queue)
            ->disabled()
            ->save();
        $saved = new ScheduleDefinition(
            scheduleId: $definition->scheduleId,
            name: $definition->name,
            origin: $definition->origin,
            jobType: $definition->jobType,
            schemaVersion: $definition->schemaVersion,
            payload: $definition->payload,
            queue: $definition->queue,
            priority: $definition->priority,
            context: $context,
            expression: $definition->expression,
            timezone: $definition->timezone,
            nextRunAt: $next,
            lastRunAt: null,
            lastOccurrenceId: null,
            enabled: false,
            overlap: OverlapPolicy::Skip,
            catchUp: CatchUpPolicy::Skip,
            blockedReason: null,
            metadata: array_merge($definition->metadata, ['_interop' => $plan->descriptorId]),
            createdAt: $definition->createdAt,
            updatedAt: $this->clock->now(),
        );
        $this->manager->schedules()->store()->save($saved);

        return $saved->scheduleId;
    }

    private function cronExpression(CronDescriptor $descriptor, MigrationPlan $plan): ScheduleExpression
    {
        if ($descriptor->cronExpression !== null) {
            return ScheduleExpression::cron($descriptor->cronExpression);
        }
        if ($descriptor->dailyAt !== null) {
            return ScheduleExpression::dailyAt($descriptor->dailyAt);
        }
        $seconds = $descriptor->intervalSeconds ?? (int) ($plan->sourceSnapshot['interval'] ?? 3600);

        return ScheduleExpression::interval(max(1, $seconds));
    }

    private function removeCron(MigrationPlan $plan): void
    {
        $timestamp = (int) ($plan->sourceSnapshot['timestamp'] ?? 0);
        $hook = (string) ($plan->sourceSnapshot['hook'] ?? '');
        $args = $plan->sourceSnapshot['args'] ?? [];
        $this->cron->unschedule($timestamp, $hook, is_array($args) ? array_values($args) : []);
    }

    private function sourceExists(MigrationRecord $record): bool
    {
        if ($record->sourceSystem === SourceSystem::WpCron) {
            $hook = (string) ($record->sourceSnapshot['hook'] ?? '');
            $timestamp = (int) ($record->sourceSnapshot['timestamp'] ?? 0);
            $cron = $this->cron->cronArray();

            return isset($cron[$timestamp][$hook]);
        }
        $hook = (string) ($record->sourceSnapshot['hook'] ?? '');
        $group = (string) ($record->sourceSnapshot['group'] ?? '');

        return $this->actions->count(['hook' => $hook, 'group' => $group, 'status' => 'pending']) > 0;
    }

    private function restoreSource(MigrationRecord $record): void
    {
        $hook = (string) ($record->sourceSnapshot['hook'] ?? '');
        $args = $record->sourceSnapshot['args'] ?? [];
        $timestamp = (int) ($record->sourceSnapshot['timestamp'] ?? time());
        if ($record->sourceSystem === SourceSystem::WpCron) {
            $recurrence = $record->sourceSnapshot['recurrence'] ?? false;
            if (is_string($recurrence) && $recurrence !== '') {
                $this->cron->scheduleRecurring($timestamp, $recurrence, $hook, is_array($args) ? array_values($args) : []);

                return;
            }
            $this->cron->scheduleSingle($timestamp, $hook, is_array($args) ? array_values($args) : []);

            return;
        }
        $interval = $record->sourceSnapshot['recurrence'] ?? null;
        $group = (string) ($record->sourceSnapshot['group'] ?? '');
        if (is_numeric($interval) && (int) $interval > 0) {
            $this->actions->scheduleRecurring($timestamp, (int) $interval, $hook, is_array($args) ? $args : [], $group);
        }
    }

    private function emit(string $action, MigrationRecord $record): void
    {
        if (!is_callable($this->audit)) {
            return;
        }
        ($this->audit)(new AuditEvent(
            $this->clock->now(),
            'interop',
            $action,
            0,
            'migration',
            $record->migrationId,
            $record->networkId,
            $record->siteId,
            ['status' => $record->status->value],
        ));
    }
}
