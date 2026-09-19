<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

use Fuzeo\Queue\Persistence\SchemaOwner;

/**
 * Lightweight fingerprint of loaded WordPress + Queue code. Recalculate periodically, not every tight loop tick.
 */
final class RuntimeGeneration
{
    public function __construct(
        private readonly WordPressRuntime $wp,
        private readonly string $packageVersion = PackageInfo::VERSION,
        private readonly int $schemaVersion = SchemaOwner::CURRENT_VERSION,
        private readonly string $loadedClassVersion = PackageInfo::VERSION,
    ) {
    }

    public function current(): string
    {
        $payload = [
            'package' => $this->packageVersion,
            'loaded' => $this->loadedClassVersion,
            'schema' => $this->schemaVersion,
            'plugins' => $this->wp->activePlugins(),
            'network_plugins' => $this->wp->networkActivePlugins(),
            'theme' => $this->wp->activeTheme(),
        ];
        $json = json_encode($payload);
        if (!is_string($json)) {
            return hash('sha256', $this->packageVersion);
        }

        return hash('sha256', $json);
    }

    /**
     * @return array{package: string, loaded: string, schema: int, plugins: list<string>, network_plugins: list<string>, theme: string}
     */
    public function describe(): array
    {
        return [
            'package' => $this->packageVersion,
            'loaded' => $this->loadedClassVersion,
            'schema' => $this->schemaVersion,
            'plugins' => $this->wp->activePlugins(),
            'network_plugins' => $this->wp->networkActivePlugins(),
            'theme' => $this->wp->activeTheme(),
        ];
    }
}
