<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Core\Dispatcher;
use Fuzeo\Queue\Exceptions\UnknownJobException;
use Fuzeo\Queue\Jobs\DispatchOptions;
use Fuzeo\Queue\Jobs\JobRegistry;
use Fuzeo\Queue\Support\Ulid;
use Fuzeo\Queue\Unique\UniqueIdentity;
use Fuzeo\Queue\Unique\UniqueStore;

/**
 * Dispatches ordinary queue jobs. Does not execute handlers.
 */
final class Scheduler
{
    public function __construct(
        private readonly ScheduleStore $store,
        private readonly Dispatcher $dispatcher,
        private readonly JobRegistry $jobs,
        private readonly UniqueStore $uniques,
        private readonly Clock $clock,
        private readonly SitePresence $sites,
        private readonly ScheduleCalculator $calculator = new ScheduleCalculator(),
        private readonly int $claimLeaseSeconds = 30,
        private readonly int $maxCatchUp = 100,
        private readonly int $catchUpCutoffDays = 7,
    ) {
    }

    public function runDue(int $limit = 50): int
    {
        $now = $this->clock->now();
        $dispatched = 0;
        foreach ($this->store->due($now, $limit) as $schedule) {
            $dispatched += $this->processSchedule($schedule, $now);
        }

        return $dispatched;
    }

    public function runOne(string $scheduleId): int
    {
        $schedule = $this->store->get($scheduleId);
        if ($schedule === null || !$schedule->enabled) {
            return 0;
        }

        return $this->processSchedule($schedule, $this->clock->now());
    }

    private function processSchedule(ScheduleDefinition $schedule, \DateTimeImmutable $now): int
    {
        if ($schedule->context->scope->value === 'site' && !$this->sites->exists($schedule->context->networkId, $schedule->context->siteId)) {
            $this->store->save($schedule->withBlocked('site_deleted')->withUpdatedAt($now));

            return 0;
        }
        if (!$this->jobs->has($schedule->jobType)) {
            $this->store->save($schedule->withBlocked('origin_unavailable')->withUpdatedAt($now));

            return 0;
        }

        $intended = $schedule->nextRunAt;
        if ($intended > $now) {
            return 0;
        }

        $cutoff = $now->sub(new \DateInterval('P' . max(1, $this->catchUpCutoffDays) . 'D'));
        $nextAfterIntended = $this->calculator->nextAfter($schedule->expression, $schedule->timezone, $intended);
        $count = 0;

        if ($nextAfterIntended > $now) {
            return $this->dispatchOccurrence($schedule, $intended, $now) ? 1 : 0;
        }

        if ($schedule->catchUp === CatchUpPolicy::Skip) {
            $next = $this->calculator->nextAfter($schedule->expression, $schedule->timezone, $now);
            $this->store->save($schedule->withNextRun($next, $schedule->lastRunAt, $schedule->lastOccurrenceId, 'skipped_missed')->withUpdatedAt($now));

            return 0;
        }

        if ($schedule->catchUp === CatchUpPolicy::Latest) {
            $occurrences = $this->calculator->occurrencesThrough($schedule->expression, $schedule->timezone, $intended, $now, $this->maxCatchUp + 1);
            $latest = $occurrences === [] ? $intended : $occurrences[array_key_last($occurrences)];
            if ($latest < $cutoff) {
                $next = $this->calculator->nextAfter($schedule->expression, $schedule->timezone, $now);
                $this->store->save($schedule->withNextRun($next, $schedule->lastRunAt, $schedule->lastOccurrenceId, 'skipped_cutoff')->withUpdatedAt($now));

                return 0;
            }
            if ($this->dispatchOccurrence($schedule, $latest, $now)) {
                return 1;
            }

            return 0;
        }

        $cursor = $intended;
        while ($cursor <= $now && $count < $this->maxCatchUp) {
            if ($cursor < $cutoff) {
                $cursor = $this->calculator->nextAfter($schedule->expression, $schedule->timezone, $cursor);
                continue;
            }
            $fresh = $this->store->get($schedule->scheduleId);
            if ($fresh === null) {
                break;
            }
            if ($this->dispatchOccurrence($fresh, $cursor, $now)) {
                $count++;
            } else {
                break;
            }
            $cursor = $this->calculator->nextAfter($schedule->expression, $schedule->timezone, $cursor);
        }
        if ($cursor <= $now) {
            $fresh = $this->store->get($schedule->scheduleId);
            if ($fresh !== null) {
                $next = $this->calculator->nextAfter($schedule->expression, $schedule->timezone, $now);
                $this->store->save($fresh->withNextRun($next, $fresh->lastRunAt, $fresh->lastOccurrenceId, 'catch_up_truncated')->withUpdatedAt($now));
            }
        }

        return $count;
    }

