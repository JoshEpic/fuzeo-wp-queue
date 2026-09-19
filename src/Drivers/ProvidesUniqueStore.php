<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

use Fuzeo\Queue\Unique\UniqueStore;

interface ProvidesUniqueStore
{
    public function uniqueStore(): UniqueStore;
}
