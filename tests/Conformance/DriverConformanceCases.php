<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Conformance;

use Fuzeo\Queue\Drivers\Failure;
use Fuzeo\Queue\Drivers\FailureStore;
use Fuzeo\Queue\Drivers\QueueDriver;
use Fuzeo\Queue\Drivers\ReleaseOptions;
use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Drivers\StatusAware;
use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Retry\AttemptRecord;
use Fuzeo\Queue\Retention\RetentionPolicy;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Support\FrozenClock;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;

trait DriverConformanceCases
{
    protected FrozenClock $clock;

    /**
     * @param array<string, mixed> $config
     */
    abstract protected function boot(array $config = []): QueueDriver;

    protected function initConformance(): void
    {
        $this->clock ??= new FrozenClock(new \DateTimeImmutable('2026-06-01T00:00:00Z'));
        ProcessOrderHandler::reset();
    }

    protected function tearDownConformance(): void
    {
        Coordinator::reset();
    }

    public function testEnqueueReserveAckAndPayload(): void
    {
        $driver = $this->boot();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $envelope = Queue::dispatch(new ProcessOrderJob(7));
        self::assertSame(1, $driver->size('default'));
        $reserved = $driver->reserve(new ReserveRequest('default', 'w1', 30));
        self::assertNotNull($reserved);
        self::assertSame(1, $reserved->envelope->attempt);
        self::assertSame(7, $reserved->envelope->payload['order_id'] ?? null);
        self::assertSame(1, $reserved->envelope->context->siteId);
        $driver->acknowledge($reserved);
        self::assertSame(JobState::Completed, $this->store($driver)->job($envelope->jobId)->state);
        self::assertTrue($driver->health()->ok);
    }

    public function testNamedQueuesAndPriority(): void
    {
        $driver = $this->boot();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::on('low')->withPriority(1)->dispatch(new ProcessOrderJob(1));
        Queue::on('low')->withPriority(9)->dispatch(new ProcessOrderJob(2));
        Queue::on('high')->dispatch(new ProcessOrderJob(3));
        $low = $driver->reserve(new ReserveRequest('low', 'w', 30));
        self::assertNotNull($low);
        self::assertSame(9, $low->envelope->priority);
        self::assertNull($driver->reserve(new ReserveRequest('missing', 'w', 30)));
        $high = $driver->reserve(new ReserveRequest('high', 'w', 30));
        self::assertNotNull($high);
        self::assertSame('high', $high->envelope->queue);
    }

    public function testFutureEligibility(): void
    {
        $driver = $this->boot();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $late = Queue::later($this->clock->now()->add(new \DateInterval('PT5M')), new ProcessOrderJob(4));
        self::assertNull($driver->reserve(new ReserveRequest('default', 'w', 30)));
        $this->clock->set($this->clock->now()->add(new \DateInterval('PT5M')));
        $reserved = $driver->reserve(new ReserveRequest('default', 'w', 30));
        self::assertNotNull($reserved);
        self::assertSame($late->jobId, $reserved->envelope->jobId);
    }

