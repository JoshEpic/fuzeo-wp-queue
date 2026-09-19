<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

use Fuzeo\Queue\Exceptions\QueueException;
use Fuzeo\Queue\Support\Dates;

/**
 * Next-run math in a named timezone, persisted as UTC.
 *
 * DST:
 * - Spring-forward gap: a local time that does not exist is skipped (next valid matching instant).
 * - Fall-back overlap: a local time that occurs twice fires once, at the earlier offset.
 */
final class ScheduleCalculator
{
    public function nextAfter(ScheduleExpression $expression, string $timezone, \DateTimeImmutable $afterUtc): \DateTimeImmutable
    {
        $tz = $this->timezone($timezone);
        $afterUtc = Dates::utc($afterUtc);

        return match ($expression->type) {
            ScheduleExpressionType::Interval => $afterUtc->add(new \DateInterval('PT' . $expression->seconds() . 'S')),
            ScheduleExpressionType::Daily => $this->nextDaily($expression->value, $tz, $afterUtc),
            ScheduleExpressionType::Cron => $this->nextCron(new CronExpression($expression->value), $tz, $afterUtc),
        };
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    public function occurrencesThrough(
        ScheduleExpression $expression,
        string $timezone,
        \DateTimeImmutable $fromInclusive,
        \DateTimeImmutable $untilInclusive,
        int $limit,
    ): array {
        $out = [];
        $cursor = Dates::utc($fromInclusive);
        $until = Dates::utc($untilInclusive);
        if ($cursor > $until) {
            return [];
        }
        $out[] = $cursor;
        while (count($out) < $limit) {
            $next = $this->nextAfter($expression, $timezone, $cursor);
            if ($next > $until) {
                break;
            }
            $out[] = $next;
            $cursor = $next;
        }

        return $out;
    }

    public function timezone(string $name): \DateTimeZone
    {
        try {
            return new \DateTimeZone($name);
        } catch (\Exception $exception) {
            throw new QueueException('Unknown schedule timezone "' . $name . '".', 0, $exception);
        }
    }

    private function nextDaily(string $time, \DateTimeZone $tz, \DateTimeImmutable $afterUtc): \DateTimeImmutable
    {
        $local = $afterUtc->setTimezone($tz);
        $parts = explode(':', $time);
        $hour = (int) $parts[0];
        $minute = (int) $parts[1];
        for ($day = 0; $day <= 400; $day++) {
            $date = $local->modify('+' . $day . ' day');
            $candidate = $this->localWallTime($date, $tz, $hour, $minute);
            if ($candidate === null) {
                continue;
            }
            if ($candidate > $afterUtc) {
                return $candidate;
            }
        }

        throw new QueueException('Unable to compute the next daily run time.');
    }

    private function nextCron(CronExpression $cron, \DateTimeZone $tz, \DateTimeImmutable $afterUtc): \DateTimeImmutable
    {
        $local = $afterUtc->setTimezone($tz);
        $cursor = $local->modify('+1 minute')->setTime(
            (int) $local->modify('+1 minute')->format('G'),
            (int) $local->modify('+1 minute')->format('i'),
            0
        );
        $guard = 0;
        while ($guard < 366 * 24 * 60) {
            $guard++;
            $resolved = $this->resolveLocal($cursor, $tz);
            if ($resolved !== null && $cron->matches($cursor) && $resolved > $afterUtc) {
                return $resolved;
            }
            $cursor = $cursor->modify('+1 minute');
        }

        throw new QueueException('Unable to compute the next cron run time.');
    }

    private function localWallTime(\DateTimeImmutable $day, \DateTimeZone $tz, int $hour, int $minute): ?\DateTimeImmutable
    {
        $label = $day->format('Y-m-d') . sprintf(' %02d:%02d:00', $hour, $minute);

        return $this->parseWall($label, $tz);
    }

    /**
     * Returns UTC instant for a local civil time, skipping gaps and using the earlier offset on folds.
     */
    private function parseWall(string $label, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $first = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $label, $tz);
        if ($first === false) {
            return null;
        }
        if ($first->format('Y-m-d H:i:s') !== $label) {
            return null;
        }
        $utc = Dates::utc($first);
        $roundTrip = $utc->setTimezone($tz)->format('Y-m-d H:i:s');
        if ($roundTrip !== $label) {
            return null;
        }

        $later = $first->modify('+1 hour');
        if ($later->format('Y-m-d H:i:s') === $label) {
            return Dates::utc($first);
        }
        $earlier = $first->modify('-1 hour');
        if ($earlier->format('Y-m-d H:i:s') === $label && Dates::utc($earlier) < $utc) {
            return Dates::utc($earlier);
        }

        return $utc;
    }

    private function resolveLocal(\DateTimeImmutable $local, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        return $this->parseWall($local->format('Y-m-d H:i:s'), $tz);
    }
}
