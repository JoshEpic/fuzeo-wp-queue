<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

final class Candidate
{
    public function __construct(
        public readonly string $version,
        public readonly int $compatibilitySeries,
        public readonly string $path,
        public readonly string $source,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $version = $data['version'] ?? '';
        $series = $data['compatibility_series'] ?? 0;
        $path = $data['path'] ?? '';
        $source = $data['source'] ?? '';

        if (!is_string($version) || !is_int($series) || !is_string($path) || !is_string($source)) {
            throw new \Fuzeo\Queue\Exceptions\IncompatibleRuntimeException('Invalid Fuzeo Queue candidate record.');
        }

        return new self($version, $series, $path, $source);
    }
}
