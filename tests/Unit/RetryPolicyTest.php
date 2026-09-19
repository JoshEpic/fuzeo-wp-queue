<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Retry\ExponentialBackoff;
use Fuzeo\Queue\Retry\FixedBackoff;
use Fuzeo\Queue\Retry\LinearBackoff;
use Fuzeo\Queue\Retry\PercentJitter;
use Fuzeo\Queue\Retry\RetryPolicy;
use Fuzeo\Queue\Retry\SequenceBackoff;
use Fuzeo\Queue\Retry\SequenceRandom;
use Fuzeo\Queue\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function testBackoffStrategies(): void
    {
        self::assertSame(30, (new FixedBackoff(30))->delaySeconds(1));
        self::assertSame(30, (new FixedBackoff(30))->delaySeconds(4));
        self::assertSame(30, (new LinearBackoff(30))->delaySeconds(1));
        self::assertSame(60, (new LinearBackoff(30))->delaySeconds(2));
        self::assertSame(90, (new LinearBackoff(30))->delaySeconds(3));
        self::assertSame(30, (new ExponentialBackoff(30))->delaySeconds(1));
        self::assertSame(60, (new ExponentialBackoff(30))->delaySeconds(2));
        self::assertSame(120, (new ExponentialBackoff(30))->delaySeconds(3));
        self::assertSame(240, (new ExponentialBackoff(30))->delaySeconds(4));
        $seq = new SequenceBackoff([10, 30, 120]);
        self::assertSame(10, $seq->delaySeconds(1));
        self::assertSame(30, $seq->delaySeconds(2));
        self::assertSame(120, $seq->delaySeconds(3));
        self::assertSame(120, $seq->delaySeconds(9));
    }

    public function testDeterministicJitter(): void
    {
        $jitter = new PercentJitter(10, new SequenceRandom([1.0]));
        self::assertSame(66, $jitter->apply(60));
        $mid = new PercentJitter(10, new SequenceRandom([0.5]));
        self::assertSame(60, $mid->apply(60));
        $low = new PercentJitter(10, new SequenceRandom([0.0]));
        self::assertSame(54, $low->apply(60));
    }

    public function testNextAvailableUsesClockWithoutSleep(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $policy = new RetryPolicy(3, new FixedBackoff(30));
        $next = $policy->nextAvailable(1, $clock);
        self::assertSame('2026-01-01T00:00:30+00:00', $next->format(\DateTimeInterface::ATOM));
        self::assertFalse($policy->attemptsRemain(3));
        self::assertTrue($policy->attemptsRemain(2));
    }

    public function testFromArrayRoundTrip(): void
    {
        $policy = new RetryPolicy(5, new LinearBackoff(15), 10);
        $restored = RetryPolicy::fromArray($policy->toArray());
        self::assertSame(5, $restored->maxAttempts);
        self::assertSame(10, $restored->jitterPercent);
        self::assertSame(30, $restored->backoff->delaySeconds(2));
    }
}
