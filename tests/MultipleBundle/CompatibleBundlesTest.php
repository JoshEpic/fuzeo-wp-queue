<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\MultipleBundle;

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Runtime\Candidate;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Runtime\PackageInfo;
use Fuzeo\Queue\Tests\Support\ImportRecordJob;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\WordPress\Admin\AdminRegistrar;
use Fuzeo\Queue\WordPress\Cli\CliRegistrar;
use Fuzeo\Queue\WordPress\WordPressBootstrap;
use PHPUnit\Framework\TestCase;

final class CompatibleBundlesTest extends TestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
    }

    public function testOneRuntimeTwoConsumersZeroDuplicateHooks(): void
    {
        $a = new Candidate('0.1.0', PackageInfo::COMPATIBILITY_SERIES, '/plugins/a', 'a.php');
        $b = new Candidate('0.1.0', PackageInfo::COMPATIBILITY_SERIES, '/plugins/b', 'b.php');

        $first = Coordinator::boot([$a, $b], ['driver' => 'memory']);
        $second = Coordinator::boot([$a, $b], ['driver' => 'memory']);

        self::assertSame($first, $second);
        self::assertSame(2, Coordinator::bootCount());
        self::assertSame(1, WordPressBootstrap::hookCount());
        self::assertSame(1, CliRegistrar::registrationCount());
        self::assertSame(1, AdminRegistrar::registrationCount());
        self::assertSame(1, $GLOBALS['fuzeo_queue_kernel']['migrations_run']);

        $first->consumers()->register(new Origin('fuzeowp/bridge', '0.8.0'));
        $first->consumers()->register(new Origin('fuzeowp/relay', '0.3.0'));
        self::assertSame(2, $first->consumers()->count());

        $first->jobs()->registerJob(ProcessOrderJob::class, new Origin('fuzeowp/bridge', '0.8.0'), ProcessOrderHandler::class);
        $first->jobs()->registerJob(ImportRecordJob::class, new Origin('fuzeowp/relay', '0.3.0'));
        self::assertCount(2, $first->jobs()->all());
    }
}
