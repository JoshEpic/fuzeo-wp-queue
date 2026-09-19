<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

use Fuzeo\Queue\Drivers\CancelsJobs;
use Fuzeo\Queue\Jobs\ContextualHandler;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\Handler;
use Fuzeo\Queue\Jobs\JobContext;
use Fuzeo\Queue\Jobs\JobRegistry;
use Fuzeo\Queue\Jobs\RegisteredJob;

final class JobExecutor
{
    public function __construct(
        private readonly JobRegistry $registry,
        private readonly ?CancelsJobs $control = null,
    ) {
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

        $instance = $this->instantiate($registered);
        $context = new JobContext($envelope, $this->control);
        if ($instance instanceof ContextualHandler) {
            $instance->handleContext($context);

            return;
        }
        if ($instance instanceof Handler) {
            $instance->handle($envelope);

            return;
        }

        throw new \Fuzeo\Queue\Exceptions\UnknownJobException(
            'Registered handler ' . $registered->handler . ' for ' . $registered->type
            . ' does not implement ' . Handler::class . ' or ' . ContextualHandler::class . '.'
        );
    }

    private function instantiate(RegisteredJob $registered): object
    {
        $class = $registered->handler;
        if (!is_a($class, Handler::class, true) && !is_a($class, ContextualHandler::class, true)) {
            throw new \Fuzeo\Queue\Exceptions\UnknownJobException(
                'Registered handler ' . $class . ' for ' . $registered->type
                . ' does not implement ' . Handler::class . ' or ' . ContextualHandler::class . '.'
            );
        }

        return new $class();
    }
}
