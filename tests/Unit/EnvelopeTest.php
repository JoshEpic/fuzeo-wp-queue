<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Exceptions\UnsupportedEnvelopeException;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\EnvelopeRedactor;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Support\Ulid;
use PHPUnit\Framework\TestCase;

final class EnvelopeTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $envelope = $this->envelope();
        $restored = Envelope::fromArray($envelope->toArray());
        self::assertSame($envelope->jobId, $restored->jobId);
        self::assertSame($envelope->jobType, $restored->jobType);
        self::assertSame(42, $restored->context->siteId);
        self::assertSame(3, $restored->context->networkId);
        self::assertSame('fuzeowp/bridge', $restored->origin->package);
        self::assertSame(Envelope::VERSION, $restored->envelopeVersion);
        self::assertSame(3, $restored->schemaVersion);
    }

    public function testRejectsUnsupportedEnvelopeVersion(): void
    {
        $data = $this->envelope()->toArray();
        $data['envelope_version'] = Envelope::VERSION + 1;
        $this->expectException(UnsupportedEnvelopeException::class);
        Envelope::fromArray($data);
    }

    public function testRejectsVersionZero(): void
    {
        $data = $this->envelope()->toArray();
        $data['envelope_version'] = 0;
        $this->expectException(UnsupportedEnvelopeException::class);
        Envelope::fromArray($data);
    }

    public function testPreservesExplicitSiteContext(): void
    {
        $envelope = $this->envelope();
        self::assertSame(42, $envelope->context->siteId);
        self::assertNotSame(1, $envelope->context->siteId);
    }

    public function testRedactorOmitsPayloadByDefault(): void
    {
        $summary = EnvelopeRedactor::summarize($this->envelope());
        self::assertArrayNotHasKey('payload', $summary);
        self::assertTrue($summary['payload_redacted']);
    }

    private function envelope(): Envelope
    {
        $now = new \DateTimeImmutable('2026-01-02T03:04:05+00:00');

        return new Envelope(
            jobId: Ulid::generate(1_700_000_000_000),
            envelopeVersion: 1,
            jobType: 'acme.process_order',
            schemaVersion: 3,
            payload: ['order_id' => 123],
            queue: 'fulfillment',
            priority: 5,
            attempt: 0,
            maxAttempts: 3,
            timeoutSeconds: 60,
            availableAt: $now,
            context: ExecutionContext::site(3, 42),
            origin: new Origin('fuzeowp/bridge', '0.8.0'),
            correlationId: Ulid::generate(1_700_000_000_001),
            batchId: null,
            chainId: null,
            parentJobId: null,
            idempotencyKey: 'order:123',
            uniqueKey: null,
            metadata: ['source' => 'checkout'],
            tags: ['orders'],
            createdAt: $now,
            state: JobState::Pending,
        );
    }
}
