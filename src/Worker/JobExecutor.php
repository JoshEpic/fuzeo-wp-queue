<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\Handler;
use Fuzeo\Queue\Jobs\JobRegistry;
use Fuzeo\Queue\Jobs\RegisteredJob;

final class JobExecutor
{
    public function __construct(private readonly JobRegistry $registry)
    {
    }

    public function execute(Envelope $envelope): void
    {
        $registered = $this->registry->get($envelope->jobType);
        if ($envelope->schemaVersion !== $registered->schemaVersion) {
            throw new \Fuzeo\Queue\Exceptions\UnsupportedSchemaException(
                'Job ' . $envelope->jobType . ' schema version ' . $envelope->schemaVersion
                . ' does not match registered version ' . $registered->schemaVersion . '.'
            );
        }

        $handler = $this->instantiate($registered);
        $handler->handle($envelope);
    }

    private function instantiate(RegisteredJob $registered): Handler
    {
        $class = $registered->handler;
        if (!is_a($class, Handler::class, true)) {
            throw new \Fuzeo\Queue\Exceptions\UnknownJobException(
                'Registered handler ' . $class . ' for ' . $registered->type . ' does not implement ' . Handler::class . '.'
            );
        }

        $instance = new $class();
        if (!$instance instanceof Handler) {
            throw new \Fuzeo\Queue\Exceptions\UnknownJobException('Handler instantiation failed for ' . $registered->type . '.');
        }

        return $instance;
    }
}
