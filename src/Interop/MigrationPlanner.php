<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

use Fuzeo\Queue\Core\QueueManager;
use Fuzeo\Queue\Exceptions\InteropException;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Schedule\OccurrenceId;
use Fuzeo\Queue\Support\SecretRedactor;

final class MigrationPlanner
{
    public function __construct(
        private readonly QueueManager $manager,
        private readonly MigrationRegistry $registry,
        private readonly MigrationStore $store,
        private readonly CronInspector $cron,
        private readonly ActionSchedulerInspector $actions,
        private readonly SecretRedactor $redactor = new SecretRedactor(),
    ) {
    }

    public function planCron(string $hook, ExecutionContext $context): MigrationPlan
    {
        $descriptor = $this->registry->cronFor($hook);
        if ($descriptor === null) {
            throw new InteropException('No migration descriptor registered for WP-Cron hook "' . $hook . '".');
        }
        $event = $this->findCron($hook, $context->siteId);
        $existing = $event !== null
            ? $this->store->findBySource(SourceSystem::WpCron, $this->cronSourceId($hook, $context), $context->siteId, $context->networkId)
            : $this->store->findBySource(SourceSystem::WpCron, $this->cronSourceId($hook, $context), $context->siteId, $context->networkId);
        if ($existing !== null && in_array($existing->status, [MigrationStatus::Completed, MigrationStatus::RollbackAvailable], true)) {
            return $this->already($existing, 'WP-Cron ' . $hook);
        }
        if ($event === null) {
            throw new InteropException('WP-Cron hook "' . $hook . '" has no event on this site.');
        }
        $job = $descriptor->map($event->args, $this->manager->serializer());
        $destName = $descriptor->destinationName();
        $destId = OccurrenceId::scheduleId($destName, $descriptor->origin, $context->networkId, $context->siteId, $context->scope->value);
        $next = (new \DateTimeImmutable('@' . $event->timestamp))->setTimezone(new \DateTimeZone('UTC'));
        $snapshot = [
            'hook' => $hook,
            'timestamp' => $event->timestamp,
            'recurrence' => $event->recurrence,
            'interval' => $event->intervalSeconds,
            'args' => $event->args,
            'event_key' => $event->eventKey,
        ];

        return new MigrationPlan(
            descriptorId: $descriptor->identity()->id(),
            sourceSystem: SourceSystem::WpCron,
            sourceLabel: 'WP-Cron ' . $hook,
            sourceIdentifier: $this->cronSourceId($hook, $context),
            nextRun: $next->format(\DateTimeInterface::ATOM),
            destinationType: $event->isRecurring() ? 'schedule' : 'delayed_job',
            destinationId: $event->isRecurring() ? $destId : 'pending',
            queue: $descriptor->queue,
            payloadSummary: $this->redactor->redactMap($job->payload()),
            actions: $event->isRecurring()
                ? ['create Fuzeo schedule (disabled)', 'record destination', 'remove WP-Cron event', 'enable Fuzeo schedule', 'record completed']
                : ['dispatch Fuzeo delayed job', 'remove WP-Cron event', 'record completed'],
            rollbackAvailable: true,
            compatibility: Compatibility::DeclaredCompatible,
            sourceSnapshot: $snapshot,
            siteId: $context->siteId,
            networkId: $context->networkId,
            originPackage: $descriptor->origin->package,
            descriptorVersion: $descriptor->version,
        );
    }

