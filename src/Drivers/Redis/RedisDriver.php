<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Drivers\Redis;

use Fuzeo\Queue\Concurrency\AdmissionPolicy;
use Fuzeo\Queue\Config\Config;
use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Drivers\DriverCapabilities;
use Fuzeo\Queue\Drivers\DriverHealth;
use Fuzeo\Queue\Drivers\EnqueuedJob;
use Fuzeo\Queue\Drivers\Failure;
use Fuzeo\Queue\Drivers\FailureStore;
use Fuzeo\Queue\Drivers\ProvidesIdempotencyStore;
use Fuzeo\Queue\Drivers\ProvidesScheduleStore;
use Fuzeo\Queue\Drivers\ProvidesUniqueStore;
use Fuzeo\Queue\Drivers\ProvidesWorkerStore;
use Fuzeo\Queue\Drivers\QueueDriver;
use Fuzeo\Queue\Drivers\Reconnectable;
use Fuzeo\Queue\Drivers\ReliableAcknowledger;
use Fuzeo\Queue\Drivers\ReleaseOptions;
use Fuzeo\Queue\Drivers\Reservation;
use Fuzeo\Queue\Drivers\ReservationToken;
use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Drivers\StatusAware;
use Fuzeo\Queue\Exceptions\AmbiguousAckException;
use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\QueueName;
use Fuzeo\Queue\Redis\RedisClient;
use Fuzeo\Queue\Redis\RedisKeys;
use Fuzeo\Queue\Redis\RedisLock;
use Fuzeo\Queue\Redis\RedisScripts;
use Fuzeo\Queue\Redis\RedisSettings;
use Fuzeo\Queue\Redis\RedisWorkerStore;
use Fuzeo\Queue\Redis\ScriptCache;
use Fuzeo\Queue\Support\Dates;
use Fuzeo\Queue\Support\SystemClock;
use Fuzeo\Queue\Retention\PruneResult;
use Fuzeo\Queue\Retention\RetentionPolicy;
use Fuzeo\Queue\Idempotency\IdempotencyStore;
use Fuzeo\Queue\Idempotency\RedisIdempotencyStore;
use Fuzeo\Queue\Schedule\RedisScheduleStore;
use Fuzeo\Queue\Schedule\ScheduleStore;
use Fuzeo\Queue\Unique\RedisUniqueStore;
use Fuzeo\Queue\Unique\UniqueIdentity;
use Fuzeo\Queue\Unique\UniquePolicy;
use Fuzeo\Queue\Unique\UniqueStore;
use Fuzeo\Queue\Retry\AttemptRecord;
use Fuzeo\Queue\Worker\WorkerStore;

final class RedisDriver implements QueueDriver, FailureStore, ReliableAcknowledger, Reconnectable, ProvidesWorkerStore, StatusAware, ProvidesUniqueStore, ProvidesIdempotencyStore, ProvidesScheduleStore
{
    public const MIN_REDIS_VERSION = '6.0.0';

    private readonly ScriptCache $scripts;

    private readonly RedisKeys $keys;

    private readonly AdmissionPolicy $admission;

    private readonly RedisUniqueStore $uniques;

    private readonly RedisIdempotencyStore $idempotency;

    private readonly RedisScheduleStore $schedules;

    private int $lastThrottleWait = 0;

    public function __construct(
        private readonly RedisClient $redis,
        private readonly RedisSettings $settings,
        private readonly Clock $clock = new SystemClock(),
        private readonly Config $config = new Config(),
    ) {
        $this->keys = new RedisKeys($settings->prefix());
        $this->scripts = new ScriptCache($redis);
        $this->admission = AdmissionPolicy::fromConfig($config);
        $this->uniques = new RedisUniqueStore($redis, $this->keys, $this->scripts);
        $this->idempotency = new RedisIdempotencyStore($redis, $this->keys, $this->scripts, $clock);
        $this->schedules = new RedisScheduleStore($redis, $this->keys, $clock);
        $this->assertVersion();
        $this->redis->command('HSET', [$this->keys->meta(), 'driver', 'redis', 'package', 'fuzeowp/queue', 'schema_version', '4']);
    }

