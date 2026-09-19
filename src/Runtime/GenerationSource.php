<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

interface GenerationSource
{
    public function current(): string;
}
