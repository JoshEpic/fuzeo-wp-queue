<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Security;

use Fuzeo\Queue\Exceptions\DuplicateJobTypeException;
use Fuzeo\Queue\Exceptions\SerializationException;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Serialization\JsonPayloadSerializer;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Tests\Support\UnsafeObjectJob;
use PHPUnit\Framework\TestCase;

final class PayloadSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        Coordinator::bootForTesting();
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Coordinator::reset();
    }

    public function testDispatchRejectsWordPressLikeObjects(): void
    {
        Coordinator::get()->jobs()->registerJob(UnsafeObjectJob::class, new Origin('acme/shop', '1.0.0'));
        $this->expectException(SerializationException::class);
        Queue::dispatch(new UnsafeObjectJob());
    }

    public function testDoesNotUnserializePhpObjects(): void
    {
        $serialized = serialize((object) ['evil' => true]);
        $this->expectException(SerializationException::class);
        (new JsonPayloadSerializer())->decode($serialized);
    }

    public function testSpoofedJobTypeMustBeRegistered(): void
    {
        $this->expectException(\Fuzeo\Queue\Exceptions\UnknownJobException::class);
        Coordinator::get()->jobs()->get('evil.takeover');
    }

    public function testDuplicateTypeIsDeterministic(): void
    {
        $this->expectException(DuplicateJobTypeException::class);
        Coordinator::get()->jobs()->register(
            'acme.process_order',
            1,
            ProcessOrderJob::class,
            new Origin('evil/plugin', '9.9.9'),
        );
    }
}