    public function uniqueStore(): UniqueStore
    {
        return $this->uniques;
    }

    public function idempotencyStore(): IdempotencyStore
    {
        return $this->idempotency;
    }

    public function scheduleStore(): ScheduleStore
    {
        return $this->schedules;
    }

    public function client(): RedisClient
    {
        return $this->redis;
    }

    public function settings(): RedisSettings
    {
        return $this->settings;
    }

    public function lock(): RedisLock
    {
        return new RedisLock($this->redis, $this->keys, $this->scripts);
    }

    public function workerStore(): WorkerStore
    {
        return new RedisWorkerStore($this->redis, $this->keys, $this->clock, max(30, $this->config->staleWorkerSeconds * 3));
    }

    public function enqueue(Envelope $envelope): EnqueuedJob
    {
        if ($envelope->state !== JobState::Pending) {
            throw new DriverException('Only pending envelopes can be enqueued.');
        }
        QueueName::assertValid($envelope->queue);
        $now = $this->clock->now()->getTimestamp();
        $available = $envelope->availableAt->getTimestamp();
        $identity = UniqueIdentity::forJob($envelope);
        $uniqueKey = $identity !== null ? $this->keys->unique($identity->hash) : '';
        $ttl = UniquePolicy::ttlSeconds($envelope) ?? 0;
        $raw = $this->scripts->run(
            'enqueue',
            RedisScripts::ENQUEUE,
            [
                $this->keys->job($envelope->jobId),
                $this->keys->ready($envelope->queue),
                $this->keys->queues(),
                $this->keys->wakeup($envelope->queue),
                $this->keys->delayed($envelope->queue),
            ],
            [
                $this->encode($envelope),
                JobState::Pending->value,
                (string) $available,
                $this->readyScore($envelope->priority, $available),
                $envelope->queue,
                $envelope->jobId,
                (string) $now,
                $uniqueKey,
                (string) $ttl,
            ]
        );
        $result = is_array($raw) ? $raw : [1, $envelope->jobId];
        $accepted = (int) ($result[0] ?? 1) === 1;
        if (!$accepted) {
            $existingId = (string) ($result[1] ?? '');
            try {
                $existing = $existingId !== '' ? $this->job($existingId) : $envelope;
            } catch (DriverException) {
                $existing = $envelope;
            }

            return new EnqueuedJob($existing, false, $existingId !== '' ? $existingId : null);
        }

        return new EnqueuedJob($envelope);
    }

    public function reserve(ReserveRequest $request): ?Reservation
    {
        $now = $this->clock->now()->getTimestamp();
        $lease = $now + $request->leaseSeconds;
        $token = ReservationToken::generate();
        $rate = null;
        foreach ($this->admission->globalRateLimits as $limit) {
            if ($limit->key === $request->queue) {
                $rate = $limit;
                break;
            }
        }
        $limit = $this->admission->concurrencyFor($request->queue) ?? 0;
        $result = $this->luaList($this->scripts->run(
            'reserve',
            RedisScripts::RESERVE,
            [],
            [
                $this->keys->prefix(),
                $request->queue,
                (string) $now,
                (string) $lease,
                $request->workerId,
                $token->value,
                (string) $limit,
                $rate?->key ?? '',
                (string) ($rate?->capacity ?? 0),
                (string) ($rate?->refillPerSecond ?? 0),
            ]
        ));
        if ($result === []) {
            return $this->blockOrNull($request);
        }
        $status = (string) ($result[0] ?? '');
        if ($status === 'empty') {
            return $this->blockOrNull($request);
        }
        if ($status === 'throttled') {
            $this->lastThrottleWait = isset($result[2]) && is_numeric($result[2]) ? (int) $result[2] : 1;

            return null;
        }
        if ($status !== 'ok') {
            return null;
        }
        $json = (string) ($result[1] ?? '');
        $envelope = $this->decode($json);
        $leaseAt = (new \DateTimeImmutable('@' . $lease))->setTimezone(new \DateTimeZone('UTC'));
        $reservedAt = $this->clock->now();

        return new Reservation($envelope, $token, $reservedAt, $leaseAt, $request->workerId);
    }

    public function lastThrottleWaitSeconds(): int
    {
        return $this->lastThrottleWait;
    }

