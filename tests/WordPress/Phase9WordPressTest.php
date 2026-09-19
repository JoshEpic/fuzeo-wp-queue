<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\WordPress;

use Fuzeo\Queue\Operations\AccessDenied;
use Fuzeo\Queue\Operations\Operator;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\WordPress\QueueAccess;
use Fuzeo\Queue\WordPress\WordPressBootstrap;
use PHPUnit\Framework\TestCase;

final class Phase9WordPressTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ABSPATH') || !function_exists('wp_insert_user')) {
            self::markTestSkipped('WP_TESTS_DIR is not configured.');
        }
        Coordinator::bootForTesting(['driver' => 'memory']);
    }

    protected function tearDown(): void
    {
        Coordinator::reset();
        if (function_exists('wp_set_current_user')) {
            wp_set_current_user(0);
        }
    }

    public function testPluginLifecycleHookRequestsRestart(): void
    {
        WordPressBootstrap::onCodeChanged();
        self::assertNotSame('', Coordinator::get()->deployment()->snapshot()->restartGeneration);
    }

    public function testNetworkAuthorizationForFleetRestart(): void
    {
        if (!is_multisite()) {
            self::markTestSkipped('Multisite is required.');
        }
        $user = wp_insert_user([
            'user_login' => 'fq_deploy_' . wp_generate_password(6, false),
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
