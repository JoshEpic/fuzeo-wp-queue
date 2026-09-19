<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Jobs;

use Fuzeo\Queue\Exceptions\QueueException;

/**
 * Identifies the package or plugin that produced a job or registration.
 */
final class Origin
{
    public function __construct(
        public readonly string $package,
        public readonly string $version,
        public readonly ?string $pluginFile = null,
    ) {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*$/', $this->package)) {
            throw new QueueException(
                'Origin package must look like a Composer name, e.g. acme/shop or fuzeowp/bridge.'
            );
        }

        if ($this->version === '' || strlen($this->version) > 64) {
            throw new QueueException('Origin version must be a non-empty string of 64 characters or fewer.');
        }
    }

    /**
     * @return array{package: string, version: string, plugin_file: string|null}
     */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'version' => $this->version,
            'plugin_file' => $this->pluginFile,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $package = $data['package'] ?? null;
        $version = $data['version'] ?? null;
        if (!is_string($package) || !is_string($version)) {
            throw new QueueException('Origin requires package and version strings.');
        }

        $pluginFile = $data['plugin_file'] ?? null;
        if ($pluginFile !== null && !is_string($pluginFile)) {
            throw new QueueException('Origin plugin_file must be a string or null.');
        }

        return new self($package, $version, $pluginFile);
    }
}
