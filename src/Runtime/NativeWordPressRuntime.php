<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

/**
 * Calls WordPress Core when functions exist. Safe no-op outside WordPress.
 */
final class NativeWordPressRuntime implements WordPressRuntime
{
    public function currentBlogId(): int
    {
        if (function_exists('get_current_blog_id')) {
            return (int) get_current_blog_id();
        }

        return 1;
    }

    public function currentUserId(): int
    {
        if (function_exists('get_current_user_id')) {
            return (int) get_current_user_id();
        }

        return 0;
    }

    public function setCurrentUser(int $userId): void
    {
        if (function_exists('wp_set_current_user')) {
            wp_set_current_user($userId);
        }
    }

    public function currentLocale(): string
    {
        if (function_exists('get_locale')) {
            return (string) get_locale();
        }

        return '';
    }

    public function restoreLocale(): void
    {
        if (function_exists('restore_current_locale')) {
            restore_current_locale();

            return;
        }
        if (function_exists('restore_previous_locale')) {
            $guard = 16;
            while ($guard-- > 0 && function_exists('is_locale_switched') && is_locale_switched()) {
                restore_previous_locale();
            }
        }
    }

    public function switchedStackDepth(): int
    {
        $stack = $GLOBALS['_wp_switched_stack'] ?? null;
        if (is_array($stack)) {
            return count($stack);
        }

        return !empty($GLOBALS['switched']) ? 1 : 0;
    }

    public function restorePreviousBlog(): void
    {
        if (function_exists('restore_current_blog') && $this->switchedStackDepth() > 0) {
            restore_current_blog();
        }
    }

    public function restoreBlog(): void
    {
        $guard = 32;
        while ($guard-- > 0 && $this->switchedStackDepth() > 0) {
            $this->restorePreviousBlog();
        }
    }

    public function switchToBlog(int $siteId): void
    {
        if (function_exists('switch_to_blog')) {
            switch_to_blog($siteId);
        }
    }

    public function siteExists(int $siteId): bool
    {
        return $this->siteStatus($siteId) !== null;
    }

    public function siteStatus(int $siteId): ?array
    {
        if (function_exists('get_site')) {
            $site = get_site($siteId);
            if ($site === null || $site === false) {
                return null;
            }
            if (is_object($site)) {
                return [
                    'deleted' => (bool) ($site->deleted ?? false),
                    'archived' => (bool) ($site->archived ?? false),
                    'spam' => (bool) ($site->spam ?? false),
                    'mature' => (bool) ($site->mature ?? false),
                    'domain' => (string) ($site->domain ?? ''),
                    'path' => (string) ($site->path ?? ''),
                ];
            }
        }
        if (function_exists('get_blog_details')) {
            $details = get_blog_details($siteId);
            if ($details === false || $details === null) {
                return null;
            }
            if (is_object($details)) {
                return [
                    'deleted' => (bool) ($details->deleted ?? false),
                    'archived' => (bool) ($details->archived ?? false),
                    'spam' => (bool) ($details->spam ?? false),
                    'mature' => (bool) ($details->mature ?? false),
                    'domain' => (string) ($details->domain ?? ''),
                    'path' => (string) ($details->path ?? ''),
                ];
            }
        }
        if ($siteId === $this->currentBlogId()) {
            return ['deleted' => false, 'archived' => false, 'spam' => false];
        }

        return null;
    }

    public function resetQuery(): void
    {
        if (function_exists('wp_reset_query')) {
            wp_reset_query();
        }
        if (function_exists('wp_reset_postdata')) {
            wp_reset_postdata();
        }
        unset($GLOBALS['post']);
    }

    public function flushRuntimeCache(): void
    {
        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();

            return;
        }
        if (function_exists('wp_cache_switch_to_blog')) {
            wp_cache_switch_to_blog($this->currentBlogId());
        }
    }

    public function objectCacheDropInPresent(): bool
    {
        if (defined('WP_CONTENT_DIR')) {
            return is_file(WP_CONTENT_DIR . '/object-cache.php');
        }

        return false;
    }

    public function isPluginActive(string $pluginFile): bool
    {
        if (function_exists('is_plugin_active')) {
            return is_plugin_active($pluginFile);
        }
        $plugins = $this->activePlugins();

        return in_array($pluginFile, $plugins, true);
    }

    public function isPluginActiveForNetwork(string $pluginFile): bool
    {
        if (function_exists('is_plugin_active_for_network')) {
            return is_plugin_active_for_network($pluginFile);
        }

        return in_array($pluginFile, $this->networkActivePlugins(), true);
    }

    public function activePlugins(): array
    {
        if (!function_exists('get_option')) {
            return [];
        }
        $value = get_option('active_plugins', []);
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $plugin) {
            if (is_string($plugin) && $plugin !== '') {
                $out[] = $plugin;
            }
        }
        sort($out);

        return $out;
    }

    public function networkActivePlugins(): array
    {
        if (!function_exists('get_site_option')) {
            return [];
        }
        $value = get_site_option('active_sitewide_plugins', []);
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach (array_keys($value) as $plugin) {
            if (is_string($plugin) && $plugin !== '') {
                $out[] = $plugin;
            }
        }
        sort($out);

        return $out;
    }

    public function activeTheme(): string
    {
        $stylesheet = function_exists('get_option') ? get_option('stylesheet', '') : '';
        $template = function_exists('get_option') ? get_option('template', '') : '';

        return (is_string($stylesheet) ? $stylesheet : '') . '|' . (is_string($template) ? $template : '');
    }

    public function isMultisite(): bool
    {
        return function_exists('is_multisite') && is_multisite();
    }

    public function rollbackOpenTransaction(): bool
    {
        if (!$this->inTransaction()) {
            return false;
        }
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if (is_object($wpdb) && method_exists($wpdb, 'query')) {
            $wpdb->query('ROLLBACK');
        }

        return true;
    }

    public function inTransaction(): bool
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return false;
        }
        $flag = $wpdb->get_var('SELECT @@in_transaction');
        if ($flag !== null && (int) $flag === 1) {
            return true;
        }
        $trx = $wpdb->get_var(
            'SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = CONNECTION_ID()'
        );

        return is_numeric($trx) && (int) $trx > 0;
    }
}
