<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Integration;

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ImportRecordJob;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\WordPress\WordPressContextResolver;
use PHPUnit\Framework\TestCase;

final class WordPressIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['fuzeo_wp_blog_id'] = 1;
        $GLOBALS['fuzeo_wp_network_id'] = 1;
        $GLOBALS['fuzeo_wp_options'] = [];
    }

    protected function tearDown(): void
    {
        Coordinator::reset();
    }

    public function testSingleSiteDispatchUsesCurrentBlog(): void
    {
        $GLOBALS['fuzeo_wp_blog_id'] = 7;
        $GLOBALS['fuzeo_wp_network_id'] = 1;
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::fake();
        Queue::dispatch(new ProcessOrderJob(5));
        $envelope = Queue::fake()->dispatched(ProcessOrderJob::class)[0];
        self::assertSame(7, $envelope->context->siteId);
        self::assertSame(1, $envelope->context->networkId);
    }

    public function testExplicitSiteIsNotReplacedByCurrentBlog(): void
    {
        $GLOBALS['fuzeo_wp_blog_id'] = 1;
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::fake();
        Queue::on('default')->onSite(9, 42)->dispatch(new ProcessOrderJob(5));
        $envelope = Queue::fake()->dispatched(ProcessOrderJob::class)[0];
        self::assertSame(42, $envelope->context->siteId);
        self::assertSame(9, $envelope->context->networkId);
    }

    public function testSeparatePluginsRegisterDistinctJobTypes(): void
    {
        Coordinator::bootForTesting();
        $runtime = Coordinator::get();
        $runtime->consumers()->register(new Origin('acme/alpha', '1.0.0'));
        $runtime->consumers()->register(new Origin('acme/beta', '1.0.0'));
        $runtime->jobs()->registerJob(ProcessOrderJob::class, new Origin('acme/alpha', '1.0.0'), ProcessOrderHandler::class);
        $runtime->jobs()->registerJob(ImportRecordJob::class, new Origin('acme/beta', '1.0.0'));
        self::assertTrue($runtime->jobs()->has('acme.process_order'));
        self::assertTrue($runtime->jobs()->has('acme.import_record'));
    }

    public function testContextResolverReadsWordPressGlobals(): void
    {
        $GLOBALS['fuzeo_wp_blog_id'] = 12;
        $GLOBALS['fuzeo_wp_network_id'] = 4;
        $context = (new WordPressContextResolver())->current();
        self::assertSame(12, $context->siteId);
        self::assertSame(4, $context->networkId);
    }

    public function testPluginsLoadedBootsOnce(): void
    {
        Coordinator::reset();
        do_action('plugins_loaded');
        do_action('plugins_loaded');
        self::assertTrue(Coordinator::isBooted());
        self::assertSame(2, Coordinator::bootCount());
        self::assertSame(1, $GLOBALS['fuzeo_queue_kernel']['hooks_registered']);
    }
}
