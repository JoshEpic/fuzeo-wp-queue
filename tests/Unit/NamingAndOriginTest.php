<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Exceptions\InvalidQueueNameException;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\ExecutionScope;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Jobs\QueueName;
use PHPUnit\Framework\TestCase;

final class NamingAndOriginTest extends TestCase
{
    public function testQueueNames(): void
    {
        self::assertSame('fulfillment', QueueName::normalize('Fulfillment'));
        $this->expectException(InvalidQueueNameException::class);
        QueueName::normalize('Bad Queue');
    }

    public function testOriginRequiresComposerStyleName(): void
    {
        $origin = new Origin('fuzeowp/bridge', '0.8.0', 'bridge.php');
        self::assertSame('fuzeowp/bridge', $origin->toArray()['package']);
        $this->expectException(\Fuzeo\Queue\Exceptions\QueueException::class);
        new Origin('not-a-package', '1.0.0');
    }

    public function testNetworkScopedContext(): void
    {
        $context = ExecutionContext::network(4);
        self::assertSame(0, $context->siteId);
        self::assertSame(ExecutionScope::Network, $context->scope);
    }
}
