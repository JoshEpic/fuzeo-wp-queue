<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Retry;

use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Exceptions\RetryAfterException;
use Fuzeo\Queue\Exceptions\RetryableException;
use Fuzeo\Queue\Exceptions\TerminalException;
use Fuzeo\Queue\Jobs\Envelope;
use Fuzeo\Queue\Jobs\JobState;

final class FailureClassifier
{
    public function decide(
        \Throwable $throwable,
        Envelope $envelope,
        RetryPolicy $policy,
        Clock $clock,
        RandomSource $random = new SystemRandom(),
    ): RetryDecision {
        if (!$this->isRetryable($throwable)) {
            return new RetryDecision(
                JobState::Dead,
                null,
                false,
                'terminal',
                $this->terminalReason($throwable),
            );
        }

        if (!$policy->attemptsRemain($envelope->attempt)) {
            return new RetryDecision(
                JobState::Dead,
                null,
                false,
                'exhausted',
                'attempts_exhausted',
            );
        }

        $override = $throwable instanceof RetryAfterException ? $throwable->seconds : null;
        $availableAt = $policy->nextAvailable($envelope->attempt, $clock, $override, $random);

        return new RetryDecision(JobState::Pending, $availableAt, true, 'retryable', null);
    }

    public function isRetryable(\Throwable $throwable): bool
    {
        if ($throwable instanceof TerminalException) {
            return false;
        }
        if ($throwable instanceof RetryableException) {
            return true;
        }
        if ($throwable instanceof \TypeError || $throwable instanceof \ParseError) {
            return false;
        }
        if ($throwable instanceof \Error) {
            return false;
        }

        return $throwable instanceof \Exception;
    }

    public function terminalReason(\Throwable $throwable): string
    {
        return match (true) {
            $throwable instanceof \Fuzeo\Queue\Exceptions\UnknownJobException => 'unknown_job_type',
            $throwable instanceof \Fuzeo\Queue\Exceptions\UnsupportedSchemaException => 'unsupported_schema',
            $throwable instanceof \Fuzeo\Queue\Exceptions\UnsupportedEnvelopeException => 'unsupported_envelope',
            $throwable instanceof \Fuzeo\Queue\Exceptions\SiteUnavailableException => 'site_unavailable',
            $throwable instanceof \TypeError => 'type_error',
            $throwable instanceof \Error => 'php_error',
            default => 'terminal_failure',
        };
    }
}
