<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Worker;

use Fuzeo\Queue\Runtime\PackageInfo;
use Fuzeo\Queue\Support\Ulid;

final class WorkerIdentity
{
    public function __construct(
        public readonly string $workerId,
        public readonly string $hostname,
        public readonly int $pid,
        public readonly \DateTimeImmutable $startedAt,
        public readonly string $runtimeVersion,
        public readonly string $runtimeGeneration = '',
    ) {
    }

    public static function generate(?\DateTimeImmutable $startedAt = null, string $runtimeGeneration = ''): self
    {
        $host = gethostname();
        $pid = getmypid();

        return new self(
            Ulid::generate(),
            is_string($host) && $host !== '' ? $host : 'unknown',
            is_int($pid) ? $pid : 0,
            $startedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            PackageInfo::VERSION,
            $runtimeGeneration,
        );
    }
}
