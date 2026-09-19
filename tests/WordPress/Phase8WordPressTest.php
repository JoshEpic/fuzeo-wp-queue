<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\WordPress;

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Operations\AccessDenied;
use Fuzeo\Queue\Operations\Operator;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\WordPress\QueueAccess;
use PHPUnit\Framework\TestCase;

final class Phase8WordPressTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('wp_insert_user')) {
            self::markTestSkipped('WP_TESTS_DIR is not configured.');
        }
        Coordinator::bootForTesting(['driver' => 'memory']);
        ProcessOrderHandler::reset();
    }

    protected function tearDown(): void
    {
        Coordinator::reset();
        wp_set_current_user(0);
    }

    public function testDeletedSiteLabel(): void
    {
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $envelope = Queue::on('default')->onSite(1, 4242)->dispatch(new ProcessOrderJob(1));
        $detail = Coordinator::get()->operations()->job(Operator::cli(), $envelope->jobId);
        self::assertStringContainsString('4242', (string) $detail['site_label']);
    }

    public function testCrossSiteDeniedWhenMultisite(): void
    {
        if (!is_multisite()) {
            self::markTestSkipped('Multisite is required.');
        }
        $user = wp_insert_user([
            'user_login' => 'fq_site_admin_' . wp_generate_password(6, false),
            'user_pass' => wp_generate_password(),
            'role' => 'administrator',
        ]);
        self::assertIsInt($user);
        wp_set_current_user($user);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $other = wp_insert_site([
            'domain' => 'fq' . wp_generate_password(4, false) . '.example.org',
            'path' => '/',
            'title' => 'Fuzeo Queue Site',
        ]);
        if (is_wp_error($other)) {
            self::markTestSkipped($other->get_error_message());
        }
        $envelope = Queue::on('default')->onSite(1, (int) $other)->dispatch(new ProcessOrderJob(8));
        $operator = new Operator(new QueueAccess(), $user, get_current_blog_id(), false);
        $this->expectException(AccessDenied::class);
        Coordinator::get()->operations()->job($operator, $envelope->jobId);
    }
}
