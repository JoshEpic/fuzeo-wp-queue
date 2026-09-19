<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

final class RegisteredJob
{
    /**
     * @param class-string $handler
     * @param class-string<Job>|null $jobClass
     */
    public function __construct(
        public readonly string $type,
        public readonly int $schemaVersion,
        public readonly string $handler,
        public readonly Origin $origin,
        public readonly ?string $jobClass = null,
    ) {
    }
}
