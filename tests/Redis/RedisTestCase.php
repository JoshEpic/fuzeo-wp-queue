<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Redis;

use Fuzeo\Queue\Config\Config;
use Fuzeo\Queue\Drivers\Redis\RedisDriver;
use Fuzeo\Queue\Redis\PhpRedisConnection;
use Fuzeo\Queue\Redis\RedisSettings;
use Fuzeo\Queue\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

abstract class RedisTestCase extends TestCase
{
    protected RedisDriver $redisDriver;

    protected FrozenClock $clock;

    protected RedisSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is required for Redis tests.');
        }
        $host = getenv('FUZEO_QUEUE_TEST_REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('FUZEO_QUEUE_TEST_REDIS_PORT') ?: '6379');
        $database = (int) (getenv('FUZEO_QUEUE_TEST_REDIS_DB') ?: '15');
        $namespace = 't' . substr(bin2hex(random_bytes(6)), 0, 12);
        $this->settings = new RedisSettings(
            host: $host,
            port: $port,
            database: $database,
            namespace: $namespace,
        );
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-06-01T00:00:00Z'));
        try {
            $client = new PhpRedisConnection($this->settings);
        } catch (\Throwable $exception) {
            self::markTestSkipped('Redis is not reachable: ' . $exception->getMessage());
        }
        $this->redisDriver = new RedisDriver(
            $client,
            $this->settings,
            $this->clock,
            new Config(driver: Config::DRIVER_REDIS, redisNamespace: $namespace)
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->redisDriver)) {
            $this->deletePrefixedKeys();
        }
        parent::tearDown();
    }

    private function deletePrefixedKeys(): void
    {
        $prefix = $this->settings->prefix() . ':*';
        $cursor = '0';
        $client = $this->redisDriver->client();
        do {
            $scan = $client->command('SCAN', [$cursor, 'MATCH', $prefix, 'COUNT', 100]);
            if (!is_array($scan) || count($scan) < 2) {
                break;
            }
            $cursor = (string) $scan[0];
            $keys = $scan[1] ?? [];
            if (is_array($keys)) {
                foreach ($keys as $key) {
                    if (is_string($key)) {
                        $client->command('DEL', [$key]);
                    }
                }
            }
        } while ($cursor !== '0');
    }
}
