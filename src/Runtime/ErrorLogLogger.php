<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

final class ErrorLogLogger implements RuntimeLogger
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function log(string $event, array $context = []): void
    {
        $safe = ['event' => $event];
        foreach ($context as $key => $value) {
            if (!is_string($key) || $key === '' || str_contains(strtolower($key), 'secret') || str_contains(strtolower($key), 'password') || str_contains(strtolower($key), 'dsn')) {
                continue;
            }
            $safe[$key] = $value;
        }
        $encoded = json_encode($safe, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return;
        }
        error_log('fuzeo-queue ' . $encoded);
    }
}
