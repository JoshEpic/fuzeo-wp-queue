<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Conformance;

use Fuzeo\Queue\Config\Config;
use Fuzeo\Queue\Drivers\QueueDriver;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Redis\RedisTestCase;

final class RedisDriverConformanceTest extends RedisTestCase
{
    use DriverConformanceCases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initConformance();
    }

    protected function tearDown(): void
    {
        $this->tearDownConformance();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function boot(array $config = []): QueueDriver
    {
        $config['driver'] = Config::DRIVER_REDIS;
        $config['redis_namespace'] = $this->settings->namespace;
        $dsn = 'redis://' . $this->settings->host . ':' . $this->settings->port . '/' . $this->settings->database;
        $config['redis_dsn'] = $dsn;

        return Coordinator::bootForTesting(
            $config,
            $this->redisDriver,
            clock: $this->clock,
        )->driver();
    }
}
