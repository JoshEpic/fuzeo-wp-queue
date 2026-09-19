<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Exceptions;

/**
 * Handler may run again after backoff. Does not imply the previous attempt had no side effects.
 */
class RetryableException extends QueueException
{
}
