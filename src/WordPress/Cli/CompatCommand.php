<?php

declare(strict_types=1);

namespace Fuzeo\Queue\WordPress\Cli;

use Fuzeo\Queue\Runtime\Coordinator;

final class CompatCommand
{
    /**
     * Show compatibility execution status.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        unset($args, $assoc);
        $snap = Coordinator::get()->execution()->snapshot();
        if (class_exists('WP_CLI')) {
            \WP_CLI::log('mode=' . $snap['mode'] . ' enabled=' . (($snap['compatibility_enabled'] ?? false) ? 'yes' : 'no'));
            \WP_CLI::log((string) ($snap['mode_label'] ?? ''));
            foreach ((array) ($snap['limitations'] ?? []) as $line) {
                \WP_CLI::log('- ' . $line);
            }
        }
    }

    /**
     * Enable the bounded WordPress compatibility executor. Does not start a persistent worker.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function enable(array $args, array $assoc): void
    {
        unset($args, $assoc);
        Coordinator::get()->execution()->enable();
        Coordinator::get()->operations()->recordEvent('compat.enabled', 'execution', 'compat');
        $this->line('WordPress Compatibility Mode enabled. Persistent CLI workers remain recommended.');
    }

    /**
     * Disable the WordPress compatibility executor.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function disable(array $args, array $assoc): void
    {
        unset($args, $assoc);
        Coordinator::get()->execution()->disable();
        Coordinator::get()->operations()->recordEvent('compat.disabled', 'execution', 'compat');
        $this->line('WordPress Compatibility Mode disabled.');
    }

    /**
     * Run one bounded compatibility tick (uses the real Queue backend).
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function run(array $args, array $assoc): void
    {
        unset($args);
        $force = isset($assoc['force']);
        $result = Coordinator::get()->execution()->tick($force, true);
        $this->line('outcome=' . $result->outcome . ' jobs=' . $result->jobsProcessed . ' reason=' . $result->reason);
    }

    /**
     * Print example crontab lines. Does not modify the system crontab.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function cronExample(array $args, array $assoc): void
    {
        unset($args, $assoc);
        $this->line(Coordinator::get()->execution()->cronExample());
    }

    private function line(string $text): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::log($text);
        }
    }
}
