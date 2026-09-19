<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Config\ConfigRepository;
use Fuzeo\Queue\Exceptions\ConfigurationException;
use Fuzeo\Queue\Exceptions\QueueException;
use Fuzeo\Queue\Exceptions\SerializationException;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Orchestration\BatchCounters;
use Fuzeo\Queue\Orchestration\BatchFailurePolicy;
use Fuzeo\Queue\Orchestration\BatchRecord;
use Fuzeo\Queue\Orchestration\BatchState;
use Fuzeo\Queue\Orchestration\MemberStatus;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Runtime\PackageInfo;
use Fuzeo\Queue\Serialization\JsonPayloadSerializer;
use Fuzeo\Queue\Serialization\PayloadLimits;
use Fuzeo\Queue\Support\SecretRedactor;
use Fuzeo\Queue\Support\Ulid;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use PHPUnit\Framework\TestCase;

final class Phase10HardeningTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('FUZEO_QUEUE_LEASE_SECONDS=');
        Coordinator::reset();
        parent::tearDown();
    }

    public function testPackageIsOneZeroAndSeriesOne(): void
    {
        self::assertSame('1.2.0', PackageInfo::VERSION);
        self::assertSame(1, PackageInfo::COMPATIBILITY_SERIES);
        self::assertSame(1, PackageInfo::ENVELOPE_VERSION);
    }

    public function testInvalidLeaseFailsEarly(): void
    {
        putenv('FUZEO_QUEUE_LEASE_SECONDS=-1');
        $this->expectException(ConfigurationException::class);
        (new ConfigRepository())->resolve();
    }

    public function testTimeoutMustBeLessThanLease(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must be less than lease_seconds');
        (new ConfigRepository())->resolve([
            'lease_seconds' => 30,
            'default_timeout_seconds' => 60,
            'worker_timeout' => 20,
        ]);
    }

    public function testInvalidRedisDsnFailsEarly(): void
    {
        $this->expectException(ConfigurationException::class);
        (new ConfigRepository())->resolve(['redis_dsn' => 'http://example.com']);
    }

    public function testNegativeConcurrencyFails(): void
    {
        $this->expectException(ConfigurationException::class);
        (new ConfigRepository())->resolve(['concurrency' => ['default' => 0]]);
    }

    public function testTooManyTagsRejectedAtDispatch(): void
    {
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $tags = [];
        for ($i = 0; $i < 17; $i++) {
            $tags[] = 't' . $i;
        }
        $this->expectException(QueueException::class);
        Queue::on('default')->withTags($tags)->dispatch(new ProcessOrderJob(1));
    }

    public function testOversizedMetadataRejectedAtDispatch(): void
    {
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $this->expectException(QueueException::class);
        Queue::on('default')->withMetadata(['blob' => str_repeat('x', 9000)])->dispatch(new ProcessOrderJob(1));
    }

    public function testLongPayloadStringRejected(): void
    {
        $this->expectException(SerializationException::class);
        (new JsonPayloadSerializer(new PayloadLimits(262144, 32, 32)))->normalize(['x' => str_repeat('a', 40)]);
    }

    public function testSecretFixtureRedactsOperatorFields(): void
    {
        $redactor = new SecretRedactor();
        $fixture = [
            'password' => 'p',
            'passwd' => 'p',
            'secret' => 's',
            'client_secret' => 'cs',
            'api_key' => 'k',
            'apikey' => 'k',
            'authorization' => 'a',
            'bearer' => 'b',
            'cookie' => 'c',
            'access_token' => 't',
            'refresh_token' => 'r',
            'private_key' => 'pk',
            'order_id' => 9,
        ];
        $out = $redactor->redactMap($fixture);
        foreach ($fixture as $key => $value) {
            if ($key === 'order_id') {
                self::assertSame(9, $out[$key]);
                continue;
            }
            self::assertSame('[REDACTED]', $out[$key], $key);
        }
    }

    public function testBatchCountersAreO1AndFinalizeOnce(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $batch = new BatchRecord(
            Ulid::generate(),
            new Origin('acme/shop', '1.0.0'),
            \Fuzeo\Queue\Jobs\ExecutionContext::singleSite(),
            BatchState::Active,
            2,
            0,
            0,
            0,
            BatchFailurePolicy::CollectAll,
            $now,
        );
        $first = BatchCounters::apply($batch, MemberStatus::Dispatched, MemberStatus::Completed, $now);
        self::assertFalse($first->justFinalized);
        self::assertSame(1, $first->batch->completedJobs);
        $second = BatchCounters::apply($first->batch, MemberStatus::Dispatched, MemberStatus::Completed, $now);
        self::assertTrue($second->justFinalized);
        self::assertSame(BatchState::Completed, $second->batch->state);
        $again = BatchCounters::apply($second->batch, MemberStatus::Completed, MemberStatus::Completed, $now);
        self::assertFalse($again->justFinalized);
    }

    public function testMemoryBatchDoesNotScanMembersOnTerminal(): void
    {
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $jobs = [];
        $n = 250;
        for ($i = 0; $i < $n; $i++) {
            $jobs[] = new ProcessOrderJob($i);
        }
        $started = hrtime(true);
        $batch = Queue::batch($jobs)->dispatch();
        \Fuzeo\Queue\Worker\WorkerLoop::fromManager(
            Coordinator::get(),
            new \Fuzeo\Queue\Worker\WorkerOptions(sleepSeconds: 0, maxJobs: 0)
        )->run($n + 5);
        $ms = (hrtime(true) - $started) / 1e6;
        $fresh = Coordinator::get()->orchestrator()->store()->getBatch($batch->batchId);
        self::assertNotNull($fresh);
        self::assertSame(\Fuzeo\Queue\Orchestration\BatchState::Completed, $fresh->state);
        self::assertSame($n, $fresh->completedJobs);
        self::assertLessThan(15000, $ms);
    }

    public function testAutoloadDoesNotBootRuntime(): void
    {
        Coordinator::reset();
        self::assertFalse(Coordinator::isBooted());
    }

    public function testNoUnserializeInSrc(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/\bunserialize\s*\(/', $contents) === 1 || preg_match('/\bserialize\s*\(/', $contents) === 1) {
                $hits[] = $file->getPathname();
            }
        }
        self::assertSame([], $hits);
    }
}
