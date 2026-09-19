<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

final class FakeCronGateway implements CronGateway
{
    /** @var array<int, array<string, array<string, array<string, mixed>>>> */
    private array $cron = [];

    /** @var array<string, array<string, mixed>> */
    private array $schedules = [
        'hourly' => ['interval' => 3600, 'display' => 'Once Hourly'],
        'twicedaily' => ['interval' => 43200, 'display' => 'Twice Daily'],
        'daily' => ['interval' => 86400, 'display' => 'Once Daily'],
        'every_5_minutes' => ['interval' => 300, 'display' => 'Every 5 minutes'],
    ];

    public bool $automaticDisabled = false;

    public function eventsPresent(): bool
    {
        return true;
    }

    public function automaticSpawningDisabled(): bool
    {
        return $this->automaticDisabled;
    }

    public function cronArray(): array
    {
        return $this->cron;
    }

    public function schedules(): array
    {
        return $this->schedules;
    }

    public function unschedule(int $timestamp, string $hook, array $args): bool
    {
        if (!isset($this->cron[$timestamp][$hook])) {
            return false;
        }
        foreach ($this->cron[$timestamp][$hook] as $key => $event) {
            $eventArgs = is_array($event['args'] ?? null) ? $event['args'] : [];
            if ($eventArgs === $args) {
                unset($this->cron[$timestamp][$hook][$key]);
                if ($this->cron[$timestamp][$hook] === []) {
                    unset($this->cron[$timestamp][$hook]);
                }
                if (($this->cron[$timestamp] ?? []) === []) {
                    unset($this->cron[$timestamp]);
                }

                return true;
            }
        }

        return false;
    }

    public function scheduleSingle(int $timestamp, string $hook, array $args): bool
    {
        $this->cron[$timestamp][$hook][md5(json_encode($args) ?: '')] = [
            'schedule' => false,
            'args' => $args,
        ];

        return true;
    }

    public function scheduleRecurring(int $timestamp, string $recurrence, string $hook, array $args): bool
    {
        $interval = (int) ($this->schedules[$recurrence]['interval'] ?? 0);
        $this->cron[$timestamp][$hook][md5(json_encode($args) ?: '')] = [
            'schedule' => $recurrence,
            'args' => $args,
            'interval' => $interval,
        ];

        return true;
    }

    public function registerSchedule(string $slug, int $interval): void
    {
        $this->schedules[$slug] = ['interval' => $interval, 'display' => $slug];
    }
}
