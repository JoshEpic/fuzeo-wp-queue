<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\Ulid;
use Fuzeo\Queue\Worker\JobExecutor;

/**
 * Executes adapter-owned Action Scheduler actions. Does not intercept third-party hooks.
 */
final class FallbackCallback
{
    public static function register(): void
    {
        if (!function_exists('add_action')) {
            return;
        }
        add_action(FallbackEnvelope::HOOK, [self::class, 'handle']);
    }

    /**
     * @param array<string, mixed>|mixed $args
     */
    public static function handle(mixed $args = []): void
    {
        if (!is_array($args)) {
            return;
        }
        /** @var array<string, mixed> $args */
        if (($args['fuzeo_runtime'] ?? null) !== 1) {
            return;
        }
        if (!Coordinator::isBooted()) {
            throw new \Fuzeo\Queue\Exceptions\InteropException(
                'Fuzeo Queue runtime is not booted; adapter-owned Action Scheduler work cannot run.'
            );
        }
        $fallback = FallbackEnvelope::fromActionArgs($args);
        $manager = Coordinator::get();
        $now = $manager->clock()->now();
        $envelope = new Envelope(
            jobId: Ulid::generate(),
            envelopeVersion: Envelope::VERSION,
            jobType: $fallback->jobType,
            schemaVersion: $fallback->schemaVersion,
            payload: $fallback->payload,
            queue: $fallback->queue,
            priority: 0,
            attempt: 1,
            maxAttempts: 1,
            timeoutSeconds: 60,
            availableAt: Dates::utc($now),
            context: $fallback->context,
            origin: $fallback->origin,
            correlationId: null,
            batchId: null,
            chainId: null,
            parentJobId: null,
            idempotencyKey: null,
            uniqueKey: null,
            metadata: ['_runtime' => RuntimeName::ActionScheduler->value],
            tags: ['interop-fallback'],
            createdAt: Dates::utc($now),
            state: JobState::Reserved,
        );
        (new JobExecutor($manager->jobs()))->execute($envelope);
    }
}
