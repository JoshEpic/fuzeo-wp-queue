<?php

/**
 * Candidate registration only. Autoloading this package must not boot WordPress
 * integrations or create a queue runtime.
 *
 * The candidate record shape is a compatibility contract across bundled copies:
 * version, compatibility_series, path, source.
 */

declare(strict_types=1);

$GLOBALS['fuzeo_queue_kernel'] ??= [
    'candidates' => [],
    'booted' => false,
    'boot_count' => 0,
    'hooks_registered' => 0,
    'cli_registered' => 0,
    'admin_registered' => 0,
    'migrations_run' => 0,
    'incompatible' => [],
    'diagnostics' => [],
    'runtime' => null,
];

$GLOBALS['fuzeo_queue_kernel']['candidates'][] = [
    'version' => '0.5.0',
    'compatibility_series' => 1,
    'path' => dirname(__DIR__),
    'source' => __FILE__,
];

if (!function_exists('fuzeo_queue_register_plugins_loaded_listener')) {
    /**
     * Safe to call more than once. No-ops until add_action exists (WordPress loaded).
     */
    function fuzeo_queue_register_plugins_loaded_listener(): void
    {
        static $registered = false;
        if ($registered || !function_exists('add_action')) {
            return;
        }

        $registered = true;
        add_action('plugins_loaded', static function (): void {
            if (!class_exists(\Fuzeo\Queue\Runtime\Coordinator::class, true)) {
                return;
            }

            \Fuzeo\Queue\Runtime\Coordinator::bootFromGlobals();
        }, -1000);
    }
}

fuzeo_queue_register_plugins_loaded_listener();
