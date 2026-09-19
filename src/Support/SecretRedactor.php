<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Support;

final class SecretRedactor
{
    /**
     * @var list<string>
     */
    public const DEFAULT_KEYS = [
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'bearer',
        'cookie',
        'access_token',
        'refresh_token',
        'client_secret',
        'private_key',
    ];

    /** @var list<string> */
    private array $keys;

    /**
     * @param list<string> $extraKeys
     */
    public function __construct(array $extraKeys = [])
    {
        $merged = array_merge(self::DEFAULT_KEYS, $extraKeys);
        $normalized = [];
        foreach ($merged as $key) {
            $normalized[] = strtolower($key);
        }
        $this->keys = array_values(array_unique($normalized));
    }

    public function redactMessage(string $message): string
    {
        $out = preg_replace('/(password|secret|token|api[_-]?key|authorization|bearer)\s*[:=]\s*\S+/i', '$1=[REDACTED]', $message);
        if (!is_string($out)) {
            return '[redacted]';
        }
        if (strlen($out) > 1024) {
            return substr($out, 0, 1021) . '...';
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function redactMap(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $name = is_string($key) ? $key : (string) $key;
            if ($this->isSensitive($name)) {
                $out[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $out[$key] = $this->redactMap($value);
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    public function isSensitive(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));
        if (in_array($normalized, $this->keys, true)) {
            return true;
        }

        foreach (['password', 'passwd', 'secret', 'token', 'authorization', 'cookie', 'private_key', 'api_key', 'apikey'] as $needle) {
            if ($normalized === $needle || str_ends_with($normalized, '_' . $needle)) {
                return true;
            }
        }

        return false;
    }
}
