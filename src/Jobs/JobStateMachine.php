<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

use Fuzeo\Queue\Exceptions\InvalidStateTransitionException;

/**
 * Canonical persisted states. "Running" is derived from an active reservation
 * (state = reserved and lease not expired), not stored separately.
 *
 * Valid transitions:
 *   pending   -> reserved | cancelled
 *   reserved  -> pending (release) | completed (ack) | failed | cancelled
 *   completed -> (terminal)
 *   failed    -> (terminal in Phase 1; later phases may requeue)
 *   cancelled -> (terminal)
 */
final class JobStateMachine
{
    /**
     * @var array<string, list<JobState>>
     */
    private const TRANSITIONS = [
        'pending' => [JobState::Reserved, JobState::Cancelled],
        'reserved' => [JobState::Pending, JobState::Completed, JobState::Failed, JobState::Cancelled],
        'completed' => [],
        'failed' => [],
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
}
