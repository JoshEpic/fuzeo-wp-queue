<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Operations;

final class HealthReport
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public readonly HealthStatus $status,
        public readonly array $reasons,
        public readonly string $scope = 'queue',
    ) {
    }

    /**
     * @return array{status: string, reasons: list<string>, scope: string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'reasons' => $this->reasons,
            'scope' => $this->scope,
        ];
    }
}
