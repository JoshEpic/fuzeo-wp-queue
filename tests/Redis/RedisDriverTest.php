<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Redis;

use Fuzeo\Queue\Config\Config;
use Fuzeo\Queue\Drivers\Redis\RedisDriver;
use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Redis\PhpRedisConnection;
use Fuzeo\Queue\Redis\RedisSettings;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Tests\Support\RateLimitedOrderJob;

final class RedisDriverTest extends RedisTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testNamespaceIsolationAndObjectCachePrefix(): void
    {
        Coordinator::bootForTesting(
            ['driver' => 'redis'],
            $this->redisDriver,
            clock: $this->clock,
        );
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(1));
        self::assertStringStartsWith('fuzeo_queue:', $this->settings->prefix());
        $other = new RedisSettings(
            host: $this->settings->host,
            port: $this->settings->port,
            database: $this->settings->database,
            namespace: 'otherns',
        );
        $second = new RedisDriver(
            new PhpRedisConnection($other),
            $other,
            $this->clock,
            new Config(driver: Config::DRIVER_REDIS, redisNamespace: 'otherns')
        );
        self::assertSame(0, $second->size('default'));
        self::assertTrue($this->redisDriver->capabilities()->supports('blocking_reserve'));
        self::assertTrue($this->redisDriver->capabilities()->supports('high_concurrency'));
        $health = $this->redisDriver->health();
        self::assertArrayHasKey('endpoint', $health->details);
        self::assertIsString($health->details['endpoint']);
        self::assertStringNotContainsString('password', (string) $health->details['endpoint']);
    }

    public function testLockOwnership(): void
    {
        $lock = $this->redisDriver->lock();
        self::assertTrue($lock->acquire('promo', 'owner-a', 10));
        self::assertFalse($lock->acquire('promo', 'owner-b', 10));
        self::assertFalse($lock->release('promo', 'owner-b'));
        self::assertTrue($lock->extend('promo', 'owner-a', 10));
        self::assertTrue($lock->release('promo', 'owner-a'));
        self::assertTrue($lock->acquire('promo', 'owner-b', 10));
    }

    public function testScriptFlushReloads(): void
    {
        Coordinator::bootForTesting(['driver' => 'redis'], $this->redisDriver, clock: $this->clock);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(2));
        $this->redisDriver->client()->command('SCRIPT', ['FLUSH']);
        $reserved = $this->redisDriver->reserve(new ReserveRequest('default', 'w', 30));
        self::assertNotNull($reserved);
        $this->redisDriver->acknowledge($reserved);
    }

    public function testQueueConcurrencyLimitDoesNotIncrementAttempt(): void
    {
        $limited = new RedisDriver(
            $this->redisDriver->client(),
            $this->settings,
            $this->clock,
            new Config(
                driver: Config::DRIVER_REDIS,
                redisNamespace: $this->settings->namespace,
                concurrency: ['imports' => 1]
            )
        );
        Coordinator::bootForTesting(
            ['driver' => 'redis', 'concurrency' => ['imports' => 1]],
            $limited,
            clock: $this->clock,
        );
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $one = Queue::on('imports')->dispatch(new ProcessOrderJob(1));
        $two = Queue::on('imports')->dispatch(new ProcessOrderJob(2));
        $first = $limited->reserve(new ReserveRequest('imports', 'w1', 30));
        self::assertNotNull($first);
        self::assertSame(1, $first->envelope->attempt);
        self::assertNull($limited->reserve(new ReserveRequest('imports', 'w2', 30)));
        $otherId = $first->envelope->jobId === $one->jobId ? $two->jobId : $one->jobId;
        self::assertSame(0, $limited->job($otherId)->attempt);
        self::assertSame(JobState::Pending, $limited->job($otherId)->state);
        $limited->acknowledge($first);
        $next = $limited->reserve(new ReserveRequest('imports', 'w2', 30));
        self::assertNotNull($next);
        self::assertSame($otherId, $next->envelope->jobId);
        self::assertSame(1, $next->envelope->attempt);
    }

    public function testRateLimitDoesNotBurnAttempts(): void
    {
        Coordinator::bootForTesting(['driver' => 'redis'], $this->redisDriver, clock: $this->clock);
        Queue::register(RateLimitedOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $firstEnv = Queue::dispatch(new RateLimitedOrderJob(1));
        $secondEnv = Queue::dispatch(new RateLimitedOrderJob(2));
        $first = $this->redisDriver->reserve(new ReserveRequest('default', 'w', 30));
        self::assertNotNull($first);
        self::assertSame(1, $first->envelope->attempt);
        $this->redisDriver->acknowledge($first);
        self::assertNull($this->redisDriver->reserve(new ReserveRequest('default', 'w', 30)));
        $otherId = $first->envelope->jobId === $firstEnv->jobId ? $secondEnv->jobId : $firstEnv->jobId;
        self::assertSame(0, $this->redisDriver->job($otherId)->attempt);
        $this->clock->set($this->clock->now()->add(new \DateInterval('PT2S')));
        $second = $this->redisDriver->reserve(new ReserveRequest('default', 'w', 30));
        self::assertNotNull($second);
        self::assertSame($otherId, $second->envelope->jobId);
        self::assertSame(1, $second->envelope->attempt);
    }

    public function testDsnRedactsSecrets(): void
    {
        $settings = RedisSettings::fromDsn('redis://user:s3cret@127.0.0.1:6379/15', 'nspace');
        self::assertSame('s3cret', $settings->password);
        self::assertStringNotContainsString('s3cret', $settings->redactedEndpoint());
        self::assertStringNotContainsString('user', $settings->redactedEndpoint());
    }

    public function testWorkerRegistry(): void
    {
        $store = $this->redisDriver->workerStore();
        $identity = \Fuzeo\Queue\Worker\WorkerIdentity::generate($this->clock->now());
        $store->register($identity, ['default']);
        $rows = $store->all();
        self::assertNotSame([], $rows);
        self::assertFalse($store->isStale($rows[0], 30));
    }
}
