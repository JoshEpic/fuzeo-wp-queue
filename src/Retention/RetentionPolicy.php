<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Retention;

final class RetentionPolicy
{
    public function __construct(
        public readonly int $completedRetentionDays = 7,
        public readonly int $deadRetentionDays = 30,
    ) {
        if ($this->completedRetentionDays < 1 || $this->deadRetentionDays < 1) {
            throw new \InvalidArgumentException('Retention days must be at least 1.');
        }
    }

    public function completedBefore(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify('-' . $this->completedRetentionDays . ' days');
    }

    public function deadBefore(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify('-' . $this->deadRetentionDays . ' days');
    }
}
