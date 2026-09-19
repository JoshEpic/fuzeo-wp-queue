<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

use Fuzeo\Queue\Exceptions\InvalidStateTransitionException;

/**
 * Canonical persisted states. "Running" is derived from an active reservation.
 *
 * pending   -> reserved | cancelled
 * reserved  -> pending (retry/release) | completed | dead | failed | cancelled
 * dead      -> pending (manual retry)
 * failed    -> pending (manual retry of legacy terminal rows)
 * completed -> (terminal)
 * cancelled -> (terminal)
 *
 * `failed` remains for Phase 2 driver.fail() compatibility. New terminal
 * outcomes use `dead`. Neither is auto-reserved.
 */
final class JobStateMachine
{
    /**
     * @var array<string, list<JobState>>
     */
    private const TRANSITIONS = [
        'pending' => [JobState::Reserved, JobState::Cancelled, JobState::Dead],
        'reserved' => [
            JobState::Pending,
            JobState::Completed,
            JobState::Failed,
            JobState::Dead,
            JobState::Cancelled,
        ],
        'completed' => [],
        'failed' => [JobState::Pending],
        'dead' => [JobState::Pending],
        'cancelled' => [],
    ];

    public static function canTransition(JobState $from, JobState $to): bool
    {
        if ($from === $to && $from === JobState::Reserved) {
            return true;
        }

        foreach (self::TRANSITIONS[$from->value] as $allowed) {
            if ($allowed === $to) {
                return true;
            }
        }

        return false;
    }

    public static function transition(JobState $from, JobState $to): JobState
    {
        if (!self::canTransition($from, $to)) {
            throw new InvalidStateTransitionException(
                'Cannot transition job from ' . $from->value . ' to ' . $to->value . '.'
            );
        }

        return $to;
    }

    /**
     * @return list<JobState>
     */
    public static function allowedFrom(JobState $from): array
    {
        $allowed = self::TRANSITIONS[$from->value];
        if ($from === JobState::Reserved) {
            $allowed[] = JobState::Reserved;
        }

        return $allowed;
    }

    public static function isTerminalStopped(JobState $state): bool
    {
        return $state === JobState::Dead || $state === JobState::Failed;
    }
}
