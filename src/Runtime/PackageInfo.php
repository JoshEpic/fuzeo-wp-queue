<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

final class PackageInfo
{
    public const VERSION = '0.2.0';

    /**
     * Runtime compatibility epoch. Incompatible bundled copies must not share a runtime.
     * This is independent of Composer major while the package is 0.x.
     */
    public const COMPATIBILITY_SERIES = 1;

    public const ENVELOPE_VERSION = 1;
}
