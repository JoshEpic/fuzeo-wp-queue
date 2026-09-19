<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Idempotency\IdempotencyStatus;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Schedule\CatchUpPolicy;
use Fuzeo\Queue\Schedule\OverlapPolicy;
use Fuzeo\Queue\Support\FrozenClock;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Tests\Support\UniqueProductJob;
use PHPUnit\Framework\TestCase;

final class Phase5PrimitivesTest extends TestCase
{
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-06-01T00:00:00Z'));
        Coordinator::bootForTesting(['driver' => 'memory'], clock: $this->clock);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::register(UniqueProductJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
    }

    protected function tearDown(): void
    {
        Coordinator::reset();
    }

    public function testPastDelayIsImmediatelyEligible(): void
    {
        $driver = Coordinator::get()->driver();
        $past = $this->clock->now()->sub(new \DateInterval('PT5M'));
        $envelope = Queue::later($past, new ProcessOrderJob(1));
        self::assertSame($past->getTimestamp(), $envelope->availableAt->getTimestamp());
        self::assertNotNull($driver->reserve(new ReserveRequest('default', 'w', 30)));
    }

    public function testRelativeDelayString(): void
    {
        $envelope = Queue::later('+15 minutes', new ProcessOrderJob(2));
        self::assertSame(
            $this->clock->now()->add(new \DateInterval('PT15M'))->getTimestamp(),
            $envelope->availableAt->getTimestamp()
        );
        self::assertNull(Coordinator::get()->driver()->reserve(new ReserveRequest('default', 'w', 30)));
    }

    public function testUniqueDispatchSuppressesDuplicate(): void
    {
        $first = Queue::dispatchResult(new UniqueProductJob(9));
        $second = Queue::dispatchResult(new UniqueProductJob(9));
        self::assertTrue($first->accepted);
        self::assertFalse($second->accepted);
        self::assertSame($first->jobId(), $second->duplicateOf);
        self::assertSame(1, Coordinator::get()->driver()->size('default'));
    }

    public function testUniqueHeldThroughRetryAndReleasedOnDead(): void
    {
        $driver = Coordinator::get()->driver();
        Queue::on('default')->withMaxAttempts(2)->dispatch(new UniqueProductJob(3));
        $first = $driver->reserve(new ReserveRequest('default', 'w', 30));
        self::assertNotNull($first);
        $when = $this->clock->now()->add(new \DateInterval('PT1S'));
        $store = $driver;
        self::assertInstanceOf(\Fuzeo\Queue\Drivers\FailureStore::class, $store);
        $store->settleOutcome($first, $this->record($first, true, $when), \Fuzeo\Queue\Jobs\JobState::Pending, $when);
        $dup = Queue::dispatchResult(new UniqueProductJob(3));
        self::assertFalse($dup->accepted);
        $this->clock->set($when);
        $second = $driver->reserve(new ReserveRequest('default', 'w', 30));
        self::assertNotNull($second);
        $store->settleOutcome($second, $this->record($second, false, null), \Fuzeo\Queue\Jobs\JobState::Dead, null);
        $again = Queue::dispatchResult(new UniqueProductJob(3));
        self::assertTrue($again->accepted);
    }

    public function testManualRetryConflictsWhenUniqueReacquired(): void
    {
        $driver = Coordinator::get()->driver();
        Queue::on('default')->withMaxAttempts(1)->dispatch(new UniqueProductJob(4));
        $reserved = $driver->reserve(new ReserveRequest('default', 'w', 30));
        self::assertNotNull($reserved);
        $store = $driver;
        self::assertInstanceOf(\Fuzeo\Queue\Drivers\FailureStore::class, $store);
        $store->settleOutcome($reserved, $this->record($reserved, false, null), \Fuzeo\Queue\Jobs\JobState::Dead, null);
        Queue::dispatchResult(new UniqueProductJob(4));
        $this->expectException(\Fuzeo\Queue\Exceptions\UniqueConflictException::class);
        $store->revive($reserved->envelope->jobId);
    }

    public function testIdempotencyBeginCompleteAndStaleOwner(): void
    {
        $api = Queue::idempotency();
        $a = $api->begin('invoice-charge:1');
        $b = $api->begin('invoice-charge:1');
        self::assertTrue($a->owned);
        self::assertFalse($b->owned);
        self::assertSame(IdempotencyStatus::Started, $b->status);
        $api->complete((string) $a->ownerToken, ['ok' => true]);
        $c = $api->begin('invoice-charge:1');
        self::assertFalse($c->owned);
        self::assertSame(IdempotencyStatus::Completed, $c->status);
        $this->expectException(\Fuzeo\Queue\Exceptions\DriverException::class);
        $api->complete((string) $a->ownerToken, ['nope' => true]);
    }