    public function planActionScheduler(string $hook, ExecutionContext $context, ?string $actionId = null, bool $pending = false): MigrationPlan
    {
        $descriptor = $this->registry->actionSchedulerFor($hook);
        if ($descriptor === null) {
            throw new InteropException('No migration descriptor registered for Action Scheduler hook "' . $hook . '".');
        }
        $records = $this->actions->page($hook, $descriptor->group !== '' ? $descriptor->group : null, null, 50, 0);
        $target = null;
        foreach ($records as $row) {
            if ($actionId !== null && $row->id !== $actionId) {
                continue;
            }
            $target = $row;
            break;
        }
        if ($target === null) {
            throw new InteropException('No Action Scheduler action found for hook "' . $hook . '".');
        }
        if ($target->isInProgress()) {
            throw new InteropException('In-progress Action Scheduler actions are not eligible for migration.');
        }
        if ($target->isFailed() && !$pending) {
            throw new InteropException('Failed Action Scheduler actions are not migrated automatically. Inspect and replay explicitly.');
        }
        if ($target->isPending() && !$target->isRecurring() && !$pending && !$descriptor->migratePending) {
            throw new InteropException(
                'Pending Action Scheduler actions drain in place by default. Pass advanced pending migration explicitly.'
            );
        }
        $job = $descriptor->map($target->args, $this->manager->serializer());
        $sourceId = $target->isRecurring()
            ? 'as-recurring:' . $hook . ':' . $target->group
            : 'as-action:' . $target->id;
        $existing = $this->store->findBySource(SourceSystem::ActionScheduler, $sourceId, $context->siteId, $context->networkId);
        if ($existing !== null && in_array($existing->status, [MigrationStatus::Completed, MigrationStatus::RollbackAvailable], true)) {
            return $this->already($existing, 'Action Scheduler ' . $hook);
        }
        $destName = $descriptor->scheduleName ?? substr(preg_replace('/[^a-z0-9_-]+/i', '-', $hook) ?? $hook, 0, 64);
        $destId = $target->isRecurring()
            ? OccurrenceId::scheduleId($destName, $descriptor->origin, $context->networkId, $context->siteId, $context->scope->value)
            : 'pending';
        $warning = $target->isPending() && !$target->isRecurring()
            ? 'Bulk pending migration is advanced. Prefer drain-in-place for in-flight work.'
            : '';

        return new MigrationPlan(
            descriptorId: $descriptor->identity()->id(),
            sourceSystem: SourceSystem::ActionScheduler,
            sourceLabel: 'Action Scheduler ' . $hook . ' #' . $target->id,
            sourceIdentifier: $sourceId,
            nextRun: $target->scheduledAt?->format(\DateTimeInterface::ATOM),
            destinationType: $target->isRecurring() ? 'schedule' : 'delayed_job',
            destinationId: $destId,
            queue: $descriptor->queue,
            payloadSummary: $this->redactor->redactMap($job->payload()),
            actions: $target->isRecurring()
                ? ['create Fuzeo schedule (disabled)', 'record destination', 'unschedule AS action', 'enable Fuzeo schedule', 'record completed']
                : ['dispatch Fuzeo delayed job', 'unschedule AS action', 'record completed'],
            rollbackAvailable: $target->isRecurring(),
            compatibility: Compatibility::DeclaredCompatible,
            sourceSnapshot: [
                'id' => $target->id,
                'hook' => $target->hook,
                'group' => $target->group,
                'status' => $target->status,
                'recurrence' => $target->recurrenceSeconds,
                'scheduled_at' => $target->scheduledAt?->format(\DateTimeInterface::ATOM),
                'args' => $target->args,
            ],
            siteId: $context->siteId,
            networkId: $context->networkId,
            originPackage: $descriptor->origin->package,
            descriptorVersion: $descriptor->version,
            warning: $warning,
        );
    }

    public function classifyCron(CronEvent $event, ?MigrationRecord $history): Compatibility
    {
        if ($history !== null && in_array($history->status, [MigrationStatus::Completed, MigrationStatus::RollbackAvailable], true)) {
            return Compatibility::AlreadyMigrated;
        }
        if ($this->registry->cronFor($event->hook) !== null) {
            return Compatibility::DeclaredCompatible;
        }

        return Compatibility::Unknown;
    }

    public function classifyAction(ActionRecord $record, ?MigrationRecord $history): Compatibility
    {
        if ($record->isInProgress()) {
            return Compatibility::NotEligible;
        }
        if ($history !== null && in_array($history->status, [MigrationStatus::Completed, MigrationStatus::RollbackAvailable], true)) {
            return Compatibility::AlreadyMigrated;
        }
        if ($this->registry->actionSchedulerFor($record->hook, $record->group) !== null) {
            return Compatibility::DeclaredCompatible;
        }

        return Compatibility::Unknown;
    }

    private function findCron(string $hook, int $siteId): ?CronEvent
    {
        foreach ($this->cron->all() as $event) {
            if ($event->hook === $hook && $event->siteId === $siteId) {
                return $event;
            }
        }

        return null;
    }

    private function cronSourceId(string $hook, ExecutionContext $context): string
    {
        return 'cron:' . $hook . ':' . $context->siteId;
    }

    private function already(MigrationRecord $existing, string $label): MigrationPlan
    {
        return new MigrationPlan(
            descriptorId: $existing->descriptorId,
            sourceSystem: $existing->sourceSystem,
            sourceLabel: $label,
            sourceIdentifier: $existing->sourceIdentifier,
            nextRun: null,
            destinationType: $existing->destinationType,
            destinationId: $existing->destinationId,
            queue: (string) ($existing->metadata['queue'] ?? 'default'),
            payloadSummary: [],
            actions: ['already migrated'],
            rollbackAvailable: $existing->rollbackAvailable,
            compatibility: Compatibility::AlreadyMigrated,
            sourceSnapshot: $existing->sourceSnapshot,
            siteId: $existing->siteId,
            networkId: $existing->networkId,
            originPackage: $existing->originPackage,
            descriptorVersion: $existing->descriptorVersion,
        );
    }
}
