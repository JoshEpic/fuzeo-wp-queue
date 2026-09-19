<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

final class CronInspector
{
    public const PAGE_MAX = 100;

    public function __construct(
        private readonly CronGateway $gateway,
        private readonly int $siteId = 1,
        private readonly int $networkId = 1,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $events = $this->all();
        $recurring = 0;
        $single = 0;
        foreach ($events as $event) {
            if ($event->isRecurring()) {
                $recurring++;
            } else {
                $single++;
            }
        }

        return [
            'detected' => $this->gateway->eventsPresent(),
            'automatic_spawning_disabled' => $this->gateway->automaticSpawningDisabled(),
            'events' => count($events),
            'recurring' => $recurring,
            'single' => $single,
        ];
    }

    /**
     * @return list<CronEvent>
     */
    public function page(int $limit = 50, int $offset = 0): array
    {
        return array_slice($this->all(), max(0, $offset), min(self::PAGE_MAX, max(1, $limit)));
    }

    /**
     * @return list<CronEvent>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->gateway->cronArray() as $timestamp => $hooks) {
            if (!is_array($hooks)) {
                continue;
            }
            foreach ($hooks as $hook => $events) {
                if (!is_array($events)) {
                    continue;
                }
                foreach ($events as $key => $event) {
                    if (!is_array($event)) {
                        continue;
                    }
                    $args = $event['args'] ?? [];
                    if (!is_array($args)) {
                        $args = [];
                    }
                    $schedule = $event['schedule'] ?? false;
                    $interval = isset($event['interval']) && is_numeric($event['interval']) ? (int) $event['interval'] : null;
                    $out[] = new CronEvent(
                        hook: (string) $hook,
                        timestamp: (int) $timestamp,
                        args: array_values($args),
                        recurrence: is_string($schedule) ? $schedule : false,
                        intervalSeconds: $interval,
                        siteId: $this->siteId,
                        networkId: $this->networkId,
                        eventKey: (string) $key,
                    );
                }
            }
        }
        usort($out, static fn (CronEvent $a, CronEvent $b): int => $a->timestamp <=> $b->timestamp);

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function schedules(): array
    {
        return $this->gateway->schedules();
    }
}
