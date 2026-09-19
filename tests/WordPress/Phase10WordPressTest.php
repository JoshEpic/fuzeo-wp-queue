<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\WordPress;

use Fuzeo\Queue\Operations\AccessDenied;
use Fuzeo\Queue\Operations\Operator;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\WordPress\QueueAccess;
use PHPUnit\Framework\TestCase;

final class Phase10WordPressTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('wp_insert_user')) {
            self::markTestSkipped('WP_TESTS_DIR is not configured.');
        }
        Coordinator::bootForTesting(['driver' => 'memory']);
    }

    protected function tearDown(): void
    {
        Coordinator::reset();
        wp_set_current_user(0);
    }

    public function testSiteAdminCannotRestartNetworkFleet(): void
    {
        if (!is_multisite()) {
            self::markTestSkipped('Multisite is required.');
        }
        $user = wp_insert_user([
            'user_login' => 'fq_site_restart_' . wp_generate_password(6, false),
            'user_pass' => wp_generate_password(),
            'role' => 'administrator',
        ]);
        self::assertIsInt($user);
        wp_set_current_user($user);
        $operator = new Operator(new QueueAccess(), $user, get_current_blog_id(), false);
        $this->expectException(AccessDenied::class);
        Coordinator::get()->operations()->requestRestart($operator);
    }
}
