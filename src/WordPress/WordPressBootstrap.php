<?php

declare(strict_types=1);

namespace Fuzeo\Queue\WordPress;

use Fuzeo\Queue\Runtime\Coordinator;

/**
 * WordPress hooks attach only after the winning runtime boots.
 * Autoloading the package does not register admin, CLI, or schema hooks.
 */
final class WordPressBootstrap
{
    public static function register(): void
    {
        $kernel = &$GLOBALS['fuzeo_queue_kernel'];
        if (!is_array($kernel)) {
            return;
        }
        if (($kernel['hooks_registered'] ?? 0) > 0) {
            return;
        }

        $kernel['hooks_registered'] = 1;

        if (function_exists('do_action')) {
            do_action('fuzeo_queue_ready', Coordinator::get());
        }
    }

    public static function hookCount(): int
    {
        $kernel = $GLOBALS['fuzeo_queue_kernel'] ?? [];

        return (int) ($kernel['hooks_registered'] ?? 0);
    }
}
