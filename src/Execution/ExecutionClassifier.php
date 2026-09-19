<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Execution;

use Fuzeo\Queue\Config\Config;
use Fuzeo\Queue\Jobs\DispatchOptions;
use Fuzeo\Queue\Jobs\Job;
use Fuzeo\Queue\Jobs\RequiresExecutionCapabilities;
use Fuzeo\Queue\Jobs\RequiresPersistentWorker;

final class ExecutionClassifier
{
    public static function classify(
        ?Job $job,
        DispatchOptions $options,
        Config $config,
        string $queue,
    ): ExecutionClass {
        if ($options->executionClass === ExecutionClass::Persistent) {
            return ExecutionClass::Persistent;
        }
        if ($job instanceof RequiresPersistentWorker) {
            return ExecutionClass::Persistent;
        }
        if ($job instanceof RequiresExecutionCapabilities) {
            foreach ($job->requiredCapabilities() as $capability) {
                if (in_array($capability, ['persistent_worker', 'long_running', 'persistent'], true)) {
                    return ExecutionClass::Persistent;
                }
            }
        }
        foreach ($config->persistentQueues as $name) {
            if ($name === $queue) {
                return ExecutionClass::Persistent;
            }
        }

        return $options->executionClass ?? ExecutionClass::Standard;
    }

    public static function compatEligible(ExecutionClass $class, int $timeoutSeconds, Config $config): bool
    {
        if ($class === ExecutionClass::Persistent) {
            return false;
        }

        return $timeoutSeconds <= $config->compatibilityMaxJobTimeout;
    }
}
