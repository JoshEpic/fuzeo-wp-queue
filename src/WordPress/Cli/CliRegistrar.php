<?php

declare(strict_types=1);

namespace Fuzeo\Queue\WordPress\Cli;

/**
 * WP-CLI namespace: `wp fuzeo-queue`.
 *
 * Chosen over `wp fuzeo queue` so Fuzeo product plugins can own `wp fuzeo`,
 * and over `wp queue` to avoid colliding with other queue plugins.
 *
 * Only the winning runtime registers commands.
 */
final class CliRegistrar
{
    public const COMMAND = 'fuzeo-queue';

    public static function register(): void
    {
        $kernel = &$GLOBALS['fuzeo_queue_kernel'];
        if (!is_array($kernel)) {
            return;
        }
        if (($kernel['cli_registered'] ?? 0) > 0) {
            return;
        }
        $kernel['cli_registered'] = 1;

        if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::add_command(self::COMMAND, QueueCommand::class);
    }

    public static function registrationCount(): int
    {
        $kernel = $GLOBALS['fuzeo_queue_kernel'] ?? [];

        return (int) ($kernel['cli_registered'] ?? 0);
    }
}
