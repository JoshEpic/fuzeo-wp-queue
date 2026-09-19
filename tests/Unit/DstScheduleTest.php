<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Schedule\ScheduleCalculator;
use Fuzeo\Queue\Schedule\ScheduleExpression;
use PHPUnit\Framework\TestCase;

final class DstScheduleTest extends TestCase
{
    public function testSpringForwardSkipsNonexistentLocalTime(): void
    {
        $calc = new ScheduleCalculator();
        $expr = ScheduleExpression::dailyAt('02:30');
        $after = new \DateTimeImmutable('2026-03-07T00:00:00Z');
        $next = $calc->nextAfter($expr, 'America/New_York', $after);
        $local = $next->setTimezone(new \DateTimeZone('America/New_York'));
        self::assertSame('2026-03-07', $local->format('Y-m-d'));
        self::assertSame('02:30', $local->format('H:i'));

        $afterSunday = new \DateTimeImmutable('2026-03-08T00:00:00Z');
        $spring = $calc->nextAfter($expr, 'America/New_York', $afterSunday);
        $springLocal = $spring->setTimezone(new \DateTimeZone('America/New_York'));
        self::assertSame('2026-03-09', $springLocal->format('Y-m-d'));
        self::assertSame('02:30', $springLocal->format('H:i'));
    }

    public function testFallBackFiresOnceAtEarlierOffset(): void
    {
        $calc = new ScheduleCalculator();
        $expr = ScheduleExpression::dailyAt('01:30');
        $before = new \DateTimeImmutable('2026-11-01T04:00:00Z');
        $first = $calc->nextAfter($expr, 'America/New_York', $before);
        $second = $calc->nextAfter($expr, 'America/New_York', $first);
        $tz = new \DateTimeZone('America/New_York');
        self::assertSame('01:30', $first->setTimezone($tz)->format('H:i'));
        self::assertSame('2026-11-02', $second->setTimezone($tz)->format('Y-m-d'));
        self::assertGreaterThan(3600, $second->getTimestamp() - $first->getTimestamp());
    }

    public function testUtcIntervalIgnoresProcessTimezone(): void
    {
        $prev = date_default_timezone_get();
        date_default_timezone_set('America/Los_Angeles');
        try {
            $calc = new ScheduleCalculator();
            $after = new \DateTimeImmutable('2026-01-01T00:00:00Z');
            $next = $calc->nextAfter(ScheduleExpression::interval(3600), 'UTC', $after);
            self::assertSame('2026-01-01T01:00:00+00:00', $next->format(\DateTimeInterface::ATOM));
        } finally {
            date_default_timezone_set($prev);
        }
    }

    public function testNonDstTimezone(): void
    {
        $calc = new ScheduleCalculator();
        $expr = ScheduleExpression::dailyAt('02:30');
        $after = new \DateTimeImmutable('2026-03-08T00:00:00Z');
        $next = $calc->nextAfter($expr, 'UTC', $after);
        self::assertSame('2026-03-08T02:30:00+00:00', $next->format(\DateTimeInterface::ATOM));
    }
}
