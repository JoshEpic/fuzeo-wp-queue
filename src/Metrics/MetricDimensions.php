<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Metrics;

use Fuzeo\Queue\Jobs\Envelope;

/**
 * Low-cardinality labels. Job IDs, users, and tags are not dimensions.
 */
final class MetricDimensions
{
    public const NONE = 'none';
    public const QUEUE = 'queue';
    public const JOB_TYPE = 'job_type';
    public const ORIGIN = 'origin';
    public const SITE = 'site';
    public const OUTCOME = 'outcome';
    public const DRIVER = 'driver';

    public function __construct(
        public readonly string $queue = '',
        public readonly string $jobType = '',
        public readonly string $origin = '',
        public readonly string $site = '',
        public readonly string $outcome = '',
        public readonly string $driver = '',
    ) {
    }

    public static function fromEnvelope(Envelope $envelope, string $driver = '', string $outcome = ''): self
    {
        $site = $envelope->context->scope->value === 'network'
            ? 'network'
            : (string) $envelope->context->siteId;

        return new self(
            queue: $envelope->queue,
            jobType: $envelope->jobType,
            origin: $envelope->origin->package,
            site: $site,
            outcome: $outcome,
            driver: $driver,
        );
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function series(bool $includeSite): array
    {
        $out = [[self::NONE, '']];
        if ($this->queue !== '') {
            $out[] = [self::QUEUE, $this->truncate($this->queue)];
        }
        if ($this->jobType !== '') {
            $out[] = [self::JOB_TYPE, $this->truncate($this->jobType)];
        }
        if ($this->origin !== '') {
            $out[] = [self::ORIGIN, $this->truncate($this->origin)];
        }
        if ($includeSite && $this->site !== '') {
            $out[] = [self::SITE, $this->truncate($this->site)];
        }
        if ($this->outcome !== '') {
            $out[] = [self::OUTCOME, $this->truncate($this->outcome)];
        }
        if ($this->driver !== '') {
            $out[] = [self::DRIVER, $this->truncate($this->driver)];
        }

        return $out;
    }

    private function truncate(string $value): string
    {
        return strlen($value) > 191 ? substr($value, 0, 191) : $value;
    }
}
