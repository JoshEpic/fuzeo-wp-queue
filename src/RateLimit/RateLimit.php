<?php

declare(strict_types=1);

namespace Fuzeo\Queue\RateLimit;

use Fuzeo\Queue\Exceptions\ConfigurationException;

/**
 * Token-bucket rate limit. Burst equals capacity; refill is steady.
 *
 * perSecond(5) allows a burst of 5 then 5/sec.
 * perMinute(100) is capacity 100 refilling 100/60 per second.
 */
final class RateLimit
{
    private const KEY_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}$/';

    public function __construct(
        public readonly string $key,
        public readonly int $capacity,
        public readonly float $refillPerSecond,
    ) {
        if (preg_match(self::KEY_PATTERN, $this->key) !== 1) {
            throw new ConfigurationException(
                'Invalid rate-limit key "' . $this->key . '". Use a short identifier such as remote-api.'
            );
        }
        if ($this->capacity < 1) {
            throw new ConfigurationException('Rate-limit capacity must be at least 1.');
        }
        if ($this->refillPerSecond <= 0) {
            throw new ConfigurationException('Rate-limit refill must be positive.');
        }
    }

    public static function perSecond(int $n, string $key = 'default'): self
    {
        return new self($key, $n, (float) $n);
    }

    public static function perMinute(int $n, string $key = 'default'): self
    {
        return new self($key, $n, $n / 60.0);
    }

    public function withKey(string $key): self
    {
        return new self($key, $this->capacity, $this->refillPerSecond);
    }

    /**
     * @return array{key: string, capacity: int, refill_per_second: float}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'capacity' => $this->capacity,
            'refill_per_second' => $this->refillPerSecond,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $key = isset($data['key']) && is_string($data['key']) ? $data['key'] : 'default';
        if (isset($data['per_minute']) && is_numeric($data['per_minute'])) {
            return self::perMinute((int) $data['per_minute'], $key);
        }
        if (isset($data['per_second']) && is_numeric($data['per_second'])) {
            return self::perSecond((int) $data['per_second'], $key);
        }
        $capacity = isset($data['capacity']) && is_numeric($data['capacity']) ? (int) $data['capacity'] : 1;
        $refill = isset($data['refill_per_second']) && is_numeric($data['refill_per_second'])
            ? (float) $data['refill_per_second']
            : 1.0;

        return new self($key, $capacity, $refill);
    }
}
