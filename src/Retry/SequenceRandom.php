<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Retry;

final class SequenceRandom implements RandomSource
{
    /** @var list<float> */
    private array $values;

    private int $offset = 0;

    /**
     * @param list<float> $values
     */
    public function __construct(array $values)
    {
        if ($values === []) {
            throw new \InvalidArgumentException('SequenceRandom requires at least one value.');
        }
        $this->values = $values;
    }

    public function nextFloat(): float
    {
        $value = $this->values[$this->offset % count($this->values)];
        $this->offset++;

        return max(0.0, min(1.0, $value));
    }
}
