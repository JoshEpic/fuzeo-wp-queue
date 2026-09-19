<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Config;

/**
 * Precedence (highest first):
 * 1. Explicit configure() / constructor values
 * 2. Environment variables FUZEO_QUEUE_*
 * 3. WordPress constants FUZEO_QUEUE_*
 * 4. WordPress filter fuzeo_queue_config
 * 5. Package defaults
 */
final class ConfigRepository
{
    /**
     * @param array<string, mixed> $explicit
     */
    public function resolve(array $explicit = []): Config
    {
        $config = new Config();
        $config = $config->merge($this->fromFilter());
        $config = $config->merge($this->fromConstants());
        $config = $config->merge($this->fromEnvironment());
        $config = $config->merge($explicit);
        ConfigValidator::validate($config);

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function fromEnvironment(): array
    {
        return $this->mapPrefix(static function (string $key): ?string {
            $value = getenv($key);
            if ($value === false || $value === '') {
                return null;
            }

            return $value;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function fromConstants(): array
    {
        return $this->mapPrefix(static function (string $key): mixed {
            return defined($key) ? constant($key) : null;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function fromFilter(): array
    {
        if (!function_exists('apply_filters')) {
            return [];
        }

        $filtered = apply_filters('fuzeo_queue_config', []);
        if (!is_array($filtered)) {
            throw new \Fuzeo\Queue\Exceptions\ConfigurationException('fuzeo_queue_config filter must return an array.');
        }

        /** @var array<string, mixed> $filtered */
        return $filtered;
    }

    /**
     * @param callable(string): mixed $lookup
     * @return array<string, mixed>
     */
    private function mapPrefix(callable $lookup): array
    {
        $map = [
            'FUZEO_QUEUE_DRIVER' => 'driver',
            'FUZEO_QUEUE_DEFAULT_QUEUE' => 'default_queue',
            'FUZEO_QUEUE_MAX_PAYLOAD_BYTES' => 'max_payload_bytes',
            'FUZEO_QUEUE_MAX_PAYLOAD_DEPTH' => 'max_payload_depth',
            'FUZEO_QUEUE_MAX_PAYLOAD_STRING_BYTES' => 'max_payload_string_bytes',
            'FUZEO_QUEUE_DEFAULT_MAX_ATTEMPTS' => 'default_max_attempts',
            'FUZEO_QUEUE_DEFAULT_TIMEOUT_SECONDS' => 'default_timeout_seconds',
            'FUZEO_QUEUE_REDACT_PAYLOADS' => 'redact_payloads',
            'FUZEO_QUEUE_LEASE_SECONDS' => 'lease_seconds',
            'FUZEO_QUEUE_WORKER_SLEEP' => 'worker_sleep',
            'FUZEO_QUEUE_WORKER_TIMEOUT' => 'worker_timeout',
            'FUZEO_QUEUE_WORKER_MEMORY' => 'worker_memory',
            'FUZEO_QUEUE_WORKER_MAX_JOBS' => 'worker_max_jobs',
            'FUZEO_QUEUE_WORKER_MAX_RUNTIME' => 'worker_max_runtime',
            'FUZEO_QUEUE_HEARTBEAT_INTERVAL' => 'heartbeat_interval',
            'FUZEO_QUEUE_STALE_WORKER_THRESHOLD' => 'stale_worker_threshold',
            'FUZEO_QUEUE_COMPLETED_RETENTION_DAYS' => 'completed_retention_days',
            'FUZEO_QUEUE_DEAD_RETENTION_DAYS' => 'dead_retention_days',
            'FUZEO_QUEUE_DEFAULT_JITTER_PERCENT' => 'default_jitter_percent',
            'FUZEO_QUEUE_REDIS_DSN' => 'redis_dsn',
            'FUZEO_QUEUE_REDIS_NAMESPACE' => 'redis_namespace',
            'FUZEO_QUEUE_CONCURRENCY' => 'concurrency',
            'FUZEO_QUEUE_RATE_LIMITS' => 'rate_limits',
            'FUZEO_QUEUE_SCHEDULE_CLAIM_LEASE_SECONDS' => 'schedule_claim_lease_seconds',
            'FUZEO_QUEUE_SCHEDULE_MAX_CATCH_UP' => 'schedule_max_catch_up',
            'FUZEO_QUEUE_SCHEDULE_CATCH_UP_CUTOFF_DAYS' => 'schedule_catch_up_cutoff_days',
            'FUZEO_QUEUE_IDEMPOTENCY_LEASE_SECONDS' => 'idempotency_lease_seconds',
            'FUZEO_QUEUE_IDEMPOTENCY_RETAIN_SECONDS' => 'idempotency_retain_seconds',
            'FUZEO_QUEUE_GENERATION_CHECK_INTERVAL' => 'generation_check_interval',
            'FUZEO_QUEUE_GC_INTERVAL' => 'gc_interval',
            'FUZEO_QUEUE_RUNTIME_RESET' => 'runtime_reset',
            'FUZEO_QUEUE_WORKER_RECYCLE_ON_CONTEXT_ERROR' => 'worker_recycle_on_context_error',
            'FUZEO_QUEUE_SITE_HEALTH_BACKLOG_CRITICAL' => 'site_health_backlog_critical',
            'FUZEO_QUEUE_METRICS_ENABLED' => 'metrics_enabled',
            'FUZEO_QUEUE_METRICS_MINUTE_HOURS' => 'metrics_minute_hours',
            'FUZEO_QUEUE_METRICS_HOUR_DAYS' => 'metrics_hour_days',
            'FUZEO_QUEUE_METRICS_DAY_DAYS' => 'metrics_day_days',
            'FUZEO_QUEUE_METRICS_INCLUDE_SITE' => 'metrics_include_site',
            'FUZEO_QUEUE_HEALTH_LAG_DEGRADED_SECONDS' => 'health_lag_degraded_seconds',
            'FUZEO_QUEUE_HEALTH_LAG_CRITICAL_SECONDS' => 'health_lag_critical_seconds',
            'FUZEO_QUEUE_HEALTH_DEAD_DEGRADED' => 'health_dead_degraded',
            'FUZEO_QUEUE_HEALTH_DEAD_CRITICAL' => 'health_dead_critical',
            'FUZEO_QUEUE_DEPLOYMENT_ID' => 'deployment_id',
            'FUZEO_QUEUE_EXPECTED_WORKER_COUNT' => 'expected_worker_count',
            'FUZEO_QUEUE_MAINTENANCE_LEASE_SECONDS' => 'maintenance_lease_seconds',
            'FUZEO_QUEUE_MAX_TAGS' => 'max_tags',
            'FUZEO_QUEUE_MAX_TAG_LENGTH' => 'max_tag_length',
            'FUZEO_QUEUE_MAX_METADATA_BYTES' => 'max_metadata_bytes',
            'FUZEO_QUEUE_MAX_METADATA_KEY_LENGTH' => 'max_metadata_key_length',
            'FUZEO_QUEUE_COMPATIBILITY_ENABLED' => 'compatibility_enabled',
            'FUZEO_QUEUE_COMPATIBILITY_MAX_RUNTIME' => 'compatibility_max_runtime',
            'FUZEO_QUEUE_COMPATIBILITY_MAX_JOBS' => 'compatibility_max_jobs',
            'FUZEO_QUEUE_COMPATIBILITY_ALLOWED_QUEUES' => 'compatibility_allowed_queues',
            'FUZEO_QUEUE_COMPATIBILITY_STALE_WORKER_GRACE' => 'compatibility_stale_worker_grace',
            'FUZEO_QUEUE_COMPATIBILITY_MAX_JOB_TIMEOUT' => 'compatibility_max_job_timeout',
            'FUZEO_QUEUE_PERSISTENT_QUEUES' => 'persistent_queues',
            'FUZEO_QUEUE_EXECUTION_MODE' => 'execution_mode',
        ];

        $values = [];
        foreach ($map as $source => $key) {
            $value = $lookup($source);
            if ($value !== null && $value !== false) {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