    public function acknowledge(Reservation $reservation): void
    {
        $now = (string) $this->clock->now()->getTimestamp();
        $ok = $this->scripts->run(
            'ack',
            RedisScripts::ACK,
            [
                $this->keys->job($reservation->envelope->jobId),
                $this->keys->reserved(),
                $this->keys->completed(),
                $this->keys->concurrency($reservation->envelope->queue),
            ],
            [$reservation->token->value, $reservation->envelope->jobId, $now]
        );
        if ((int) $ok !== 1) {
            throw new DriverException(
                'Reservation token is not the active owner of job ' . $reservation->envelope->jobId . '.'
            );
        }
    }

    public function acknowledgeOrAmbiguous(Reservation $reservation): void
    {
        try {
            $this->acknowledge($reservation);
        } catch (DriverException $exception) {
            if ($this->isConnectivity($exception)) {
                throw new AmbiguousAckException(
                    'ACK could not be persisted. The job must not be treated as complete and may run again.',
                    0,
                    $exception
                );
            }
            throw $exception;
        }
    }

    public function release(Reservation $reservation, ReleaseOptions $options): void
    {
        $nowTs = $this->clock->now()->getTimestamp();
        $availableAt = $options->availableAt ?? $this->clock->now()->add(new \DateInterval('PT' . $options->delaySeconds . 'S'));
        $available = $availableAt->getTimestamp();
        $ok = $this->scripts->run(
            'release',
            RedisScripts::RELEASE,
            [
                $this->keys->job($reservation->envelope->jobId),
                $this->keys->reserved(),
                $this->keys->concurrency($reservation->envelope->queue),
                $this->keys->ready($reservation->envelope->queue),
                $this->keys->delayed($reservation->envelope->queue),
            ],
            [
                $reservation->token->value,
                $reservation->envelope->jobId,
                (string) $available,
                $this->readyScore($reservation->envelope->priority, $available),
                (string) $nowTs,
                Dates::toAtom($availableAt),
            ]
        );
        if ((int) $ok !== 1) {
            throw new DriverException(
                'Reservation token is not the active owner of job ' . $reservation->envelope->jobId . '.'
            );
        }
    }

    public function fail(Reservation $reservation, Failure $failure): void
    {
        $record = new AttemptRecord(
            attemptId: \Fuzeo\Queue\Support\Ulid::generate(),
            jobId: $reservation->envelope->jobId,
            attempt: $reservation->envelope->attempt,
            outcome: JobState::Failed->value,
            jobType: $reservation->envelope->jobType,
            queue: $reservation->envelope->queue,
            workerId: $reservation->workerId,
            reservationToken: $reservation->token->value,
            originPackage: $reservation->envelope->origin->package,
            networkId: $reservation->envelope->context->networkId,
            siteId: $reservation->envelope->context->siteId,
            scope: $reservation->envelope->context->scope->value,
            failureClass: $failure->class,
            sanitizedMessage: $failure->message,
            sanitizedTrace: (string) $failure->trace,
            willRetry: false,
            nextAvailableAt: null,
            terminalReason: 'driver_fail',
            failedAt: $this->clock->now(),
        );
        $this->settleOutcome($reservation, $record, JobState::Failed, null);
    }

    public function settleOutcome(
        Reservation $reservation,
        AttemptRecord $record,
        JobState $nextState,
        ?\DateTimeImmutable $availableAt,
    ): void {
        if ($nextState !== JobState::Pending && $nextState !== JobState::Dead && $nextState !== JobState::Failed) {
            throw new DriverException('settleOutcome only supports pending retry or dead/failed.');
        }
        $now = $this->clock->now();
        $when = $availableAt ?? $now;
        $dest = $nextState === JobState::Pending
            ? $this->keys->ready($reservation->envelope->queue)
            : $this->keys->dead();
        $delayed = $this->keys->delayed($reservation->envelope->queue);
        $encoded = json_encode($record->toArray(), JSON_THROW_ON_ERROR);
        $ok = $this->scripts->run(
            'settle',
            RedisScripts::SETTLE,
            [
                $this->keys->job($reservation->envelope->jobId),
                $this->keys->reserved(),
                $this->keys->concurrency($reservation->envelope->queue),
                $this->keys->attempts($reservation->envelope->jobId),
                $dest,
                $delayed,
            ],
            [
                $reservation->token->value,
                $reservation->envelope->jobId,
                $nextState->value,
                (string) $when->getTimestamp(),
                $this->readyScore($reservation->envelope->priority, $when->getTimestamp()),
                (string) $now->getTimestamp(),
                $encoded,
                (string) $now->getTimestamp(),
                Dates::toAtom($when),
            ]
        );
        if ((int) $ok !== 1) {
            throw new DriverException(
                'Reservation token is not the active owner of job ' . $reservation->envelope->jobId . '.'
            );
        }
    }