    private function dispatchOccurrence(ScheduleDefinition $schedule, \DateTimeImmutable $intended, \DateTimeImmutable $now): bool
    {
        $occurrenceId = OccurrenceId::make($schedule->scheduleId, $intended);
        $token = Ulid::generate();
        $lease = $now->add(new \DateInterval('PT' . max(1, $this->claimLeaseSeconds) . 'S'));
        if (!$this->store->claimOccurrence($occurrenceId, $schedule->scheduleId, $intended, $token, $lease)) {
            $claim = $this->store->getClaim($occurrenceId);
            if ($claim !== null && $claim->status === 'dispatched') {
                $this->advance($schedule, $intended, $occurrenceId, $claim->jobId ?? 'duplicate', 'already_dispatched', $now);
            }

            return false;
        }

        $occIdentity = UniqueIdentity::forOccurrence($schedule->scheduleId, $intended, $schedule->origin, $schedule->context);
        $occClaim = $this->uniques->acquire($occIdentity, $occurrenceId, 1209600);
        if (!$occClaim->acquired) {
            $this->store->markDispatched($occurrenceId, $token, $occClaim->jobId);
            $this->advance($schedule, $intended, $occurrenceId, $occClaim->jobId, 'already_dispatched', $now);

            return false;
        }

        if ($schedule->overlap === OverlapPolicy::Skip) {
            $options = new DispatchOptions(
                queue: $schedule->queue,
                priority: $schedule->priority,
                context: $schedule->context,
                origin: $schedule->origin,
                uniqueKey: 'schedule-overlap:' . $schedule->scheduleId,
                metadata: [
                    '_schedule_id' => $schedule->scheduleId,
                    '_occurrence_id' => $occurrenceId,
                ],
            );
        } else {
            $options = new DispatchOptions(
                queue: $schedule->queue,
                priority: $schedule->priority,
                context: $schedule->context,
                origin: $schedule->origin,
                metadata: [
                    '_schedule_id' => $schedule->scheduleId,
                    '_occurrence_id' => $occurrenceId,
                ],
            );
        }

        try {
            $result = $this->dispatcher->dispatchRegistered($schedule->jobType, $schedule->payload, $options);
        } catch (UnknownJobException) {
            $this->uniques->release($occIdentity, $occurrenceId);
            $this->store->save($schedule->withBlocked('origin_unavailable')->withUpdatedAt($now));

            return false;
        } catch (\Fuzeo\Queue\Exceptions\DriverException) {
            $this->uniques->release($occIdentity, $occurrenceId);

            return false;
        }

        $jobId = $result->jobId();
        $this->store->markDispatched($occurrenceId, $token, $jobId);
        $this->advance($schedule, $intended, $occurrenceId, $jobId, $result->accepted ? 'dispatched' : 'duplicate_job', $now);

        return $result->accepted;
    }

    private function advance(
        ScheduleDefinition $schedule,
        \DateTimeImmutable $intended,
        string $occurrenceId,
        string $jobId,
        string $result,
        \DateTimeImmutable $now,
    ): void {
        unset($jobId);
        $next = $this->calculator->nextAfter($schedule->expression, $schedule->timezone, $intended);
        $fresh = $this->store->get($schedule->scheduleId) ?? $schedule;
        $this->store->save($fresh->withNextRun($next, $intended, $occurrenceId, $result)->withUpdatedAt($now));
    }
}
