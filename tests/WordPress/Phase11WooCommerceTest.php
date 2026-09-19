<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\WordPress;

use Fuzeo\Queue\Interop\NativeActionSchedulerGateway;
use Fuzeo\Queue\Runtime\Coordinator;
use PHPUnit\Framework\TestCase;

final class Phase11WooCommerceTest extends TestCase
{
    public function testWooCommerceBundledActionSchedulerIsDetectedWithoutOwningIt(): void
    {
        if (!class_exists('WooCommerce') && !defined('WC_PLUGIN_FILE')) {
            self::markTestSkipped('WooCommerce is not installed.');
        }
        Coordinator::bootForTesting(['driver' => 'memory']);
        $gateway = new NativeActionSchedulerGateway();
        self::assertTrue($gateway->detected());
        self::assertTrue($gateway->datastoreAvailable() || function_exists('as_get_scheduled_actions'));
        Coordinator::reset();
    }
}
