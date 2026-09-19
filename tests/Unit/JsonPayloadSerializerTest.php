<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Exceptions\SerializationException;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Serialization\JsonPayloadSerializer;
use Fuzeo\Queue\Serialization\PayloadLimits;
use PHPUnit\Framework\TestCase;

final class JsonPayloadSerializerTest extends TestCase
{
    public function testEncodesJsonCompatibleValues(): void
    {
        $serializer = new JsonPayloadSerializer();
        $payload = [
            'id' => 1,
            'ok' => true,
            'name' => 'Ada',
            'nested' => ['a' => 1.5],
            'tags' => ['a', 'b'],
            'empty' => null,
        ];
        $normalized = $serializer->normalize($payload);
        $json = $serializer->encode($normalized);
        self::assertSame($normalized, $serializer->decode($json));
    }

    public function testRejectsObjects(): void
    {
        $this->expectException(SerializationException::class);
        (new JsonPayloadSerializer())->normalize(['post' => new \stdClass()]);
    }

    public function testRejectsClosures(): void
    {
        $this->expectException(SerializationException::class);
        (new JsonPayloadSerializer())->normalize(['fn' => static fn (): int => 1]);
    }

    public function testRejectsMalformedJson(): void
    {
        $this->expectException(SerializationException::class);
        (new JsonPayloadSerializer())->decode('{not json');
    }

    public function testRejectsDeepNesting(): void
    {
        $nested = ['v' => 1];
        for ($i = 0; $i < 40; $i++) {
            $nested = ['child' => $nested];
        }
        $this->expectException(SerializationException::class);
        (new JsonPayloadSerializer(new PayloadLimits(262144, 8)))->normalize($nested);
    }

    public function testRejectsEnormousPayloads(): void
    {
        $this->expectException(SerializationException::class);
        (new JsonPayloadSerializer(new PayloadLimits(64, 8)))->normalize(['blob' => str_repeat('a', 200)]);
    }

    public function testRejectsRootLists(): void
    {
        $this->expectException(SerializationException::class);
        (new JsonPayloadSerializer())->normalize([1, 2, 3]);
    }

    public function testRejectsNonUtf8(): void
    {
        $this->expectException(SerializationException::class);
        (new JsonPayloadSerializer())->normalize(['bad' => "\xB1\x31"]);
    }

    public function testExecutionContextRejectsInvalidSite(): void
    {
        $this->expectException(\Fuzeo\Queue\Exceptions\QueueException::class);
        ExecutionContext::site(1, 0);
    }
}