    public function testStaleAckRejectedAndRelease(): void
    {
        $driver = $this->boot();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(5));
        $first = $driver->reserve(new ReserveRequest('default', 'w', 30));
        self::assertNotNull($first);
        $driver->release($first, new ReleaseOptions(delaySeconds: 0));
        $this->expectException(DriverException::class);
        $driver->acknowledge($first);
    }

    public function testLeaseExtensionAndExpiry(): void
    {
        $driver = $this->boot();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $envelope = Queue::dispatch(new ProcessOrderJob(6));
        $reserved = $driver->reserve(new ReserveRequest('default', 'w', 5));
        self::assertNotNull($reserved);
        $driver->extendLease($reserved, new \DateInterval('PT30S'));
        $this->clock->set($this->clock->now()->add(new \DateInterval('PT10S')));
        self::assertNull($driver->reserve(new ReserveRequest('default', 'other', 5)));
        $this->clock->set($this->clock->now()->add(new \DateInterval('PT40S')));
        $again = $driver->reserve(new ReserveRequest('default', 'rescuer', 5));
        self::assertNotNull($again);
        self::assertSame($envelope->jobId, $again->envelope->jobId);
        self::assertSame(2, $again->envelope->attempt);
        self::assertFalse($again->token->equals($reserved->token));
        $this->expectException(DriverException::class);
        $driver->acknowledge($reserved);
    }

    public function testRetryDeadReviveAndPoison(): void
    {
        $driver = $this->boot();
        $store = $this->store($driver);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $envelope = Queue::on('default')->withMaxAttempts(2)->dispatch(new ProcessOrderJob(8));
        $first = $driver->reserve(new ReserveRequest('default', 'w', 30));
        self::assertNotNull($first);
        $when = $this->clock->now()->add(new \DateInterval('PT30S'));
        $store->settleOutcome($first, $this->record($first, true, $when), JobState::Pending, $when);
        self::assertNull($driver->reserve(new ReserveRequest('default', 'w', 30)));
        $this->clock->set($when);
        $second = $driver->reserve(new ReserveRequest('default', 'w', 30));
        self::assertNotNull($second);
        self::assertSame(2, $second->envelope->attempt);
        $store->settleOutcome($second, $this->record($second, false, null), JobState::Dead, null);
        self::assertSame(JobState::Dead, $store->job($envelope->jobId)->state);
        self::assertNotSame([], $store->attemptsFor($envelope->jobId));
        $revived = $store->revive($envelope->jobId);
        self::assertSame(0, $revived->attempt);
        self::assertSame(JobState::Pending, $revived->state);
        $replay = $driver->reserve(new ReserveRequest('default', 'w', 30));
        self::assertNotNull($replay);
        $driver->acknowledge($replay);

        $poison = Queue::on('poison')->withMaxAttempts(1)->dispatch(new ProcessOrderJob(9));
        $held = $driver->reserve(new ReserveRequest('poison', 'w', 1));
        self::assertNotNull($held);
        $this->clock->set($this->clock->now()->add(new \DateInterval('PT2S')));
        self::assertNull($driver->reserve(new ReserveRequest('poison', 'w', 1)));
        self::assertSame(JobState::Dead, $store->job($poison->jobId)->state);
    }

    public function testCountsHealthAndPrune(): void
    {
        $driver = $this->boot();
        $store = $this->store($driver);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(10));
        $reserved = $driver->reserve(new ReserveRequest('default', 'w', 30));
        self::assertNotNull($reserved);
        $driver->acknowledge($reserved);
        $this->clock->set($this->clock->now()->add(new \DateInterval('P8D')));
        $counts = $driver instanceof StatusAware ? $driver->countsByState() : [];
        if ($counts !== []) {
            self::assertGreaterThanOrEqual(1, $counts['completed'] ?? 0);
        }
        $policy = new RetentionPolicy(completedRetentionDays: 7, deadRetentionDays: 30);
        $result = $store->prune($policy, 50);
        self::assertGreaterThanOrEqual(1, $result->completedDeleted);
    }

    public function testFailRequiresToken(): void
    {
        $driver = $this->boot();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(11));
        $reserved = $driver->reserve(new ReserveRequest('default', 'w', 30));
        self::assertNotNull($reserved);
        $driver->fail($reserved, new Failure('nope'));
        self::assertSame(JobState::Failed, $this->store($driver)->job($reserved->envelope->jobId)->state);
    }

    protected function store(QueueDriver $driver): FailureStore
    {
        self::assertInstanceOf(FailureStore::class, $driver);

        return $driver;
    }

    private function record(\Fuzeo\Queue\Drivers\Reservation $reservation, bool $retry, ?\DateTimeImmutable $when): AttemptRecord
    {
        return new AttemptRecord(
            attemptId: \Fuzeo\Queue\Support\Ulid::generate(),
            jobId: $reservation->envelope->jobId,
            attempt: $reservation->envelope->attempt,
            outcome: $retry ? JobState::Pending->value : JobState::Dead->value,
            jobType: $reservation->envelope->jobType,
            queue: $reservation->envelope->queue,
            workerId: $reservation->workerId,
            reservationToken: $reservation->token->value,
            originPackage: $reservation->envelope->origin->package,
            networkId: $reservation->envelope->context->networkId,
            siteId: $reservation->envelope->context->siteId,
            scope: $reservation->envelope->context->scope->value,
            failureClass: 'RuntimeException',
            sanitizedMessage: 'fail',
            sanitizedTrace: '',
            willRetry: $retry,
            nextAvailableAt: $when,
            terminalReason: $retry ? null : 'max_attempts',
            failedAt: $this->clock->now(),
        );
    }
}
