<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\ArrayLogger;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Runtime\FakeWordPressRuntime;
use Fuzeo\Queue\Runtime\ProcessLifecycle;
use Fuzeo\Queue\Runtime\RecycleReason;
use Fuzeo\Queue\Runtime\RuntimeBaseline;
use Fuzeo\Queue\Runtime\RuntimeGeneration;
use Fuzeo\Queue\Runtime\RuntimeResetter;
use Fuzeo\Queue\Runtime\MemoryMonitor;
use Fuzeo\Queue\Tests\Support\ChdirHandler;
use Fuzeo\Queue\Tests\Support\ChdirJob;
use Fuzeo\Queue\Tests\Support\InactiveOriginHandler;
use Fuzeo\Queue\Tests\Support\InactiveOriginJob;
use Fuzeo\Queue\Tests\Support\LeakBlogHandler;
use Fuzeo\Queue\Tests\Support\LeakBlogJob;
use Fuzeo\Queue\Tests\Support\LeakBufferHandler;
use Fuzeo\Queue\Tests\Support\LeakBufferJob;
use Fuzeo\Queue\Tests\Support\LeakLocaleHandler;
use Fuzeo\Queue\Tests\Support\LeakLocaleJob;
use Fuzeo\Queue\Tests\Support\LeakTransactionHandler;
use Fuzeo\Queue\Tests\Support\LeakTransactionJob;
use Fuzeo\Queue\Tests\Support\LeakUserHandler;
use Fuzeo\Queue\Tests\Support\LeakUserJob;
use Fuzeo\Queue\Tests\Support\ObserveBlogHandler;
use Fuzeo\Queue\Tests\Support\ObserveBlogJob;
use Fuzeo\Queue\Tests\Support\ObserveLocaleHandler;
use Fuzeo\Queue\Tests\Support\ObserveLocaleJob;
use Fuzeo\Queue\Tests\Support\ObserveUserHandler;
use Fuzeo\Queue\Tests\Support\ObserveUserJob;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\WordPress\QueueAccess;
use Fuzeo\Queue\WordPress\SiteHealth;
use Fuzeo\Queue\Worker\HandlerAvailability;
use Fuzeo\Queue\Worker\JobExecutor;
use Fuzeo\Queue\Worker\WordPressSiteSwitcher;
use Fuzeo\Queue\Worker\WorkerIdentity;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;
use PHPUnit\Framework\TestCase;

