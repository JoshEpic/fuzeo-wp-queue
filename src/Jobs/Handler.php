<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

use Fuzeo\Queue\Jobs\Envelope;

/**
 * Optional runtime handler. Workers in later phases resolve this from the registry.
 */
interface Handler
{
    public function handle(Envelope $envelope): void;
}
