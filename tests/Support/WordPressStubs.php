<?php

declare(strict_types=1);

if (!function_exists('add_action')) {
    /** @var array<string, array<int, list<callable>>> $fuzeoWpActions */
    $fuzeoWpActions = [];
    $GLOBALS['fuzeo_wp_actions'] = $fuzeoWpActions;

    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        unset($accepted_args);
        $GLOBALS['fuzeo_wp_actions'][$hook][$priority][] = $callback;

        return true;
    }

    function do_action(string $hook, mixed ...$args): void
    {
        $callbacks = $GLOBALS['fuzeo_wp_actions'][$hook] ?? [];
        ksort($callbacks);
        foreach ($callbacks as $group) {
            foreach ($group as $callback) {
                $callback(...$args);
            }
        }
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        unset($hook, $args);

        return $value;
    }

    function get_current_blog_id(): int
    {
        return (int) ($GLOBALS['fuzeo_wp_blog_id'] ?? 1);
    }

    function get_current_network_id(): int
    {
        return (int) ($GLOBALS['fuzeo_wp_network_id'] ?? 1);
    }

    /**
     * @return mixed
     */
    function get_option(string $key, mixed $default = false): mixed
    {
        return $GLOBALS['fuzeo_wp_options'][$key] ?? $default;
    }

    function update_option(string $key, mixed $value): bool
    {
        $GLOBALS['fuzeo_wp_options'][$key] = $value;

        return true;
    }

    function delete_option(string $key): bool
    {
        unset($GLOBALS['fuzeo_wp_options'][$key]);

        return true;
    }

    /**
     * @return mixed
     */
    function get_site_option(string $key, mixed $default = false): mixed
    {
        return get_option($key, $default);
    }

    function update_site_option(string $key, mixed $value): bool
    {
        return update_option($key, $value);
    }

    function delete_site_option(string $key): bool
    {
        return delete_option($key);
    }

    /**
     * @return mixed
     */
    function get_network_option(?int $networkId, string $key, mixed $default = false): mixed
    {
        unset($networkId);

        return get_option($key, $default);
    }

    function update_network_option(?int $networkId, string $key, mixed $value): bool
    {
        unset($networkId);

        return update_option($key, $value);
    }

    function delete_network_option(?int $networkId, string $key): bool
    {
        unset($networkId);

        return delete_option($key);
    }

    function __(string $text, string $domain = 'default'): string
    {
        unset($domain);

        return $text;
    }

    function get_current_user_id(): int
    {
        return (int) ($GLOBALS['fuzeo_wp_user_id'] ?? 0);
    }

    function wp_set_current_user(int $id): void
    {
        $GLOBALS['fuzeo_wp_user_id'] = $id;
    }

    function get_locale(): string
    {
        return (string) ($GLOBALS['fuzeo_wp_locale'] ?? 'en_US');
    }

    function restore_current_locale(): bool
    {
        $GLOBALS['fuzeo_wp_locale'] = 'en_US';

        return true;
    }

    function wp_reset_query(): void
    {
    }

    function wp_reset_postdata(): void
    {
    }

    function wp_cache_flush_runtime(): bool
    {
        return true;
    }

    function is_multisite(): bool
    {
        return (bool) ($GLOBALS['fuzeo_wp_multisite'] ?? false);
    }

    function is_plugin_active(string $plugin): bool
    {
        $active = $GLOBALS['fuzeo_wp_active_plugins'] ?? [];

        return is_array($active) && in_array($plugin, $active, true);
    }

    function is_plugin_active_for_network(string $plugin): bool
    {
        $active = $GLOBALS['fuzeo_wp_network_plugins'] ?? [];

        return is_array($active) && in_array($plugin, $active, true);
    }

    function current_user_can(string $capability): bool
    {
        $caps = $GLOBALS['fuzeo_wp_caps'] ?? [];

        return is_array($caps) && in_array($capability, $caps, true);
    }

    function add_menu_page(string $pageTitle, string $menuTitle, string $capability, string $menuSlug, callable|string|array $callback = '', string $icon = '', int|float|null $position = null): string
    {
        unset($pageTitle, $menuTitle, $capability, $callback, $icon, $position);

        return $menuSlug;
    }

    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        unset($hook, $callback, $priority, $accepted_args);

        return true;
    }

    function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
    {
        unset($namespace, $route, $args, $override);

        return true;
    }

    function rest_ensure_response(mixed $data): mixed
    {
        return $data;
    }

    function rest_url(string $path = '', string $scheme = 'rest'): string
    {
        unset($scheme);

        return '/wp-json/' . ltrim($path, '/');
    }

    function sanitize_key(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $key) ?? $key);
    }

    function sanitize_text_field(string $str): string
    {
        return trim($str);
    }

    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    function esc_url(string $url): string
    {
        return $url;
    }

    function wp_create_nonce(string $action): string
    {
        return 'nonce-' . $action;
    }

    function wp_nonce_field(string $action, string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
    {
        unset($referer);
        $html = '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce($action) . '" />';
        if ($echo) {
            echo $html;
        }

        return $html;
    }

    function check_admin_referer(string $action = '-1', string $queryArg = '_wpnonce'): bool
    {
        unset($action, $queryArg);

        return true;
    }

    function wp_safe_redirect(string $location, int $status = 302): void
    {
        unset($location, $status);
    }

    function wp_get_referer(): string|false
    {
        return false;
    }

    function admin_url(string $path = '', string $scheme = 'admin'): string
    {
        unset($scheme);

        return '/wp-admin/' . ltrim($path, '/');
    }

    function add_query_arg(mixed $key, mixed $value = false, mixed $url = false): string
    {
        unset($key, $value);

        return is_string($url) ? $url : '/wp-admin/admin.php';
    }

    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, string $media = 'all'): void
    {
        unset($handle, $src, $deps, $ver, $media);
    }

    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, bool $inFooter = false): void
    {
        unset($handle, $src, $deps, $ver, $inFooter);
    }

    function plugins_url(string $path = '', string $plugin = ''): string
    {
        unset($plugin);

        return '/assets/' . ltrim($path, '/');
    }

    function content_url(string $path = ''): string
    {
        return '/wp-content/' . ltrim($path, '/');
    }

    function is_network_admin(): bool
    {
        return (bool) ($GLOBALS['fuzeo_wp_network_admin'] ?? false);
    }

    function get_site(int $siteId): mixed
    {
        $deleted = $GLOBALS['fuzeo_wp_deleted_sites'] ?? [];
        if (is_array($deleted) && in_array($siteId, $deleted, true)) {
            return null;
        }

        return $siteId > 0 ? (object) ['blog_id' => $siteId] : null;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(public string $code = '', public string $message = '', public mixed $data = null)
        {
        }
    }
}

if (!class_exists('WP_CLI')) {
    final class WP_CLI
    {
        /** @var array<string, callable|string|object> */
        public static array $commands = [];

        public static function add_command(string $name, callable|string|object $callable): void
        {
            self::$commands[$name] = $callable;
        }

        public static function log(string $message): void
        {
        }

        public static function halt(int $code): void
        {
            unset($code);
        }

        public static function error(string $message): void
        {
            throw new RuntimeException($message);
        }
    }
}
