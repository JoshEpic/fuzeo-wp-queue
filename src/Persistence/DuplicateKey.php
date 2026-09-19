<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

use Fuzeo\Queue\Exceptions\DriverException;
use PDOException;

final class DuplicateKey
{
    public static function matches(\Throwable $exception): bool
    {
        $current = $exception;
        while ($current !== null) {
            if ($current instanceof PDOException) {
                $sqlState = (string) ($current->errorInfo[0] ?? $current->getCode());
                $driver = (int) ($current->errorInfo[1] ?? 0);
                if ($sqlState === '23000' || $driver === 1062) {
                    return true;
                }
            }
            $message = strtolower($current->getMessage());
            if (str_contains($message, 'duplicate') || str_contains($message, '1062')) {
                return true;
            }
            $current = $current->getPrevious();
        }

        return $exception instanceof DriverException && str_contains(strtolower($exception->getMessage()), 'duplicate');
    }
}
