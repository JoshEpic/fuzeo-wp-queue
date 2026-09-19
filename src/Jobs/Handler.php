<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

use Fuzeo\Queue\Jobs\Envelope;

/**
 * Optional runtime handler. Workers resolve this from the job registry.
 * Handler class names are not the persistence identity; job type() is.
 */
interface Handler
{
    public function handle(Envelope $envelope): void;
}
