<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Drivers\Failure;
use Fuzeo\Queue\Drivers\Memory\MemoryDriver;
use Fuzeo\Queue\Drivers\ReleaseOptions;
use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Support\FrozenClock;
use Fuzeo\Queue\Support\Ulid;
use PHPUnit\Framework\TestCase;

final class MemoryDriverTest extends TestCase
{
    public function testReserveAckAndStaleToken(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $driver = new MemoryDriver($clock);
        $envelope = $this->envelope($clock->now());
        $driver->enqueue($envelope);

        $first = $driver->reserve(new ReserveRequest('default', 'worker-a', 30));
        self::assertNotNull($first);
        self::assertSame(JobState::Reserved, $driver->get($envelope->jobId)->state);

        $clock->set($clock->now()->add(new \DateInterval('PT60S')));
        $second = $driver->reserve(new ReserveRequest('default', 'worker-b', 30));
        self::assertNotNull($second);
        self::assertNotSame($first->token->value, $second->token->value);

        $this->expectException(DriverException::class);
        $driver->acknowledge($first);
    }

    public function testReleaseAndFail(): void
    {
        $driver = new MemoryDriver();
        $envelope = $this->envelope(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $driver->enqueue($envelope);
        $reserved = $driver->reserve(new ReserveRequest('default', 'worker-a'));
        self::assertNotNull($reserved);
        $driver->release($reserved, new ReleaseOptions(delaySeconds: 0));
        self::assertSame(JobState::Pending, $driver->get($envelope->jobId)->state);

        $reserved = $driver->reserve(new ReserveRequest('default', 'worker-a'));
        self::assertNotNull($reserved);
        $driver->fail($reserved, new Failure('boom'));
        self::assertSame(JobState::Failed, $driver->get($envelope->jobId)->state);
        self::assertTrue($driver->capabilities()->supports('delayed_jobs'));
        self::assertFalse($driver->capabilities()->supports('durable'));
    }

    public function testDelayedJobIsNotReservedEarly(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $driver = new MemoryDriver($clock);
        $later = $clock->now()->add(new \DateInterval('PT10M'));
        $driver->enqueue($this->envelope($clock->now(), $later));
        self::assertNull($driver->reserve(new ReserveRequest('default', 'worker-a')));
        $clock->set($later);
        self::assertNotNull($driver->reserve(new ReserveRequest('default', 'worker-a')));
    }

    private function envelope(\DateTimeImmutable $now, ?\DateTimeImmutable $availableAt = null): Envelope
    {
        return new Envelope(
            jobId: Ulid::generate(),
            envelopeVersion: 1,
            jobType: 'acme.process_order',
            schemaVersion: 1,
            payload: ['order_id' => 1],
            queue: 'default',
            priority: 0,
            attempt: 0,
            maxAttempts: 3,
            timeoutSeconds: 60,
            availableAt: $availableAt ?? $now,
            context: ExecutionContext::singleSite(),
            origin: new Origin('acme/shop', '1.0.0'),
            correlationId: null,
            batchId: null,
            chainId: null,
            parentJobId: null,
            idempotencyKey: null,
            uniqueKey: null,
            metadata: [],
            tags: [],
            createdAt: $now,
        );
    }
}
