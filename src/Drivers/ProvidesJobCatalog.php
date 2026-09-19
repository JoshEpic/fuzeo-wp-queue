<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

use Fuzeo\Queue\Inspection\JobCatalog;

interface ProvidesJobCatalog
{
    public function catalog(): JobCatalog;
}
