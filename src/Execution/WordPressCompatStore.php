<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Execution;

use Fuzeo\Queue\Support\Dates;

final class WordPressCompatStore implements CompatStore
{
    public const OPTION = 'fuzeo_queue_compat_state';

    public function load(): CompatState
    {
        $raw = $this->get();
        if (!is_array($raw)) {
            return new CompatState();
        }

        return new CompatState(
            enabled: (bool) ($raw['enabled'] ?? false),
            lastTickAt: $this->date($raw['last_tick_at'] ?? null),
            lastSuccessAt: $this->date($raw['last_success_at'] ?? null),
            lastJobsProcessed: (int) ($raw['last_jobs_processed'] ?? 0),
            lastOutcome: (string) ($raw['last_outcome'] ?? ''),
            lastCronWorkerAt: $this->date($raw['last_cron_worker_at'] ?? null),
            lastBlockedReason: (string) ($raw['last_blocked_reason'] ?? ''),
            lastBlockedJobType: (string) ($raw['last_blocked_job_type'] ?? ''),
            lastBlockedQueue: (string) ($raw['last_blocked_queue'] ?? ''),
            wpCronConfigured: (bool) ($raw['wp_cron_configured'] ?? false),
            enabledExplicit: (bool) ($raw['enabled_explicit'] ?? false),
        );
    }

    public function save(CompatState $state): void
    {
        $payload = $state->toArray();
        $payload['enabled_explicit'] = $state->enabledExplicit;
        if (function_exists('is_multisite') && is_multisite() && function_exists('update_network_option')) {
            update_network_option(null, self::OPTION, $payload);

            return;
        }
        if (function_exists('update_option')) {
            update_option(self::OPTION, $payload);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function get(): ?array
    {
        $raw = null;
        if (function_exists('is_multisite') && is_multisite() && function_exists('get_network_option')) {
            $raw = get_network_option(null, self::OPTION);
        } elseif (function_exists('get_option')) {
            $raw = get_option(self::OPTION);
        }

        return is_array($raw) ? $raw : null;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return Dates::fromAtom($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
