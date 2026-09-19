<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

use Fuzeo\Queue\Config\Config;
use Fuzeo\Queue\Exceptions\QueueException;

/**
 * User-defined tags and metadata are bounded to limit cardinality and storage DoS.
 */
final class MetadataLimits
{
    public function __construct(
        public readonly int $maxTags = 16,
        public readonly int $maxTagLength = 64,
        public readonly int $maxMetadataBytes = 8192,
        public readonly int $maxKeyLength = 64,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            $config->maxTags,
            $config->maxTagLength,
            $config->maxMetadataBytes,
            $config->maxMetadataKeyLength,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     * @param list<string> $tags
     */
    public function assert(array $metadata, array $tags): void
    {
        if (count($tags) > $this->maxTags) {
            throw new QueueException(
                'Jobs may have at most ' . $this->maxTags . ' tags. Got ' . count($tags) . '.'
            );
        }
        foreach ($tags as $tag) {
            if (!is_string($tag) || $tag === '') {
                throw new QueueException('Each tag must be a non-empty string.');
            }
            if (strlen($tag) > $this->maxTagLength) {
                throw new QueueException(
                    'Tag "' . substr($tag, 0, 24) . '…" exceeds ' . $this->maxTagLength . ' bytes.'
                );
            }
        }
        foreach (array_keys($metadata) as $key) {
            if (!is_string($key) || $key === '') {
                throw new QueueException('Metadata keys must be non-empty strings.');
            }
            if (strlen($key) > $this->maxKeyLength) {
                throw new QueueException(
                    'Metadata key "' . substr($key, 0, 24) . '…" exceeds ' . $this->maxKeyLength . ' bytes.'
                );
            }
        }
        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || strlen($json) > $this->maxMetadataBytes) {
            throw new QueueException(
                'User metadata exceeds ' . $this->maxMetadataBytes . ' bytes. Reduce keys or values.'
            );
        }
    }
}
