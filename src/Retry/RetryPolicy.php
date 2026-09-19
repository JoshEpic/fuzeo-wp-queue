<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Retry;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Support\SystemClock;

/**
 * Declarative retry policy persisted in envelope metadata[_retry].
 */
final class RetryPolicy
{
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly Backoff $backoff = new ExponentialBackoff(30),
        public readonly int $jitterPercent = 0,
    ) {
        if ($this->maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be at least 1.');
        }
        if ($this->jitterPercent < 0 || $this->jitterPercent > 100) {
            throw new \InvalidArgumentException('jitterPercent must be 0-100.');
        }
    }

    public function attemptsRemain(int $currentAttempt): bool
    {
        return $currentAttempt < $this->maxAttempts;
    }

    public function nextAvailable(
        int $failedAttempt,
        Clock $clock = new SystemClock(),
        ?int $overrideSeconds = null,
        RandomSource $random = new SystemRandom(),
    ): \DateTimeImmutable {
        $delay = $overrideSeconds ?? $this->backoff->delaySeconds($failedAttempt);
        if ($this->jitterPercent > 0 && $overrideSeconds === null) {
            $delay = (new PercentJitter($this->jitterPercent, $random))->apply($delay);
        }

        return $clock->now()->add(new \DateInterval('PT' . max(0, $delay) . 'S'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'max_attempts' => $this->maxAttempts,
            'backoff' => $this->backoff->toArray(),
            'jitter_percent' => $this->jitterPercent,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, int $fallbackMaxAttempts = 3): self
    {
        $max = isset($data['max_attempts']) && is_int($data['max_attempts'])
            ? $data['max_attempts']
            : $fallbackMaxAttempts;
        $jitter = isset($data['jitter_percent']) && is_int($data['jitter_percent'])
            ? $data['jitter_percent']
            : 0;
        $backoffData = $data['backoff'] ?? null;
        $backoff = is_array($backoffData) ? self::backoffFromArray($backoffData) : new ExponentialBackoff(30);

        return new self($max, $backoff, $jitter);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function backoffFromArray(array $data): Backoff
    {
        $type = $data['type'] ?? 'exponential';
        if (!is_string($type)) {
            $type = 'exponential';
        }

        return match ($type) {
            'fixed' => new FixedBackoff(self::intVal($data, 'seconds', 30)),
            'linear' => new LinearBackoff(self::intVal($data, 'seconds', 30)),
            'sequence' => new SequenceBackoff(self::intList($data['seconds'] ?? [30])),
            default => new ExponentialBackoff(
                self::intVal($data, 'seconds', 30),
                self::intVal($data, 'cap_seconds', 3600),
            ),
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function intVal(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? $default;

        return is_int($value) ? $value : $default;
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    private static function intList(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return [30];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_int($item) && $item >= 0) {
                $out[] = $item;
            }
        }

        return $out === [] ? [30] : $out;
    }
}
