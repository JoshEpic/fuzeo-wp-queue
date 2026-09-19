<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\WordPress;

use PHPUnit\Framework\TestCase;

final class WordPressCoreTest extends TestCase
{
    public function testWordpressTestSuiteIsConfigured(): void
    {
        $dir = getenv('WP_TESTS_DIR');
        if (!is_string($dir) || $dir === '' || !is_file($dir . '/includes/functions.php')) {
            self::markTestSkipped('WP_TESTS_DIR is not configured. Install the WordPress test suite to enable Core tests.');
        }

        require_once $dir . '/includes/functions.php';
        self::assertTrue(function_exists('tests_add_filter') || function_exists('do_action'));
    }
}
