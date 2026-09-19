<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Exceptions;

/**
 * Job must not be executed again automatically.
 */
class TerminalException extends QueueException
{
}
