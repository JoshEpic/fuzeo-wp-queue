<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Exceptions;

/**
 * Handler or worker stopped because cancellation was requested. Not a retryable failure.
 */
final class JobCancelledException extends QueueException
{
}
