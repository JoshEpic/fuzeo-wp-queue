<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

use Fuzeo\Queue\Jobs\Origin;

final class DescriptorIdentity
{
    public function __construct(
        public readonly string $originPackage,
        public readonly SourceSystem $source,
        public readonly string $sourceHook,
        public readonly int $version,
        public readonly string $group = '',
    ) {
    }

    public function registrationKey(): string
    {
        return implode('|', [$this->source->value, $this->sourceHook, $this->group]);
    }

    public function id(): string
    {
        return implode('|', [$this->originPackage, $this->source->value, $this->sourceHook, $this->group, (string) $this->version]);
    }

    public static function actionScheduler(Origin $origin, string $hook, int $version, string $group = ''): self
    {
        return new self($origin->package, SourceSystem::ActionScheduler, $hook, $version, $group);
    }

    public static function cron(Origin $origin, string $hook, int $version): self
    {
        return new self($origin->package, SourceSystem::WpCron, $hook, $version, '');
    }
}
