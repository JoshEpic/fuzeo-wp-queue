<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\MultipleBundle;

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Candidate;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Runtime\PackageInfo;
use Fuzeo\Queue\Tests\Support\ImportRecordJob;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\WordPress\Admin\AdminRegistrar;
use Fuzeo\Queue\WordPress\Cli\CliRegistrar;
use PHPUnit\Framework\TestCase;

final class ConsumerRemovalTest extends TestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
    }

    public function testRemainingConsumerCanEstablishRuntimeAfterOriginalProviderRemoved(): void
    {
        $a = new Candidate('1.0.0', PackageInfo::COMPATIBILITY_SERIES, '/plugins/a', 'a.php');
        $b = new Candidate('1.0.0', PackageInfo::COMPATIBILITY_SERIES, '/plugins/b', 'b.php');
        Coordinator::boot([$a, $b], ['driver' => 'memory']);
        Queue::register(ProcessOrderJob::class, new Origin('acme/alpha', '1.0.0'), ProcessOrderHandler::class);
        Coordinator::reset();

        $runtime = Coordinator::boot([$b], ['driver' => 'memory']);
        $runtime->consumers()->register(new Origin('acme/beta', '1.0.0'));
        Queue::register(ImportRecordJob::class, new Origin('acme/beta', '1.0.0'), ProcessOrderHandler::class);
        Queue::fake();
        Queue::dispatch(new ImportRecordJob('ok'));
        Queue::assertDispatched(ImportRecordJob::class);
        self::assertSame(1, CliRegistrar::registrationCount());
        self::assertSame(1, AdminRegistrar::registrationCount());
        self::assertFalse($runtime->jobs()->has('acme.process_order'));
        self::assertTrue($runtime->jobs()->has('acme.import_record'));
    }
}