    public function attemptsFor(string $jobId): array
    {
        $rows = $this->redis->command('LRANGE', [$this->keys->attempts($jobId), 0, -1]);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_string($row)) {
                continue;
            }
            try {
                $decoded = json_decode($row, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (is_array($decoded)) {
                $out[] = $this->attemptFromArray($decoded);
            }
        }

        return $out;
    }

    public function listStopped(int $limit = 50, int $offset = 0): array
    {
        $ids = $this->redis->command('ZREVRANGE', [$this->keys->dead(), $offset, $offset + $limit - 1]);
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            if (is_string($id)) {
                try {
                    $out[] = $this->job($id);
                } catch (DriverException) {
                }
            }
        }

        return $out;
    }

    public function revive(string $jobId): Envelope
    {
        $now = $this->clock->now();
        $current = $this->job($jobId);
        $identity = UniqueIdentity::forJob($current);
        $uniqueKey = $identity !== null ? $this->keys->unique($identity->hash) : '';
        $result = $this->scripts->run(
            'revive',
            RedisScripts::REVIVE,
            [$this->keys->job($jobId), $this->keys->dead(), $this->keys->ready($current->queue)],
            [
                $jobId,
                (string) $now->getTimestamp(),
                $this->readyScore($current->priority, $now->getTimestamp()),
                Dates::toAtom($now),
                $uniqueKey,
            ]
        );
        if ((int) $result === 0) {
            throw new DriverException('Unknown job ' . $jobId . '.');
        }
        if ((int) $result === -1) {
            throw new DriverException('Job ' . $jobId . ' is not dead or failed.');
        }
        if ((int) $result === -2) {
            $held = $this->redis->command('GET', [$uniqueKey]);
            throw new \Fuzeo\Queue\Exceptions\UniqueConflictException(
                'Cannot retry job ' . $jobId . '; unique key is held by ' . (string) $held . '.',
                is_string($held) ? $held : '',
                $identity?->uniqueKey ?? '',
            );
        }

        return $this->job($jobId);
    }

    public function job(string $jobId): Envelope
    {
        $raw = $this->redis->command('HGET', [$this->keys->job($jobId), 'envelope']);
        if (!is_string($raw) || $raw === '') {
            throw new DriverException('Unknown job ' . $jobId . '.');
        }

        return $this->decode($raw);
    }

    public function prune(RetentionPolicy $policy, int $batchSize = 500): PruneResult
    {
        $batchSize = max(1, min(5000, $batchSize));
        $now = $this->clock->now();
        $completed = $this->pruneIndex($this->keys->completed(), $policy->completedBefore($now)->getTimestamp(), $batchSize);
        $dead = $this->pruneIndex($this->keys->dead(), $policy->deadBefore($now)->getTimestamp(), $batchSize);

        return new PruneResult($completed['jobs'], $dead['jobs'], $completed['attempts'] + $dead['attempts']);
    }

    public function extendLease(Reservation $reservation, \DateInterval $extension): Reservation
    {
        $expires = $this->clock->now()->add($extension);
        $ok = $this->scripts->run(
            'extend',
            RedisScripts::EXTEND,
            [
                $this->keys->job($reservation->envelope->jobId),
                $this->keys->reserved(),
                $this->keys->concurrency($reservation->envelope->queue),
            ],
            [$reservation->token->value, $reservation->envelope->jobId, (string) $expires->getTimestamp()]
        );
        if ((int) $ok !== 1) {
            throw new DriverException(
                'Cannot extend lease; reservation token is not the active owner of job '
                . $reservation->envelope->jobId . '.'
            );
        }

        return $reservation->withLease($expires);
    }

    public function size(string $queue): int
    {
        QueueName::assertValid($queue);
        $now = (string) $this->clock->now()->getTimestamp();
        $ready = (int) $this->redis->command('ZCARD', [$this->keys->ready($queue)]);
        $due = (int) $this->redis->command('ZCOUNT', [$this->keys->delayed($queue), '-inf', $now]);

        return $ready + $due;
    }

    public function countsByState(): array
    {
        $queues = $this->redis->command('SMEMBERS', [$this->keys->queues()]);
        $pending = 0;
        if (is_array($queues)) {
            foreach ($queues as $queue) {
                if (is_string($queue)) {
                    $pending += (int) $this->redis->command('ZCARD', [$this->keys->ready($queue)]);
                    $pending += (int) $this->redis->command('ZCARD', [$this->keys->delayed($queue)]);
                }
            }
        }

        return [
            'pending' => $pending,
            'reserved' => (int) $this->redis->command('ZCARD', [$this->keys->reserved()]),
            'completed' => (int) $this->redis->command('ZCARD', [$this->keys->completed()]),
            'dead' => (int) $this->redis->command('ZCARD', [$this->keys->dead()]),
        ];
    }

    public function retryingCount(): int
    {
        $now = (string) $this->clock->now()->getTimestamp();
        $queues = $this->redis->command('SMEMBERS', [$this->keys->queues()]);
        $count = 0;
        if (is_array($queues)) {
            foreach ($queues as $queue) {
                if (is_string($queue)) {
                    $count += (int) $this->redis->command('ZCOUNT', [$this->keys->delayed($queue), '(' . $now, '+inf']);
                }
            }
        }

        return $count;
    }

    public function health(): DriverHealth
    {
        $details = [
            'endpoint' => $this->settings->redactedEndpoint(),
            'namespace' => $this->settings->namespace,
            'script_version' => RedisScripts::VERSION,
        ];
        try {
            if (!$this->ping()) {
                return new DriverHealth(false, 'redis', $details, 'Redis ping failed.');
            }
            $info = $this->redis->serverInfo();
            $details['redis_version'] = $info['redis_version'] ?? '';
            $policy = $this->redis->configGet('maxmemory-policy');
            $details['maxmemory_policy'] = $policy;
            $details['states'] = $this->countsByState();
            $message = null;
            if ($policy !== '' && $policy !== 'noeviction' && $policy !== 'volatile-lru' && $policy !== 'volatile-ttl' && $policy !== 'volatile-lfu' && $policy !== 'volatile-random') {
                $message = 'Redis maxmemory-policy is "' . $policy . '". Durable queues need noeviction or a volatile-* policy so job keys are not evicted.';
            }

            return new DriverHealth(true, 'redis', $details, $message);
        } catch (\Throwable $exception) {
            return new DriverHealth(false, 'redis', $details, $exception->getMessage());
        }
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            priorities: true,
            blockingReserve: true,
            atomicUniqueness: true,
            distributedLocks: true,
            delayedJobs: true,
            advancedMetrics: false,
            durable: true,
            atomicRateLimits: true,
            queueConcurrency: true,
            highConcurrency: true,
            scheduling: true,
            idempotency: true,
        );
    }

    public function ping(): bool
    {
        return $this->redis->ping();
    }

    public function reconnect(): void
    {
        $this->redis->reconnect();
    }

    public function get(string $jobId): Envelope
    {
        return $this->job($jobId);
    }

    private function blockOrNull(ReserveRequest $request): ?Reservation
    {
        if ($request->blockSeconds < 1) {
            return null;
        }
        $this->redis->command('BLPOP', [$this->keys->wakeup($request->queue), $request->blockSeconds]);

        return $this->reserve(new ReserveRequest($request->queue, $request->workerId, $request->leaseSeconds, 0));
    }

    private function encode(Envelope $envelope): string
    {
        try {
            return json_encode($envelope->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $exception) {
            throw new DriverException('Unable to encode envelope JSON.', 0, $exception);
        }
    }

    private function decode(string $json): Envelope
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DriverException('Persisted job envelope JSON is corrupt.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new DriverException('Persisted job envelope JSON must be an object.');
        }
        foreach (['envelope_version', 'schema_version', 'priority', 'attempt', 'max_attempts', 'timeout_seconds', 'network_id', 'site_id'] as $intKey) {
            if (isset($decoded[$intKey]) && is_numeric($decoded[$intKey])) {
                $decoded[$intKey] = (int) $decoded[$intKey];
            }
        }

        return Envelope::fromArray($decoded);
    }

    private function readyScore(int $priority, int $availableAt): string
    {
        $p = max(-5000, min(4999, $priority));

        return sprintf('%.0f', (5000 - $p) * 10000000000 + $availableAt);
    }

    /**
     * @return array{jobs: int, attempts: int}
     */
    private function pruneIndex(string $index, int $before, int $limit): array
    {
        $ids = $this->redis->command('ZRANGEBYSCORE', [$index, '-inf', (string) $before, 'LIMIT', 0, $limit]);
        if (!is_array($ids) || $ids === []) {
            return ['jobs' => 0, 'attempts' => 0];
        }
        $jobs = 0;
        $attempts = 0;
        foreach ($ids as $id) {
            if (!is_string($id)) {
                continue;
            }
            $attempts += (int) $this->redis->command('LLEN', [$this->keys->attempts($id)]);
            $this->redis->command('DEL', [$this->keys->job($id), $this->keys->attempts($id)]);
            $this->redis->command('ZREM', [$index, $id]);
            $jobs++;
        }

        return ['jobs' => $jobs, 'attempts' => $attempts];
    }

    private function assertVersion(): void
    {
        $info = $this->redis->serverInfo();
        $version = $info['redis_version'] ?? '0.0.0';
        if (version_compare($version, self::MIN_REDIS_VERSION, '<')) {
            throw new DriverException(
                'Redis ' . self::MIN_REDIS_VERSION . '+ is required; server reports ' . $version . '.'
            );
        }
    }

    private function isConnectivity(DriverException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'connection')
            || str_contains($message, 'went away')
            || str_contains($message, 'read error')
            || str_contains($message, 'socket');
    }

    /**
     * @return list<mixed>
     */
    private function luaList(mixed $result): array
    {
        if (!is_array($result) || $result === []) {
            return [];
        }
        ksort($result);

        return array_values($result);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function attemptFromArray(array $data): AttemptRecord
    {
        $next = $data['next_available_at'] ?? null;
        $failed = $data['failed_at'] ?? '';

        return new AttemptRecord(
            attemptId: (string) ($data['attempt_id'] ?? ''),
            jobId: (string) ($data['job_id'] ?? ''),
            attempt: (int) ($data['attempt'] ?? 0),
            outcome: (string) ($data['outcome'] ?? ''),
            jobType: (string) ($data['job_type'] ?? ''),
            queue: (string) ($data['queue'] ?? ''),
            workerId: isset($data['worker_id']) && is_string($data['worker_id']) ? $data['worker_id'] : null,
            reservationToken: isset($data['reservation_token']) && is_string($data['reservation_token']) ? $data['reservation_token'] : null,
            originPackage: (string) ($data['origin_package'] ?? ''),
            networkId: (int) ($data['network_id'] ?? 0),
            siteId: (int) ($data['site_id'] ?? 0),
            scope: (string) ($data['scope'] ?? ''),
            failureClass: isset($data['failure_class']) && is_string($data['failure_class']) ? $data['failure_class'] : null,
            sanitizedMessage: (string) ($data['sanitized_message'] ?? ''),
            sanitizedTrace: (string) ($data['sanitized_trace'] ?? ''),
            willRetry: (bool) ($data['will_retry'] ?? false),
            nextAvailableAt: is_string($next) && $next !== '' ? Dates::fromAtom($next) : null,
            terminalReason: isset($data['terminal_reason']) && is_string($data['terminal_reason']) ? $data['terminal_reason'] : null,
            failedAt: is_string($failed) && $failed !== '' ? Dates::fromAtom($failed) : $this->clock->now(),
        );
    }
}
