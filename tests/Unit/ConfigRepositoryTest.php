<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Config\Config;
use Fuzeo\Queue\Config\ConfigRepository;
use PHPUnit\Framework\TestCase;

final class ConfigRepositoryTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('FUZEO_QUEUE_DRIVER');
        putenv('FUZEO_QUEUE_DEFAULT_QUEUE');
        parent::tearDown();
    }

    public function testDefaults(): void
    {
        $config = (new ConfigRepository())->resolve();
        self::assertSame(Config::DRIVER_UNAVAILABLE, $config->driver);
        self::assertSame('default', $config->defaultQueue);
        self::assertTrue($config->redactPayloads);
    }

    public function testExplicitOverridesEnvironment(): void
    {
        putenv('FUZEO_QUEUE_DRIVER=memory');
        putenv('FUZEO_QUEUE_DEFAULT_QUEUE=envq');
        $config = (new ConfigRepository())->resolve([
            'driver' => 'unavailable',
            'default_queue' => 'explicit',
        ]);
        self::assertSame('unavailable', $config->driver);
        self::assertSame('explicit', $config->defaultQueue);
    }

    public function testEnvironmentOverridesDefaults(): void
    {
        putenv('FUZEO_QUEUE_DRIVER=memory');
        putenv('FUZEO_QUEUE_DEFAULT_QUEUE=imports');
        $config = (new ConfigRepository())->resolve();
        self::assertSame('memory', $config->driver);
        self::assertSame('imports', $config->defaultQueue);
    }
}
