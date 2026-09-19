<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Exceptions;

final class RetryAfterException extends RetryableException
{
    public function __construct(
        public readonly int $seconds,
        string $message = 'Retry after the requested delay.',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        if ($this->seconds < 0) {
            throw new \InvalidArgumentException('Retry delay cannot be negative.');
        }
        parent::__construct($message, $code, $previous);
    }

    public static function after(int $seconds, string $message = 'Retry after the requested delay.'): self
    {
        return new self($seconds, $message);
    }
}
