<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Exceptions;

final class UniqueConflictException extends DriverException
{
    public function __construct(
        string $message,
        public readonly string $existingJobId,
        public readonly string $uniqueKey,
    ) {
        parent::__construct($message);
    }
}
