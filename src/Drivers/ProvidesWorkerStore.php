<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers;

use Fuzeo\Queue\Worker\WorkerStore;

interface ProvidesWorkerStore
{
    public function workerStore(): WorkerStore;
}
