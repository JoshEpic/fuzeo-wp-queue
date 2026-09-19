<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

final class RuntimeDispatch
{
    public function __construct(
        public readonly RuntimeName $runtime,
        public readonly string $id,
        public readonly string $kind,
    ) {
    }

    /**
     * @return array{runtime: string, id: string, kind: string}
     */
    public function toArray(): array
    {
        return [
            'runtime' => $this->runtime->value,
            'id' => $this->id,
            'kind' => $this->kind,
        ];
    }
}
