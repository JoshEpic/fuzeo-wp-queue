<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Exceptions\RetryAfterException;
use Fuzeo\Queue\Exceptions\RetryableException;
use Fuzeo\Queue\Exceptions\TerminalException;
use Fuzeo\Queue\Exceptions\UnknownJobException;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Retry\FailureClassifier;
use Fuzeo\Queue\Retry\FixedBackoff;
use Fuzeo\Queue\Retry\RetryPolicy;
use Fuzeo\Queue\Support\FrozenClock;
use Fuzeo\Queue\Support\Ulid;
use PHPUnit\Framework\TestCase;

final class FailureClassifierTest extends TestCase
{
    public function testRetryableUntilExhausted(): void
    {
        $classifier = new FailureClassifier();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $policy = new RetryPolicy(3, new FixedBackoff(30));
        $first = $classifier->decide(new RetryableException('timeout'), $this->envelope(1), $policy, $clock);
        self::assertTrue($first->willRetry);
        self::assertSame('pending', $first->nextState->value);

        $last = $classifier->decide(new RetryableException('timeout'), $this->envelope(3), $policy, $clock);
        self::assertFalse($last->willRetry);
        self::assertSame('dead', $last->nextState->value);
        self::assertSame('attempts_exhausted', $last->terminalReason);
    }

    public function testTerminalAndUnknownType(): void
    {
        $classifier = new FailureClassifier();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $policy = new RetryPolicy(5, new FixedBackoff(1));
        $terminal = $classifier->decide(new TerminalException('bad'), $this->envelope(1), $policy, $clock);
        self::assertFalse($terminal->willRetry);
        $unknown = $classifier->decide(new UnknownJobException('gone'), $this->envelope(1), $policy, $clock);
        self::assertSame('unknown_job_type', $unknown->terminalReason);
        $type = $classifier->decide(new \TypeError('nope'), $this->envelope(1), $policy, $clock);
        self::assertFalse($type->willRetry);
    }

    public function testRetryAfterOverridesDelay(): void
    {
        $classifier = new FailureClassifier();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $policy = new RetryPolicy(3, new FixedBackoff(1));
        $decision = $classifier->decide(RetryAfterException::after(120), $this->envelope(1), $policy, $clock);
        self::assertNotNull($decision->availableAt);
        self::assertSame('2026-01-01T00:02:00+00:00', $decision->availableAt->format(\DateTimeInterface::ATOM));
    }

    private function envelope(int $attempt): Envelope
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');

        return new Envelope(
            jobId: Ulid::generate(),
            envelopeVersion: 1,
            jobType: 'acme.process_order',
            schemaVersion: 1,
            payload: ['order_id' => 1],
            queue: 'default',
            priority: 0,
            attempt: $attempt,
            maxAttempts: 3,
            timeoutSeconds: 60,
            availableAt: $now,
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
