<?php

declare(strict_types=1);

namespace Fuzeo\Queue\WordPress\Admin;

/**
 * Single admin entry point owned by the winning runtime.
 *
 * Future dashboard registers one menu (network-aware) with capability
 * manage_options on single site and manage_network on network admin.
 * Origin filtering is a dashboard concern, not a second menu per plugin.
 *
 * Phase 1 does not render UI.
 */
final class AdminRegistrar
{
    public const MENU_SLUG = 'fuzeo-queue';
    public const CAPABILITY_SITE = 'manage_options';
    public const CAPABILITY_NETWORK = 'manage_network';

    public static function register(): void
    {
        $kernel = &$GLOBALS['fuzeo_queue_kernel'];
        if (!is_array($kernel)) {
            return;
        }
        if (($kernel['admin_registered'] ?? 0) > 0) {
            return;
        }
        $kernel['admin_registered'] = 1;

        if (!function_exists('add_action')) {
            return;
        }

        add_action('admin_menu', static function (): void {
            // Phase 2+ renders the dashboard. Registration exists so plugins do not add their own menus.
        }, 20);
        add_action('network_admin_menu', static function (): void {
        }, 20);
    }

    public static function registrationCount(): int
    {
        $kernel = $GLOBALS['fuzeo_queue_kernel'] ?? [];

        return (int) ($kernel['admin_registered'] ?? 0);
    }
}
