<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Orchestration;

final class BatchProgress
{
    public function __construct(
        public readonly BatchRecord $batch,
        public readonly bool $justFinalized,
    ) {
    }
}
