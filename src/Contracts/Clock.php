<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Contracts;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
