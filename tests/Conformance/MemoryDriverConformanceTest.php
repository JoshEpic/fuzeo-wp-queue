<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Conformance;

use Fuzeo\Queue\Drivers\QueueDriver;
use Fuzeo\Queue\Runtime\Coordinator;
use PHPUnit\Framework\TestCase;

final class MemoryDriverConformanceTest extends TestCase
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
        $config['driver'] = 'memory';

        return Coordinator::bootForTesting($config, clock: $this->clock)->driver();
    }
}
