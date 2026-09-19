<?php

declare(strict_types=1);

namespace Fuzeo\Queue\WordPress\Admin;

use Fuzeo\Queue\WordPress\Capabilities;

/**
 * Single admin entry owned by the winning runtime.
 */
final class AdminRegistrar
{
    public const MENU_SLUG = 'fuzeo-queue';
    public const CAPABILITY_SITE = Capabilities::FALLBACK_SITE;
    public const CAPABILITY_NETWORK = Capabilities::FALLBACK_NETWORK;

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
            $cap = Capabilities::VIEW;
            if (!function_exists('current_user_can') || (!current_user_can($cap) && !current_user_can(Capabilities::FALLBACK_SITE))) {
                $cap = Capabilities::FALLBACK_SITE;
            }
            if (function_exists('is_network_admin') && is_multisite() && is_network_admin()) {
                return;
            }
            add_menu_page(
                'Fuzeo Queue',
                'Fuzeo Queue',
                $cap,
                self::MENU_SLUG,
                [AdminPage::class, 'render'],
                'dashicons-list-view',
                58
            );
        }, 20);
        add_action('network_admin_menu', static function (): void {
            $cap = Capabilities::NETWORK_VIEW;
            if (!function_exists('current_user_can') || (!current_user_can($cap) && !current_user_can(Capabilities::FALLBACK_NETWORK))) {
                $cap = Capabilities::FALLBACK_NETWORK;
            }
            add_menu_page(
                'Fuzeo Queue',
                'Fuzeo Queue',
                $cap,
                self::MENU_SLUG,
                [AdminPage::class, 'render'],
                'dashicons-list-view',
                58
            );
        }, 20);
        add_action('admin_enqueue_scripts', [AdminPage::class, 'enqueueAssets']);
        add_action('admin_post_fuzeo_queue_retry', [AdminPage::class, 'handleRetry']);
        add_action('admin_post_fuzeo_queue_restart', [AdminPage::class, 'handleRestart']);
        add_action('admin_post_fuzeo_queue_drain', [AdminPage::class, 'handleDrain']);
    }

    public static function registrationCount(): int
    {
        $kernel = $GLOBALS['fuzeo_queue_kernel'] ?? [];

        return (int) ($kernel['admin_registered'] ?? 0);
    }
}
