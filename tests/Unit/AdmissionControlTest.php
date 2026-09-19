<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Concurrency\AdmissionPolicy;
use Fuzeo\Queue\Concurrency\ConcurrencyLimit;
use Fuzeo\Queue\Drivers\Memory\MemoryDriver;
use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\RateLimit\RateLimit;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Support\FrozenClock;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Tests\Support\RateLimitedOrderJob;
use PHPUnit\Framework\TestCase;

final class AdmissionControlTest extends TestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testConcurrencyLimitSkipsWithoutAttempt(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $driver = new MemoryDriver($clock, new AdmissionPolicy(['imports' => 1]));
        Coordinator::bootForTesting(['driver' => 'memory', 'concurrency' => ['imports' => 1]], $driver, clock: $clock);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::on('imports')->dispatch(new ProcessOrderJob(1));
        $two = Queue::on('imports')->dispatch(new ProcessOrderJob(2));
        $first = $driver->reserve(new ReserveRequest('imports', 'a', 30));
        self::assertNotNull($first);
        self::assertNull($driver->reserve(new ReserveRequest('imports', 'b', 30)));
        self::assertSame(0, $driver->get($two->jobId)->attempt);
        $driver->acknowledge($first);
        $next = $driver->reserve(new ReserveRequest('imports', 'b', 30));
        self::assertNotNull($next);
        self::assertSame(1, $next->envelope->attempt);
    }

    public function testRateLimitDelaysWithoutAttempt(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        Coordinator::bootForTesting(['driver' => 'memory'], clock: $clock);
        Queue::register(RateLimitedOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new RateLimitedOrderJob(1));
        $second = Queue::dispatch(new RateLimitedOrderJob(2));
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MemoryDriver::class, $driver);
        $first = $driver->reserve(new ReserveRequest('default', 'a', 30));
        self::assertNotNull($first);
        $driver->acknowledge($first);
        self::assertNull($driver->reserve(new ReserveRequest('default', 'a', 30)));
        self::assertSame(0, $driver->get($second->jobId)->attempt);
        $clock->set($clock->now()->add(new \DateInterval('PT2S')));
        $again = $driver->reserve(new ReserveRequest('default', 'a', 30));
        self::assertNotNull($again);
        self::assertSame(1, $again->envelope->attempt);
    }

    public function testPolicyApiAndDriverSwitchDiagnostic(): void
    {
        $limit = ConcurrencyLimit::max(4)->forQueue('imports');
        self::assertSame(4, $limit->forQueue('imports'));
        $rate = RateLimit::perMinute(100)->withKey('vendor-api');
        self::assertSame('vendor-api', $rate->key);
        Coordinator::bootForTesting(['driver' => 'memory']);
        Coordinator::reset();
        Coordinator::bootForTesting(['driver' => 'unavailable']);
        $codes = array_map(static fn ($d) => $d->code, Coordinator::diagnostics());
        self::assertContains('driver_switch', $codes);
    }

    public function testMissingCapabilityFailsAtWorkerStart(): void
    {
        Coordinator::bootForTesting(['driver' => 'unavailable', 'concurrency' => ['imports' => 1]]);
        $this->expectException(\Fuzeo\Queue\Exceptions\ConfigurationException::class);
        \Fuzeo\Queue\Worker\WorkerLoop::fromManager(
            Coordinator::get(),
            new \Fuzeo\Queue\Worker\WorkerOptions(sleepSeconds: 0, maxJobs: 1)
        );
    }
}
