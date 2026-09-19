<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Config;

use Fuzeo\Queue\Exceptions\ConfigurationException;
use Fuzeo\Queue\Jobs\QueueName;
use Fuzeo\Queue\RateLimit\RateLimit;
use Fuzeo\Queue\Redis\RedisSettings;

/**
 * Fail closed on invalid operator configuration. Restart is required after changing
 * lease, timeout, driver, Redis, or worker memory settings that processes already cached.
 */
final class ConfigValidator
{
    public static function validate(Config $config): void
    {
        $drivers = [
            Config::DRIVER_UNAVAILABLE,
            Config::DRIVER_MEMORY,
            Config::DRIVER_MYSQL,
            Config::DRIVER_REDIS,
        ];
        if (!in_array($config->driver, $drivers, true)) {
            throw new ConfigurationException(
                'Unknown queue driver "' . $config->driver . '". Use mysql, redis, memory, or unavailable.'
            );
        }
        QueueName::assertValid($config->defaultQueue);
        self::positive('max_payload_bytes', $config->maxPayloadBytes);
        self::positive('max_payload_depth', $config->maxPayloadDepth);
        self::positive('max_payload_string_bytes', $config->maxPayloadStringBytes);
        self::positive('default_max_attempts', $config->defaultMaxAttempts);
        self::positive('default_timeout_seconds', $config->defaultTimeoutSeconds);
        self::positive('lease_seconds', $config->leaseSeconds);
        if ($config->defaultTimeoutSeconds >= $config->leaseSeconds) {
            throw new ConfigurationException(
                'default_timeout_seconds (' . $config->defaultTimeoutSeconds
                . ') must be less than lease_seconds (' . $config->leaseSeconds
                . ') so a reservation outlives the job timeout. Increase the lease or lower the timeout.'
            );
        }
        if ($config->workerTimeoutSeconds >= $config->leaseSeconds) {
            throw new ConfigurationException(
                'worker_timeout (' . $config->workerTimeoutSeconds
                . ') must be less than lease_seconds (' . $config->leaseSeconds
                . '). Increase FUZEO_QUEUE_LEASE_SECONDS or lower FUZEO_QUEUE_WORKER_TIMEOUT.'
            );
        }
        if ($config->workerSleepSeconds < 0) {
            throw new ConfigurationException('worker_sleep must be >= 0.');
        }
        self::positive('worker_memory', $config->workerMemoryBytes);
        if ($config->workerMaxJobs < 0 || $config->workerMaxRuntimeSeconds < 0) {
            throw new ConfigurationException('worker_max_jobs and worker_max_runtime must be >= 0 (0 means unlimited).');
        }
        self::positive('heartbeat_interval', $config->heartbeatIntervalSeconds);
        self::positive('stale_worker_threshold', $config->staleWorkerSeconds);
        if ($config->completedRetentionDays < 0 || $config->deadRetentionDays < 0) {
            throw new ConfigurationException('Retention days must be >= 0.');
        }
        if ($config->defaultJitterPercent < 0 || $config->defaultJitterPercent > 100) {
            throw new ConfigurationException('default_jitter_percent must be between 0 and 100.');
        }
        if ($config->redisDsn !== '') {
            RedisSettings::fromDsn($config->redisDsn, $config->redisNamespace);
        }
        if ($config->driver === Config::DRIVER_REDIS && $config->redisNamespace === '') {
            throw new ConfigurationException('redis_namespace is required for the Redis driver.');
        }
        foreach ($config->concurrency as $queue => $limit) {
            QueueName::assertValid($queue);
            if ($limit < 1) {
                throw new ConfigurationException(
                    'Concurrency limit for queue "' . $queue . '" must be >= 1. Got ' . $limit . '.'
                );
            }
        }
        foreach ($config->rateLimits as $index => $row) {
            try {
                RateLimit::fromArray($row);
            } catch (ConfigurationException $exception) {
                throw new ConfigurationException(
                    'rate_limits[' . $index . '] is invalid: ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }
        }
        self::positive('schedule_claim_lease_seconds', $config->scheduleClaimLeaseSeconds);
        self::positive('schedule_max_catch_up', $config->scheduleMaxCatchUp);
        self::positive('schedule_catch_up_cutoff_days', $config->scheduleCatchUpCutoffDays);
        self::positive('idempotency_lease_seconds', $config->idempotencyLeaseSeconds);
        self::positive('idempotency_retain_seconds', $config->idempotencyRetainSeconds);
        self::positive('generation_check_interval', $config->generationCheckInterval);
        self::positive('gc_interval', $config->gcInterval);
        self::positive('site_health_backlog_critical', $config->siteHealthBacklogCritical);
        self::positive('metrics_minute_hours', $config->metricsMinuteHours);
        self::positive('metrics_hour_days', $config->metricsHourDays);
        self::positive('metrics_day_days', $config->metricsDayDays);
        self::positive('health_lag_degraded_seconds', $config->healthLagDegradedSeconds);
        self::positive('health_lag_critical_seconds', $config->healthLagCriticalSeconds);
        if ($config->healthLagCriticalSeconds < $config->healthLagDegradedSeconds) {
            throw new ConfigurationException(
                'health_lag_critical_seconds must be >= health_lag_degraded_seconds.'
            );
        }
        if ($config->healthDeadDegraded < 0 || $config->healthDeadCritical < 0) {
            throw new ConfigurationException('health_dead_* thresholds must be >= 0.');
        }
        if ($config->expectedWorkerCount < 0) {
            throw new ConfigurationException('expected_worker_count must be >= 0.');
        }
        self::positive('maintenance_lease_seconds', $config->maintenanceLeaseSeconds);
        self::positive('max_tags', $config->maxTags);
        self::positive('max_tag_length', $config->maxTagLength);
        self::positive('max_metadata_bytes', $config->maxMetadataBytes);
        self::positive('max_metadata_key_length', $config->maxMetadataKeyLength);
        if ($config->maxTags > 32) {
            throw new ConfigurationException('max_tags cannot exceed the envelope freeze of 32 tags.');
        }
        if ($config->maxTagLength > 64) {
            throw new ConfigurationException('max_tag_length cannot exceed the envelope freeze of 64 characters.');
        }
        if ($config->deploymentId !== '' && strlen($config->deploymentId) > 128) {
            throw new ConfigurationException('deployment_id must be 128 characters or fewer.');
        }
        if ($config->deploymentId !== '' && !preg_match('/^[A-Za-z0-9._:-]+$/', $config->deploymentId)) {
            throw new ConfigurationException(
                'deployment_id may contain only letters, numbers, dots, underscores, colons, and hyphens.'
            );
        }
    }

    private static function positive(string $key, int $value): void
    {
        if ($value < 1) {
            throw new ConfigurationException($key . ' must be a positive integer. Got ' . $value . '.');
        }
    }
}
