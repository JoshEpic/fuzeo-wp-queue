<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Jobs\QueueName;
use Fuzeo\Queue\Worker\WorkerOptions;
use PHPUnit\Framework\TestCase;

final class WorkerOptionsTest extends TestCase
{
    public function testParsesCliQueueListAndMemory(): void
    {
        $options = WorkerOptions::fromCli([
            'queue' => 'high, default',
            'sleep' => '0',
            'memory' => '64M',
            'max-jobs' => '10',
        ]);
        self::assertSame(['high', 'default'], $options->queues);
        self::assertSame(0, $options->sleepSeconds);
        self::assertSame(64 * 1024 * 1024, $options->memoryBytes);
        self::assertSame(10, $options->maxJobs);
    }

    public function testDefaultQueue(): void
    {
        $options = WorkerOptions::fromCli([], QueueName::DEFAULT);
        self::assertSame(['default'], $options->queues);
    }
}