    public function testIdempotencyCrashLeavesStartedAmbiguity(): void
    {
        $api = Queue::idempotency();
        $begin = $api->begin('side-effect:1', leaseSeconds: 30);
        self::assertTrue($begin->owned);
        $lookup = $api->lookup('side-effect:1');
        self::assertNotNull($lookup);
        self::assertSame(IdempotencyStatus::Started, $lookup->status);
        $this->clock->advance(31);
        $again = $api->begin('side-effect:1');
        self::assertTrue($again->owned);
    }

    public function testScheduleDispatchesOrdinaryJob(): void
    {
        Queue::schedule()
            ->job('tick', new ProcessOrderJob(50))
            ->everySeconds(60)
            ->save();
        self::assertSame(0, Coordinator::get()->scheduler()->runDue());
        $this->clock->advance(60);
        self::assertSame(1, Coordinator::get()->scheduler()->runDue());
        self::assertSame(1, Coordinator::get()->driver()->size('default'));
        $this->clock->advance(60);
        self::assertSame(1, Coordinator::get()->scheduler()->runDue());
        self::assertSame(2, Coordinator::get()->driver()->size('default'));
    }

    public function testScheduleClaimLeaseCanBeRecovered(): void
    {
        $store = Coordinator::get()->schedules()->store();
        $now = $this->clock->now();
        $ok = $store->claimOccurrence('occ-1', 'sched-1', $now, 'token-a', $now->add(new \DateInterval('PT1S')));
        self::assertTrue($ok);
        self::assertFalse($store->claimOccurrence('occ-1', 'sched-1', $now, 'token-b', $now->add(new \DateInterval('PT10S'))));
        $this->clock->advance(2);
        self::assertTrue($store->claimOccurrence('occ-1', 'sched-1', $now, 'token-b', $now->add(new \DateInterval('PT10S'))));
    }

    public function testCatchUpSkipDoesNotFlood(): void
    {
        Queue::schedule()
            ->job('hourly', new ProcessOrderJob(51))
            ->everySeconds(3600)
            ->catchUp(CatchUpPolicy::Skip)
            ->save();
        $this->clock->advance(3600 * 4);
        Coordinator::get()->scheduler()->runDue();
        self::assertSame(0, Coordinator::get()->driver()->size('default'));
    }

    public function testOverlapSkipUsesUniqueness(): void
    {
        Queue::schedule()
            ->job('sync', new ProcessOrderJob(52))
            ->everySeconds(60)
            ->overlap(OverlapPolicy::Skip)
            ->save();
        $this->clock->advance(60);
        Coordinator::get()->scheduler()->runDue();
        $this->clock->advance(60);
        Coordinator::get()->scheduler()->runDue();
        self::assertSame(1, Coordinator::get()->driver()->size('default'));
    }

    public function testDeletedSiteIsBlocked(): void
    {
        $runtime = Coordinator::get();
        $schedule = Queue::schedule()
            ->job('site-job', new ProcessOrderJob(70))
            ->everySeconds(60)
            ->onSite(1, 42)
            ->save();
        $scheduler = new \Fuzeo\Queue\Schedule\Scheduler(
            $runtime->schedules()->store(),
            $runtime->dispatcher(),
            $runtime->jobs(),
            $runtime->unique(),
            $this->clock,
            new \Fuzeo\Queue\Schedule\MappedSitePresence([]),
        );
        $this->clock->advance(60);
        self::assertSame(0, $scheduler->runDue());
        $blocked = $runtime->schedules()->get($schedule->scheduleId);
        self::assertSame('site_deleted', $blocked?->blockedReason);
    }

    public function testUnavailableOriginBlocksSchedule(): void
    {
        Queue::schedule()->job('gone', new ProcessOrderJob(80))->everySeconds(60)->save();
        Coordinator::get()->jobs()->forget(ProcessOrderJob::type());
        $this->clock->advance(60);
        self::assertSame(0, Coordinator::get()->scheduler()->runDue());
        $rows = Coordinator::get()->schedules()->all();
        self::assertSame('origin_unavailable', $rows[0]->blockedReason);
    }

    private function record(\Fuzeo\Queue\Drivers\Reservation $reservation, bool $retry, ?\DateTimeImmutable $when): \Fuzeo\Queue\Retry\AttemptRecord
    {
        return new \Fuzeo\Queue\Retry\AttemptRecord(
            attemptId: \Fuzeo\Queue\Support\Ulid::generate(),
            jobId: $reservation->envelope->jobId,
            attempt: $reservation->envelope->attempt,
            outcome: $retry ? 'pending' : 'dead',
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
