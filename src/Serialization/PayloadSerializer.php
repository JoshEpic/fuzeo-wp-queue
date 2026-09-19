<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Serialization;

/**
 * @phpstan-type JsonMap array<string, mixed>
 */
interface PayloadSerializer
{
    /**
     * @param array<mixed> $payload
     * @return JsonMap
     */
    public function normalize(array $payload): array;

    /**
     * @param array<mixed> $payload
     */
    public function encode(array $payload): string;

    /**
     * @return JsonMap
     */
    public function decode(string $json): array;
}
