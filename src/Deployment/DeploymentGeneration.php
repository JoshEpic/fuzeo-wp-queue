<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Deployment;

use Fuzeo\Queue\Persistence\SchemaOwner;
use Fuzeo\Queue\Runtime\GenerationSource;
use Fuzeo\Queue\Runtime\PackageInfo;
use Fuzeo\Queue\Runtime\RuntimeGeneration;

/**
 * Code/runtime set workers are expected to run. Combines runtime generation with
 * an optional operator-controlled deployment token. Does not hash files on disk.
 */
final class DeploymentGeneration implements GenerationSource
{
    public function __construct(
        private readonly RuntimeGeneration $runtime,
        private readonly string $deploymentId = '',
        private readonly string $loadedClassVersion = PackageInfo::VERSION,
        private readonly int $compatibilitySeries = PackageInfo::COMPATIBILITY_SERIES,
        private readonly int $schemaVersion = SchemaOwner::CURRENT_VERSION,
    ) {
    }

    public function current(): string
    {
        $payload = [
            'runtime' => $this->runtime->current(),
            'token' => $this->deploymentId,
            'series' => $this->compatibilitySeries,
            'loaded' => $this->loadedClassVersion,
            'schema' => $this->schemaVersion,
        ];
        $json = json_encode($payload);

        return hash('sha256', is_string($json) ? $json : $this->loadedClassVersion);
    }

    public function runtimeFingerprint(): string
    {
        return $this->runtime->current();
    }

    public function deploymentId(): string
    {
        return $this->deploymentId;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $runtime = $this->runtime->describe();

        return [
            'package' => $runtime['package'],
            'loaded' => $runtime['loaded'],
            'schema' => $runtime['schema'],
            'compatibility_series' => $this->compatibilitySeries,
            'deployment_id' => $this->deploymentId,
            'runtime_generation' => $this->runtimeFingerprint(),
            'deployment_generation' => $this->current(),
            'plugins' => $runtime['plugins'],
            'network_plugins' => $runtime['network_plugins'],
            'theme' => $runtime['theme'],
        ];
    }
}