final class Phase7RuntimeTest extends TestCase
{
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
        Coordinator::bootForTesting();
        ProcessOrderHandler::reset();
        ObserveUserHandler::$userId = -1;
        ObserveLocaleHandler::$locale = '';
        ObserveBlogHandler::$blogId = 0;
        InactiveOriginHandler::$handled = 0;
    }

    protected function tearDown(): void
    {
        Coordinator::reset();
        unset($GLOBALS['fuzeo_test_wp'], $GLOBALS['fuzeo_wp_caps']);
        if (is_dir($this->cwd)) {
            chdir($this->cwd);
        }
    }

    public function testCurrentUserDoesNotLeakAcrossJobs(): void
    {
        $wp = $this->runtime();
        Queue::register(LeakUserJob::class, new Origin('acme/shop', '1.0.0'), LeakUserHandler::class);
        Queue::register(ObserveUserJob::class, new Origin('acme/shop', '1.0.0'), ObserveUserHandler::class);
        Queue::dispatch(new LeakUserJob());
        Queue::dispatch(new ObserveUserJob());
        $this->worker($wp, 2)->run();
        self::assertSame(0, ObserveUserHandler::$userId);
        self::assertSame(0, $wp->currentUserId());
    }

    public function testNestedBlogSwitchIsRestored(): void
    {
        $wp = $this->runtime();
        Queue::register(LeakBlogJob::class, new Origin('acme/shop', '1.0.0'), LeakBlogHandler::class);
        Queue::register(ObserveBlogJob::class, new Origin('acme/shop', '1.0.0'), ObserveBlogHandler::class);
        Queue::on('default')->onSite(1, 2)->dispatch(new LeakBlogJob());
        Queue::on('default')->onSite(1, 2)->dispatch(new ObserveBlogJob());
        $this->worker($wp, 2)->run();
        self::assertSame(1, $wp->currentBlogId());
        self::assertSame(2, ObserveBlogHandler::$blogId);
        self::assertSame(0, $wp->switchedStackDepth());
    }

    public function testLocaleDoesNotLeak(): void
    {
        $wp = $this->runtime();
        Queue::register(LeakLocaleJob::class, new Origin('acme/shop', '1.0.0'), LeakLocaleHandler::class);
        Queue::register(ObserveLocaleJob::class, new Origin('acme/shop', '1.0.0'), ObserveLocaleHandler::class);
        Queue::dispatch(new LeakLocaleJob());
        Queue::dispatch(new ObserveLocaleJob());
        $this->worker($wp, 2)->run();
        self::assertSame('en_US', ObserveLocaleHandler::$locale);
    }

    public function testOutputBuffersAreRestoredAfterException(): void
    {
        $wp = $this->runtime();
        $baseline = ob_get_level();
        Queue::register(LeakBufferJob::class, new Origin('acme/shop', '1.0.0'), LeakBufferHandler::class);
        Queue::dispatch(new LeakBufferJob());
        $this->worker($wp, 1)->run();
        self::assertSame($baseline, ob_get_level());
    }

    public function testLeakedTransactionForcesRecycle(): void
    {
        $wp = $this->runtime();
        Queue::register(LeakTransactionJob::class, new Origin('acme/shop', '1.0.0'), LeakTransactionHandler::class);
        Queue::dispatch(new LeakTransactionJob());
        $worker = $this->worker($wp, 5);
        $worker->run();
        self::assertFalse($wp->inTransaction());
        self::assertSame(RecycleReason::TransactionLeak, $worker->recycleReason());
        self::assertSame(1, $worker->processed());
    }

    public function testWorkingDirectoryIsRestored(): void
    {
        $wp = $this->runtime();
        Queue::register(ChdirJob::class, new Origin('acme/shop', '1.0.0'), ChdirHandler::class);
        Queue::dispatch(new ChdirJob());
        $this->worker($wp, 1)->run();
        self::assertSame($this->cwd, getcwd());
    }

    public function testInactiveOriginPluginFailsClosed(): void
    {
        $wp = $this->runtime();
        $wp->activePlugins = [];
        Queue::register(
            InactiveOriginJob::class,
            new Origin('acme/shop', '1.0.0', 'acme/shop.php'),
            InactiveOriginHandler::class
        );
        Queue::on('default')->onSite(1, 2)->dispatch(new InactiveOriginJob());
        $this->worker($wp, 1)->run();
        self::assertSame(0, InactiveOriginHandler::$handled);
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(\Fuzeo\Queue\Drivers\Memory\MemoryDriver::class, $driver);
        self::assertSame(JobState::Dead, $driver->all()[0]->state);
    }

    public function testNetworkActivePluginIsAllowed(): void
    {
        $wp = $this->runtime();
        $wp->networkPlugins = ['acme/shop.php'];
        Queue::register(
            InactiveOriginJob::class,
            new Origin('acme/shop', '1.0.0', 'acme/shop.php'),
            InactiveOriginHandler::class
        );
        Queue::on('default')->onSite(1, 2)->dispatch(new InactiveOriginJob());
        $this->worker($wp, 1)->run();
        self::assertSame(1, InactiveOriginHandler::$handled);
    }

    public function testGenerationChangeRequestsRecycle(): void
    {
        $wp = $this->runtime();
        $generation = new RuntimeGeneration($wp);
        $boot = $generation->current();
        $lifecycle = new ProcessLifecycle(
            new WorkerOptions(sleepSeconds: 0, generationCheckInterval: 1),
            $generation,
            new MemoryMonitor(memory_get_usage(true)),
            bootGeneration: $boot,
        );
        $wp->activePlugins = ['new/plugin.php'];
        $lifecycle->afterJob();
        self::assertTrue($lifecycle->shouldExit(0, false));
        self::assertSame(RecycleReason::GenerationChanged, $lifecycle->reason());
    }

    public function testMaxJobsRecycleCompletesQueueAcrossRestarts(): void
    {
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        for ($i = 1; $i <= 25; $i++) {
            Queue::dispatch(new ProcessOrderJob($i));
        }
        $runs = 0;
        $runtime = Coordinator::get();
        while (ProcessOrderHandler::$handled < 25 && $runs < 30) {
            $worker = new WorkerLoop(
                $runtime->driver(),
                new JobExecutor($runtime->jobs()),
                new \Fuzeo\Queue\Worker\MappedSiteSwitcher([1 => true]),
                new WorkerOptions(sleepSeconds: 0, maxJobs: 1),
                WorkerIdentity::generate(),
            );
            $worker->run();
            $runs++;
        }
        self::assertSame(25, ProcessOrderHandler::$handled);
        self::assertSame(25, $runs);
    }

    public function testSiteHealthPendingWithoutWorkersIsRecommended(): void
    {
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(1));
        $wp = new FakeWordPressRuntime();
        $health = new SiteHealth($wp, new RuntimeGeneration($wp), 30, 1000);
        $result = $health->testWorkers();
        self::assertSame('recommended', $result['status']);
        $debug = $health->debug();
        self::assertArrayHasKey('package_version', $debug);
        self::assertSame(false, $debug['object_cache_dropin']);
    }

    public function testQueueAccessDeniesNetworkWithoutCapability(): void
    {
        $GLOBALS['fuzeo_wp_caps'] = ['manage_options'];
        $GLOBALS['fuzeo_wp_multisite'] = true;
        $access = new QueueAccess();
        self::assertFalse($access->canViewNetwork());
        $GLOBALS['fuzeo_wp_caps'] = ['fuzeo_queue_manage_network'];
        self::assertTrue($access->canManageNetwork());
    }

    public function testInternalHooksDoNotAccumulatePerJob(): void
    {
        $before = count($GLOBALS['fuzeo_wp_actions']['fuzeo_queue_before_job'][10] ?? []);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(1));
        Queue::dispatch(new ProcessOrderJob(2));
        $this->worker($this->runtime(), 2)->run();
        $after = count($GLOBALS['fuzeo_wp_actions']['fuzeo_queue_before_job'][10] ?? []);
        self::assertSame($before, $after);
    }

    public function testResetterCleansBuffersWithoutClosingBaseline(): void
    {
        $wp = new FakeWordPressRuntime();
        ob_start();
        $baselineLevel = ob_get_level();
        $resetter = new RuntimeResetter(RuntimeBaseline::capture($wp, 'abc'), $wp, new ArrayLogger());
        ob_start();
        ob_start();
        $resetter->reset();
        self::assertSame($baselineLevel, ob_get_level());
        ob_end_clean();
    }

    public function testHandlerAvailabilityWithoutPluginFileAllowsJob(): void
    {
        $wp = new FakeWordPressRuntime();
        Coordinator::get()->jobs()->registerJob(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $availability = new HandlerAvailability(Coordinator::get()->jobs(), $wp);
        $envelope = Queue::dispatch(new ProcessOrderJob(1));
        $availability->assert($envelope);
        self::assertTrue(true);
    }

    private function runtime(): FakeWordPressRuntime
    {
        $wp = new FakeWordPressRuntime();
        $wp->sites[2] = ['deleted' => false, 'archived' => false, 'spam' => false, 'domain' => 'a.example.org', 'path' => '/a/'];
        $wp->sites[3] = ['deleted' => false, 'archived' => false, 'spam' => false, 'domain' => 'b.example.org', 'path' => '/b/'];
        $GLOBALS['fuzeo_test_wp'] = $wp;

        return $wp;
    }

    private function worker(FakeWordPressRuntime $wp, int $cycles, int $maxJobs = 0): WorkerLoop
    {
        $runtime = Coordinator::get();
        $logger = new ArrayLogger();
        $generation = new RuntimeGeneration($wp);
        $boot = $generation->current();
        $options = new WorkerOptions(sleepSeconds: 0, maxJobs: $maxJobs > 0 ? $maxJobs : max($cycles, 1));
        $resetter = new RuntimeResetter(RuntimeBaseline::capture($wp, $boot), $wp, $logger);
        $lifecycle = new ProcessLifecycle($options, $generation, new MemoryMonitor(memory_get_usage(true)), $logger, bootGeneration: $boot);

        return new WorkerLoop(
            $runtime->driver(),
            new JobExecutor($runtime->jobs()),
            new WordPressSiteSwitcher($wp),
            $options,
            WorkerIdentity::generate(null, $boot),
            resetter: $resetter,
            lifecycle: $lifecycle,
            logger: $logger,
            availability: new HandlerAvailability($runtime->jobs(), $wp),
        );
    }
}
