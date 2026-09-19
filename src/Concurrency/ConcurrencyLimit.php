<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Concurrency;

/**
 * Queue-level cap on simultaneously executing jobs across the whole fleet.
 *
 * @phpstan-type Limits array<string, int>
 */
final class ConcurrencyLimit
{
    /**
     * @param array<string, int> $byQueue
     */
    public function __construct(public readonly array $byQueue = [])
    {
        foreach ($this->byQueue as $queue => $max) {
            \Fuzeo\Queue\Jobs\QueueName::assertValid($queue);
            if ($max < 1) {
                throw new \Fuzeo\Queue\Exceptions\ConfigurationException(
                    'Concurrency for queue ' . $queue . ' must be at least 1.'
                );
            }
        }
    }

    public function forQueue(string $queue): ?int
    {
        return $this->byQueue[$queue] ?? null;
    }

    public static function max(int $max): QueueConcurrency
    {
        return new QueueConcurrency($max);
    }
}
