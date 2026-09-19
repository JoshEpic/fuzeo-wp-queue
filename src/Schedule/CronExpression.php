<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

use Fuzeo\Queue\Exceptions\QueueException;

/**
 * Five-field cron (minute hour day-of-month month day-of-week).
 *
 * Supports *, lists, ranges, and /steps. Names JAN–DEC and SUN–SAT are accepted.
 * This is not a full crontab implementation (no @yearly aliases, no seconds field).
 */
final class CronExpression
{
    private const MONTHS = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    ];

    private const WEEKDAYS = [
        'SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6,
    ];

    /**
     * @var list<int>
     */
    public readonly array $minutes;

    /**
     * @var list<int>
     */
    public readonly array $hours;

    /**
     * @var list<int>
     */
    public readonly array $days;

    /**
     * @var list<int>
     */
    public readonly array $months;

    /**
     * @var list<int>
     */
    public readonly array $weekdays;

    public function __construct(string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($parts) !== 5) {
            throw new QueueException('Cron expressions must have five fields: minute hour day month weekday.');
        }
        $this->minutes = self::field($parts[0], 0, 59);
        $this->hours = self::field($parts[1], 0, 23);
        $this->days = self::field($parts[2], 1, 31);
        $this->months = self::field($parts[3], 1, 12, self::MONTHS);
        $this->weekdays = self::normalizeWeekdays(self::field($parts[4], 0, 7, self::WEEKDAYS));
    }

    public static function assertValid(string $expression): void
    {
        new self($expression);
    }

    public function matches(\DateTimeImmutable $local): bool
    {
        $minute = (int) $local->format('i');
        $hour = (int) $local->format('G');
        $day = (int) $local->format('j');
        $month = (int) $local->format('n');
        $weekday = (int) $local->format('w');

        if (!in_array($minute, $this->minutes, true) || !in_array($hour, $this->hours, true) || !in_array($month, $this->months, true)) {
            return false;
        }

        $dom = $this->days;
        $dow = $this->weekdays;
        $domStar = $this->isStar($dom, 1, 31);
        $dowStar = $this->isStar($dow, 0, 6);
        if ($domStar && $dowStar) {
            return true;
        }
        if (!$domStar && $dowStar) {
            return in_array($day, $dom, true);
        }
        if ($domStar && !$dowStar) {
            return in_array($weekday, $dow, true);
        }

        return in_array($day, $dom, true) || in_array($weekday, $dow, true);
    }

    /**
     * @param array<string, int> $names
     * @return list<int>
     */
    private static function field(string $raw, int $min, int $max, array $names = []): array
    {
        $values = [];
        foreach (explode(',', $raw) as $part) {
            $part = strtoupper($part);
            if ($part === '*') {
                $values = array_merge($values, range($min, $max));
                continue;
            }
            $step = 1;
            if (str_contains($part, '/')) {
                [$base, $stepRaw] = explode('/', $part, 2);
                if (!is_numeric($stepRaw) || (int) $stepRaw < 1) {
                    throw new QueueException('Invalid cron step in "' . $raw . '".');
                }
                $step = (int) $stepRaw;
                $part = $base === '' ? '*' : $base;
            }
            if ($part === '*') {
                $values = array_merge($values, self::stepped(range($min, $max), $step));
                continue;
            }
            if (str_contains($part, '-')) {
                [$a, $b] = explode('-', $part, 2);
                $start = self::atom($a, $min, $max, $names);
                $end = self::atom($b, $min, $max, $names);
                if ($start > $end) {
                    throw new QueueException('Invalid cron range in "' . $raw . '".');
                }
                $values = array_merge($values, self::stepped(range($start, $end), $step));
                continue;
            }
            $values[] = self::atom($part, $min, $max, $names);
        }
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * @param list<int> $range
     * @return list<int>
     */
    private static function stepped(array $range, int $step): array
    {
        if ($step === 1) {
            return $range;
        }
        $out = [];
        foreach ($range as $i => $value) {
            if ($i % $step === 0) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, int> $names
     */
    private static function atom(string $value, int $min, int $max, array $names): int
    {
        if (isset($names[$value])) {
            $int = $names[$value];
        } elseif (is_numeric($value)) {
            $int = (int) $value;
        } else {
            throw new QueueException('Invalid cron field "' . $value . '".');
        }
        if ($int < $min || $int > $max) {
            throw new QueueException('Cron field ' . $int . ' is out of range.');
        }

        return $int;
    }

    /**
     * @param list<int> $days
     * @return list<int>
     */
    private static function normalizeWeekdays(array $days): array
    {
        $out = [];
        foreach ($days as $day) {
            $out[] = $day === 7 ? 0 : $day;
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * @param list<int> $values
     */
    private function isStar(array $values, int $min, int $max): bool
    {
        return count($values) === ($max - $min + 1);
    }
}
