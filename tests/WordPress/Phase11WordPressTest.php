<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\WordPress;

use Fuzeo\Queue\Interop\CronDescriptor;
use Fuzeo\Queue\Interop\WordPressCronGateway;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\WordPress\QueueAccess;
use PHPUnit\Framework\TestCase;

final class Phase11WordPressTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ABSPATH') || !function_exists('wp_schedule_event')) {
            self::markTestSkipped('WP_TESTS_DIR is not configured.');
        }
        Coordinator::bootForTesting(['driver' => 'memory']);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        ProcessOrderHandler::reset();
    }

    protected function tearDown(): void
    {
        wp_clear_scheduled_hook('acme_hourly_sync');
        wp_clear_scheduled_hook('acme_once_job');
        Coordinator::reset();
        wp_set_current_user(0);
    }

    public function testRealWpCronInspectAndMigrate(): void
    {
        $next = time() + 3600;
        self::assertTrue((bool) wp_schedule_event($next, 'hourly', 'acme_hourly_sync', [9]));
        $origin = new Origin('acme/shop', '1.0.0');
        $interop = Coordinator::get()->interop();
        $interop->registerCron(new CronDescriptor(
            $origin,
            'acme_hourly_sync',
            ProcessOrderJob::class,
            static fn (array $args): Job => new ProcessOrderJob((int) ($args[0] ?? 0)),
            intervalSeconds: 3600,
            scheduleName: 'acme-hourly-sync',
        ));
        $plan = $interop->planCron('acme_hourly_sync', ExecutionContext::singleSite(get_current_blog_id()));
        $record = $interop->migrate($plan, true);
        self::assertFalse((bool) wp_next_scheduled('acme_hourly_sync', [9]));
        self::assertNotNull(Coordinator::get()->schedules()->get($record->destinationId));
        $interop->rollback($record->migrationId);
        self::assertNotFalse(wp_next_scheduled('acme_hourly_sync', [9]));
    }

    public function testDisableWpCronConstantDoesNotHideEvents(): void
    {
        $gateway = new WordPressCronGateway();
        wp_schedule_single_event(time() + 600, 'acme_once_job', [1]);
        self::assertNotEmpty($gateway->cronArray());
        self::assertSame(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON, $gateway->automaticSpawningDisabled());
    }

    public function testSiteAdminCannotManageOtherSiteWhenMultisite(): void
    {
        if (!is_multisite()) {
            self::markTestSkipped('Multisite is required.');
        }
        $user = wp_insert_user([
            'user_login' => 'fq_interop_' . wp_generate_password(6, false),
            'user_pass' => wp_generate_password(),
            'role' => 'administrator',
        ]);
        self::assertIsInt($user);
        wp_set_current_user($user);
        $access = new QueueAccess();
        $other = wp_insert_site([
            'domain' => 'fqi' . wp_generate_password(4, false) . '.example.org',
            'path' => '/',
            'title' => 'Interop',
        ]);
        if (is_wp_error($other)) {
            self::markTestSkipped($other->get_error_message());
        }
        self::assertFalse($access->canManageSite((int) $other));
    }
}
