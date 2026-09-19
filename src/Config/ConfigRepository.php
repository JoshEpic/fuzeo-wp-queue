<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Config;

/**
 * Precedence (highest first):
 * 1. Explicit configure() / constructor values
 * 2. Environment variables FUZEO_QUEUE_*
 * 3. WordPress constants FUZEO_QUEUE_*
 * 4. WordPress filter fuzeo_queue_config
 * 5. Package defaults
 */
final class ConfigRepository
{
    /**
     * @param array<string, mixed> $explicit
     */
    public function resolve(array $explicit = []): Config
    {
        $config = new Config();
        $config = $config->merge($this->fromFilter());
        $config = $config->merge($this->fromConstants());
        $config = $config->merge($this->fromEnvironment());
        $config = $config->merge($explicit);

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function fromEnvironment(): array
    {
        return $this->mapPrefix(static function (string $key): ?string {
            $value = getenv($key);
            if ($value === false || $value === '') {
                return null;
            }

            return $value;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function fromConstants(): array
    {
        return $this->mapPrefix(static function (string $key): mixed {
            return defined($key) ? constant($key) : null;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function fromFilter(): array
    {
        if (!function_exists('apply_filters')) {
            return [];
        }

        $filtered = apply_filters('fuzeo_queue_config', []);
        if (!is_array($filtered)) {
            throw new \Fuzeo\Queue\Exceptions\ConfigurationException('fuzeo_queue_config filter must return an array.');
        }

        /** @var array<string, mixed> $filtered */
        return $filtered;
    }

    /**
     * @param callable(string): mixed $lookup
     * @return array<string, mixed>
     */
    private function mapPrefix(callable $lookup): array
    {
        $map = [
            'FUZEO_QUEUE_DRIVER' => 'driver',
            'FUZEO_QUEUE_DEFAULT_QUEUE' => 'default_queue',
            'FUZEO_QUEUE_MAX_PAYLOAD_BYTES' => 'max_payload_bytes',
            'FUZEO_QUEUE_MAX_PAYLOAD_DEPTH' => 'max_payload_depth',
            'FUZEO_QUEUE_DEFAULT_MAX_ATTEMPTS' => 'default_max_attempts',
            'FUZEO_QUEUE_DEFAULT_TIMEOUT_SECONDS' => 'default_timeout_seconds',
            'FUZEO_QUEUE_REDACT_PAYLOADS' => 'redact_payloads',
        ];

        $values = [];
        foreach ($map as $source => $key) {
            $value = $lookup($source);
            if ($value !== null && $value !== false) {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
