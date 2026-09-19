<?php

declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';
        public string $base_prefix = 'wp_';
        public string $last_error = '';

        /**
         * @return mixed
         */
        public function get_results(string $sql, mixed $output = null): mixed
        {
            unset($sql, $output);

            return [];
        }

        /**
         * @return mixed
         */
        public function get_row(string $sql, mixed $output = null): mixed
        {
            unset($sql, $output);

            return null;
        }

        /**
         * @return mixed
         */
        public function get_var(string $sql): mixed
        {
            unset($sql);

            return null;
        }

        /**
         * @return mixed
         */
        public function query(string $sql): mixed
        {
            unset($sql);

            return 0;
        }

        public function prepare(string $sql, mixed ...$args): string
        {
            unset($args);

            return $sql;
        }

        public function check_connection(bool $allowBail = true): bool
        {
            unset($allowBail);

            return true;
        }

        public function db_connect(bool $allowBail = true): bool
        {
            unset($allowBail);

            return true;
        }
    }
}
