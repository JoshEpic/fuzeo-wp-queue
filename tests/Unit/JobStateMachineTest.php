<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Exceptions\InvalidStateTransitionException;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\JobStateMachine;
use PHPUnit\Framework\TestCase;

final class JobStateMachineTest extends TestCase
{
    /**
     * @dataProvider valid
     */
    public function testValidTransitions(JobState $from, JobState $to): void
    {
        self::assertTrue(JobStateMachine::canTransition($from, $to));
        self::assertSame($to, JobStateMachine::transition($from, $to));
    }

    /**
     * @return list<array{0: JobState, 1: JobState}>
     */
    public static function valid(): array
    {
        return [
            [JobState::Pending, JobState::Reserved],
            [JobState::Pending, JobState::Cancelled],
            [JobState::Reserved, JobState::Pending],
            [JobState::Reserved, JobState::Completed],
            [JobState::Reserved, JobState::Failed],
            [JobState::Reserved, JobState::Cancelled],
            [JobState::Reserved, JobState::Reserved],
            [JobState::Pending, JobState::Dead],
            [JobState::Reserved, JobState::Dead],
            [JobState::Dead, JobState::Pending],
            [JobState::Failed, JobState::Pending],
        ];
    }

    /**
     * @dataProvider invalid
     */
    public function testInvalidTransitions(JobState $from, JobState $to): void
    {
        self::assertFalse(JobStateMachine::canTransition($from, $to));
        $this->expectException(InvalidStateTransitionException::class);
        JobStateMachine::transition($from, $to);
    }

    /**
     * @return list<array{0: JobState, 1: JobState}>
     */
    public static function invalid(): array
    {
        return [
            [JobState::Pending, JobState::Completed],
            [JobState::Pending, JobState::Failed],
            [JobState::Completed, JobState::Pending],
            [JobState::Cancelled, JobState::Pending],
            [JobState::Completed, JobState::Reserved],
            [JobState::Dead, JobState::Reserved],
        ];
    }
}
