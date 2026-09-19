<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Inspection;

use Fuzeo\Queue\Jobs\Envelope;

final class JobPage
{
    /**
     * @param list<Envelope> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $limit,
        public readonly int $offset,
        public readonly ?int $total = null,
    ) {
    }
}
