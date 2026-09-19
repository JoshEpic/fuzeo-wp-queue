<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

final class MemoryMonitor
{
    /**
     * @param (callable(): int)|null $usage
     */
    public function __construct(
        private readonly int $bootBytes,
        private readonly mixed $usage = null,
    ) {
    }

    /**
     * @return array{boot: int, current: int, peak: int, delta: int}
     */
    public function snapshot(): array
    {
        $current = $this->current();
        $peak = memory_get_peak_usage(true);

        return [
            'boot' => $this->bootBytes,
            'current' => $current,
            'peak' => $peak,
            'delta' => $current - $this->bootBytes,
        ];
    }

    public function exceeds(int $limitBytes): bool
    {
        return $this->current() >= $limitBytes;
    }

    public function current(): int
    {
        if (is_callable($this->usage)) {
            return (int) ($this->usage)();
        }

        return memory_get_usage(true);
    }
}
