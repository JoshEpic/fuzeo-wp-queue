<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Operations\AccessDenied;
use Fuzeo\Queue\Operations\Operator;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\WordPress\QueueAccess;
use PHPUnit\Framework\TestCase;

final class Phase8OperationsTest extends TestCase
{
    protected function setUp(): void
    {
        Coordinator::bootForTesting();
        ProcessOrderHandler::reset();
        $GLOBALS['fuzeo_wp_multisite'] = true;
        $GLOBALS['fuzeo_wp_blog_id'] = 1;
        $GLOBALS['fuzeo_wp_caps'] = ['fuzeo_queue_view', 'manage_options'];
    }

    protected function tearDown(): void
    {
        Coordinator::reset();
        unset($GLOBALS['fuzeo_wp_caps'], $GLOBALS['fuzeo_wp_multisite'], $GLOBALS['fuzeo_wp_blog_id']);
    }

    public function testSiteAdminCannotInspectAnotherSiteJob(): void
    {
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $envelope = Queue::on('default')->onSite(1, 2)->dispatch(new ProcessOrderJob(5));
        $operator = new Operator(new QueueAccess(), 1, 1, false);
        $this->expectException(AccessDenied::class);
        Coordinator::get()->operations()->job($operator, $envelope->jobId);
    }

    public function testNetworkAdminCanInspectSiteJob(): void
    {
        $GLOBALS['fuzeo_wp_caps'] = ['fuzeo_queue_manage_network', 'manage_network_options'];
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $envelope = Queue::on('default')->onSite(1, 2)->dispatch(new ProcessOrderJob(5));
        $operator = new Operator(new QueueAccess(), 1, 1, true);
        $detail = Coordinator::get()->operations()->job($operator, $envelope->jobId);
        self::assertSame($envelope->jobId, $detail['summary']['job_id']);
    }

    public function testOverviewEmptyState(): void
    {
        $overview = Coordinator::get()->operations()->overview(Operator::cli());
        self::assertTrue($overview['empty']);
        self::assertSame('healthy', $overview['health']['status']);
    }

    public function testCliHealthAndJobs(): void
    {
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $envelope = Queue::dispatch(new ProcessOrderJob(3));
        $worker = \Fuzeo\Queue\Worker\WorkerLoop::fromManager(
            Coordinator::get(),
            \Fuzeo\Queue\Worker\WorkerOptions::fromCli(['sleep' => '0'], 'default', Coordinator::get()->config())
        );
        $worker->run(1);
        $health = Coordinator::get()->operations()->queueHealth(Operator::cli());
        self::assertContains($health->status->value, ['healthy', 'degraded']);
        $detail = Coordinator::get()->operations()->job(Operator::cli(), $envelope->jobId);
        self::assertSame($envelope->jobId, $detail['summary']['job_id']);
        $cmd = new \Fuzeo\Queue\WordPress\Cli\QueueCommand();
        $cmd->health([], ['format' => 'json']);
        $cmd->jobs(['show', $envelope->jobId], []);
    }

    public function testRestPermissionUsesQueueAccess(): void
    {
        $GLOBALS['fuzeo_wp_caps'] = [];
        $access = new QueueAccess();
        self::assertFalse($access->canViewSite(1));
        $GLOBALS['fuzeo_wp_caps'] = ['manage_options'];
        self::assertTrue($access->canViewSite(1));
    }
}
