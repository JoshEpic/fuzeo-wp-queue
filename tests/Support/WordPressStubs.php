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

        public static function error(string $message): void
        {
            throw new RuntimeException($message);
        }
    }
}
