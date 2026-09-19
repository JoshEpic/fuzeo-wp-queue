<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Schedule;

enum OverlapPolicy: string
{
    case Allow = 'allow';

    case Skip = 'skip';
}
