<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\EnvelopeRedactor;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Retention\RetentionPolicy;
use Fuzeo\Queue\Support\SecretRedactor;
use Fuzeo\Queue\Support\TraceSanitizer;
use Fuzeo\Queue\Support\Ulid;
use PHPUnit\Framework\TestCase;

final class RedactionAndRetentionTest extends TestCase
{
    public function testSecretKeysAndMessages(): void
    {
        $redactor = new SecretRedactor();
        $out = $redactor->redactMap([
            'order_id' => 1,
            'password' => 'hunter2',
            'nested' => ['api_key' => 'abc', 'ok' => true],
        ]);
        self::assertSame(1, $out['order_id']);
        self::assertSame('[REDACTED]', $out['password']);
        self::assertIsArray($out['nested']);
        self::assertSame('[REDACTED]', $out['nested']['api_key']);
        self::assertStringContainsString('[REDACTED]', $redactor->redactMessage('Authorization: Bearer super-secret'));
    }

    public function testTraceOmitsArgumentsAndLimitsSize(): void
    {
        try {
            $this->throwDeep('token=abc');
        } catch (\Throwable $throwable) {
            $trace = TraceSanitizer::sanitize($throwable);
            self::assertStringNotContainsString('token=abc', $trace);
            self::assertLessThanOrEqual(TraceSanitizer::MAX_BYTES, strlen($trace));
            self::assertStringContainsString('throwDeep', $trace);
        }
    }

    public function testEnvelopePayloadRedaction(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $envelope = new Envelope(
            jobId: Ulid::generate(),
            envelopeVersion: 1,
            jobType: 'acme.process_order',
            schemaVersion: 1,
            payload: ['order_id' => 1, 'token' => 'secret'],
            queue: 'default',
            priority: 0,
            attempt: 0,
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
        $summary = EnvelopeRedactor::summarize($envelope, true);
        self::assertIsArray($summary['payload']);
        self::assertSame('[REDACTED]', $summary['payload']['token']);
        self::assertSame(1, $summary['payload']['order_id']);
    }

    public function testRetentionCutoff(): void
    {
        $policy = new RetentionPolicy(7, 30);
        $now = new \DateTimeImmutable('2026-02-01T00:00:00Z');
        self::assertSame('2026-01-25T00:00:00+00:00', $policy->completedBefore($now)->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-01-02T00:00:00+00:00', $policy->deadBefore($now)->format(\DateTimeInterface::ATOM));
    }

    private function throwDeep(string $secret): void
    {
        throw new \RuntimeException('failed with ' . $secret);
    }
}
