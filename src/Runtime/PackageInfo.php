<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

final class PackageInfo
{
    public const VERSION = '1.1.0';

    /**
     * Runtime coexistence epoch for bundled copies. Independent of Composer SemVer.
     * 1.0.0 remains series 1: compatible 1.x copies may share one runtime.
     */
    public const COMPATIBILITY_SERIES = 1;

    public const ENVELOPE_VERSION = 1;
}
