<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

use Fuzeo\Queue\Jobs\Origin;

final class ConsumerRegistry
{
    /** @var array<string, Origin> */
    private array $consumers = [];

    public function register(Origin $origin): Origin
    {
        $this->consumers[$origin->package] = $origin;

        return $origin;
    }

    public function has(string $package): bool
    {
        return isset($this->consumers[$package]);
    }

    /**
     * @return array<string, Origin>
     */
    public function all(): array
    {
        ksort($this->consumers);

        return $this->consumers;
    }

    public function count(): int
    {
        return count($this->consumers);
    }
}
