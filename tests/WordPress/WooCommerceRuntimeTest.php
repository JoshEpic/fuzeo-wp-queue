<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\WordPress;

use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\Handler;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;
use PHPUnit\Framework\TestCase;

/**
 * WooCommerce is not a package dependency. These tests run only when WooCommerce is installed.
 */
final class WooCommerceRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists('WooCommerce') || !function_exists('wc_create_order')) {
            self::markTestSkipped('WooCommerce is not installed in this WordPress test suite.');
        }
        Coordinator::bootForTesting(['driver' => 'memory']);
    }

    protected function tearDown(): void
    {
        Coordinator::reset();
    }

    public function testOrdersAreReloadedByIdAcrossJobs(): void
    {
        $product = new \WC_Product_Simple();
        $product->set_name('Fuzeo Queue Product');
        $product->set_regular_price('10');
        $product->save();
        $order = wc_create_order();
        self::assertNotFalse($order);
        $order->add_product($product, 1);
        $order->save();
        $orderId = $order->get_id();
        WooOrderHandler::$seenStatus = [];
        Queue::register(WooOrderJob::class, new Origin('acme/shop', '1.0.0'), WooOrderHandler::class);
        Queue::dispatch(new WooOrderJob($orderId, 'processing'));
        Queue::dispatch(new WooOrderJob($orderId, 'completed'));
        WorkerLoop::fromManager(Coordinator::get(), new WorkerOptions(sleepSeconds: 0, maxJobs: 2))->run();
        self::assertSame(['processing', 'completed'], WooOrderHandler::$seenStatus);
        $fresh = wc_get_order($orderId);
        self::assertNotFalse($fresh);
        self::assertSame('completed', $fresh->get_status());
    }
}

final class WooOrderJob implements Job
{
    public function __construct(
        private readonly int $orderId,
        private readonly string $status,
    ) {
    }

    public static function type(): string
    {
        return 'woo.update_order';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return ['order_id' => $this->orderId, 'status' => $this->status];
    }
}

final class WooOrderHandler implements Handler
{
    /** @var list<string> */
    public static array $seenStatus = [];

    public function handle(Envelope $envelope): void
    {
        $orderId = (int) $envelope->payload['order_id'];
        $status = (string) $envelope->payload['status'];
        $order = wc_get_order($orderId);
        if ($order === false) {
            throw new \RuntimeException('Order ' . $orderId . ' was not found.');
        }
        $order->set_status($status);
        $order->save();
        $reloaded = wc_get_order($orderId);
        self::$seenStatus[] = $reloaded !== false ? $reloaded->get_status() : '';
    }
}
