#!/usr/bin/env bash
set -euo pipefail

DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-root}
DB_HOST=${4:-127.0.0.1}
WP_VERSION=${5:-latest}
WP_MULTISITE=${6:-true}

TMP=${TMPDIR:-/tmp}
WP_CORE="${WP_CORE_DIR:-$TMP/wordpress}"
WP_TESTS="${WP_TESTS_DIR:-$TMP/wordpress-tests-lib}"

mkdir -p "$WP_CORE" "$WP_TESTS"

download_core() {
  if [[ -f "$WP_CORE/wp-load.php" ]]; then
    return
  fi
  if [[ "$WP_VERSION" == "latest" ]]; then
    curl -sL "https://wordpress.org/latest.tar.gz" | tar -xz -C "$TMP"
    rsync -a "$TMP/wordpress/" "$WP_CORE/"
  else
    curl -sL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" | tar -xz -C "$TMP"
    rsync -a "$TMP/wordpress/" "$WP_CORE/"
  fi
}

download_tests() {
  if [[ -f "$WP_TESTS/includes/functions.php" ]]; then
    return
  fi
  local ref="trunk"
  if [[ "$WP_VERSION" != "latest" ]]; then
    ref="$WP_VERSION"
  fi
  curl -sL "https://github.com/WordPress/wordpress-develop/archive/refs/heads/trunk.tar.gz" | tar -xz -C "$TMP"
  rsync -a "$TMP"/wordpress-develop-trunk/tests/phpunit/includes "$WP_TESTS/"
  rsync -a "$TMP"/wordpress-develop-trunk/tests/phpunit/data "$WP_TESTS/" 2>/dev/null || true
  unset ref
}

download_core
download_tests

cat > "$WP_TESTS/wp-tests-config.php" <<PHP
<?php
define('ABSPATH', '${WP_CORE}/');
define('WP_DEBUG', true);
define('DB_NAME', '${DB_NAME}');
define('DB_USER', '${DB_USER}');
define('DB_PASSWORD', '${DB_PASS}');
define('DB_HOST', '${DB_HOST}');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');
define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Fuzeo Queue Tests');
define('WP_PHP_BINARY', 'php');
\$table_prefix = 'wptests_';
define('WP_TESTS_MULTISITE', ${WP_MULTISITE});
PHP

echo "WP_CORE_DIR=$WP_CORE"
echo "WP_TESTS_DIR=$WP_TESTS"
