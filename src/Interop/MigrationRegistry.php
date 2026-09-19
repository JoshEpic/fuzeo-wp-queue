<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

use Fuzeo\Queue\Exceptions\DescriptorConflictException;

final class MigrationRegistry
{
    /** @var array<string, ActionSchedulerDescriptor> */
    private array $actionScheduler = [];

    /** @var array<string, CronDescriptor> */
    private array $cron = [];

    public function registerActionScheduler(ActionSchedulerDescriptor $descriptor): void
    {
        $key = $descriptor->identity()->registrationKey();
        if (isset($this->actionScheduler[$key])) {
            $existing = $this->actionScheduler[$key];
            if ($existing->origin->package !== $descriptor->origin->package) {
                throw new DescriptorConflictException(
                    'Migration descriptor conflict for Action Scheduler hook "' . $descriptor->hook
                    . '": already registered by ' . $existing->origin->package
                    . ', refused from ' . $descriptor->origin->package . '.'
                );
            }
        }
        $this->actionScheduler[$key] = $descriptor;
    }

    public function registerCron(CronDescriptor $descriptor): void
    {
        $key = $descriptor->identity()->registrationKey();
        if (isset($this->cron[$key])) {
            $existing = $this->cron[$key];
            if ($existing->origin->package !== $descriptor->origin->package) {
                throw new DescriptorConflictException(
                    'Migration descriptor conflict for WP-Cron hook "' . $descriptor->hook
                    . '": already registered by ' . $existing->origin->package
                    . ', refused from ' . $descriptor->origin->package . '.'
                );
            }
        }
        $this->cron[$key] = $descriptor;
    }

    public function actionSchedulerFor(string $hook, string $group = ''): ?ActionSchedulerDescriptor
    {
        if ($group !== '') {
            $key = SourceSystem::ActionScheduler->value . '|' . $hook . '|' . $group;
            if (isset($this->actionScheduler[$key])) {
                return $this->actionScheduler[$key];
            }
        }
        foreach ($this->actionScheduler as $descriptor) {
            if ($descriptor->hook !== $hook) {
                continue;
            }
            if ($group === '' || $descriptor->group === '' || $descriptor->group === $group) {
                return $descriptor;
            }
        }

        return null;
    }

    public function cronFor(string $hook): ?CronDescriptor
    {
        return $this->cron[SourceSystem::WpCron->value . '|' . $hook . '|'] ?? null;
    }

    /**
     * @return list<ActionSchedulerDescriptor>
     */
    public function actionSchedulerDescriptors(): array
    {
        return array_values($this->actionScheduler);
    }

    /**
     * @return list<CronDescriptor>
     */
    public function cronDescriptors(): array
    {
        return array_values($this->cron);
    }

    public function count(): int
    {
        return count($this->actionScheduler) + count($this->cron);
    }
}
