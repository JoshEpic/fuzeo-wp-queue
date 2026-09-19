<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

use Fuzeo\Queue\Exceptions\HandlerUnavailableException;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\ExecutionScope;
use Fuzeo\Queue\Jobs\JobRegistry;
use Fuzeo\Queue\Runtime\WordPressRuntime;

/**
 * Fail closed when the origin plugin is not active on the target site.
 * Classes remaining in worker memory are not treated as active.
 */
final class HandlerAvailability
{
    public function __construct(
        private readonly JobRegistry $registry,
        private readonly WordPressRuntime $wp,
    ) {
    }

    public function assert(Envelope $envelope): void
    {
        $registered = $this->registry->get($envelope->jobType);
        $plugin = $registered->origin->pluginFile;
        if ($plugin === null || $plugin === '') {
            return;
        }
        if ($envelope->context->scope === ExecutionScope::Network) {
            if (!$this->wp->isPluginActiveForNetwork($plugin)) {
                throw new HandlerUnavailableException(
                    'Origin plugin ' . $plugin . ' is not network-active for network-scoped job ' . $envelope->jobType . '.'
                );
            }

            return;
        }
        if ($this->wp->isPluginActiveForNetwork($plugin) || $this->wp->isPluginActive($plugin)) {
            return;
        }

        throw new HandlerUnavailableException(
            'Origin plugin ' . $plugin . ' is not active on site ' . $envelope->context->siteId
            . ' for job ' . $envelope->jobType . '.'
        );
    }
}
