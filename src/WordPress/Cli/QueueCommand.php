<?php

declare(strict_types=1);

namespace Fuzeo\Queue\WordPress\Cli;

use Fuzeo\Queue\Drivers\FailureStore;
use Fuzeo\Queue\Drivers\MySql\MySqlDriver;
use Fuzeo\Queue\Drivers\ProvidesWorkerStore;
use Fuzeo\Queue\Drivers\StatusAware;
use Fuzeo\Queue\Exceptions\UniqueConflictException;
use Fuzeo\Queue\Jobs\EnvelopeRedactor;
use Fuzeo\Queue\Schedule\SchedulerLoop;
use Fuzeo\Queue\Persistence\SchemaOwner;
use Fuzeo\Queue\Retention\RetentionPolicy;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Runtime\PackageInfo;
use Fuzeo\Queue\Support\SecretRedactor;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;

final class QueueCommand
{
    /**
     * ## OPTIONS
     *
     * [--queue=<queues>]
     * : Comma-separated queue names. Preference is left to right.
     *
     * [--sleep=<seconds>]
     * : Seconds to wait when no job is reserved.
     *
     * [--timeout=<seconds>]
     * : Soft job timeout (pcntl alarm when available).
     *
     * [--lease=<seconds>]
     * : Reservation lease. Should exceed job timeout.
     *
     * [--memory=<limit>]
     * : Recycle after this RSS, e.g. 128M.
     *
     * [--max-jobs=<count>]
     * : Recycle after N jobs. 0 is unlimited.
     *
     * [--max-runtime=<seconds>]
     * : Recycle after this runtime. 0 is unlimited.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function work(array $args, array $assoc): void
    {
        unset($args);
        $runtime = Coordinator::get();
        $options = WorkerOptions::fromCli($assoc, $runtime->config()->defaultQueue, $runtime->config());
        $worker = WorkerLoop::fromManager($runtime, $options);
        if (class_exists('WP_CLI')) {
            \WP_CLI::log('Fuzeo Queue worker ' . $worker->identity()->workerId . ' listening on ' . implode(',', $options->queues));
        }
        $worker->run();
        $this->halt($worker->exitCode());
    }

    /**
     * Persistent scheduler. Dispatches due schedules as ordinary jobs.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function scheduleWork(array $args, array $assoc): void
    {
        unset($args);
        $runtime = Coordinator::get();
        $options = WorkerOptions::fromCli($assoc, $runtime->config()->defaultQueue, $runtime->config());
        $loop = SchedulerLoop::fromManager($runtime, $options);
        if (class_exists('WP_CLI')) {
            \WP_CLI::log('Fuzeo Queue scheduler ' . $loop->schedulerId() . ' running');
        }
        $loop->run();
        $this->halt(\Fuzeo\Queue\Deployment\ProcessExitCode::fromReason($loop->recycleReason()));
    }

    /**
     * One-shot due schedule dispatch (cron/degraded hosting).
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function scheduleRun(array $args, array $assoc): void
    {
        unset($assoc);
        $runtime = Coordinator::get();
        $id = $args[0] ?? '';
        $n = $id === '' ? $runtime->scheduler()->runDue() : $runtime->scheduler()->runOne($id);
        $this->line('dispatched=' . $n);
    }

    /**
     * List or manage schedules.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function schedules(array $args, array $assoc): void
    {
        unset($assoc);
        $runtime = Coordinator::get();
        $action = $args[0] ?? 'list';
        $id = $args[1] ?? ($action !== 'list' && $action !== 'enable' && $action !== 'disable' && $action !== 'show' ? $action : '');
        if (in_array($action, ['show', 'enable', 'disable'], true)) {
            $id = $args[1] ?? '';
        }
        if ($action === 'show') {
            $schedule = $runtime->schedules()->get($id);
            if ($schedule === null) {
                $this->error('Unknown schedule ' . $id . '.');
                return;
            }
            $this->printLines([
                'id' => $schedule->scheduleId,
                'name' => $schedule->name,
                'origin' => $schedule->origin->package,
                'job_type' => $schedule->jobType,
                'expression' => $schedule->expression->type->value . ' ' . $schedule->expression->value,
                'timezone' => $schedule->timezone,
                'enabled' => $schedule->enabled ? 'yes' : 'no',
                'blocked' => $schedule->blockedReason ?? '',
                'next_run' => $schedule->nextRunAt->format(\DateTimeInterface::ATOM),
                'last_run' => $schedule->lastRunAt?->format(\DateTimeInterface::ATOM) ?? '',
                'last_result' => (string) $schedule->lastResult,
            ]);
            return;
        }
        if ($action === 'enable') {
            $runtime->schedules()->enable($id);
            $this->line('enabled ' . $id);
            return;
        }
        if ($action === 'disable') {
            $runtime->schedules()->disable($id);
            $this->line('disabled ' . $id);
            return;
        }
        foreach ($runtime->schedules()->all() as $schedule) {
            $this->line(sprintf(
                '%s name=%s origin=%s type=%s tz=%s enabled=%s next=%s last=%s result=%s blocked=%s',
                $schedule->scheduleId,
                $schedule->name,
                $schedule->origin->package,
                $schedule->jobType,
                $schedule->timezone,
                $schedule->enabled ? 'yes' : 'no',
                $schedule->nextRunAt->format(\DateTimeInterface::ATOM),
                $schedule->lastRunAt?->format(\DateTimeInterface::ATOM) ?? '-',
                (string) $schedule->lastResult,
                (string) $schedule->blockedReason
            ));
        }
    }

    /**
     * Inspect uniqueness claims. Destructive release requires --force.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function unique(array $args, array $assoc): void
    {
        $runtime = Coordinator::get();
        $action = $args[0] ?? 'list';
        if ($action === 'release') {
            if (!isset($assoc['force'])) {
                $this->error('Releasing uniqueness can allow duplicate jobs. Pass --force if you understand the risk.');
                return;
            }
            $hash = $args[1] ?? '';
            $runtime->unique()->forceRelease($hash);
            $this->line('released ' . $hash);
            return;
        }
        foreach ($runtime->unique()->list() as $row) {
            $this->line(sprintf(
                '%s job=%s key=%s origin=%s type=%s expires=%s',
                $row->hash,
                $row->jobId,
                $row->uniqueKey,
                $row->originPackage,
                $row->jobType,
                $row->expiresAt?->format(\DateTimeInterface::ATOM) ?? '-'
            ));
        }
    }

    /**
     * Inspect idempotency records. Destructive reset is not provided.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function idempotency(array $args, array $assoc): void
    {
        unset($args, $assoc);
        $this->line('Idempotency diagnostics are lookup-based. Destructive reset is not provided because it can duplicate external side effects.');
    }

    /**
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        unset($args, $assoc);
        $runtime = Coordinator::get();
        $driver = $runtime->driver();
        $health = $driver->health();
        $lines = [
            'runtime' => PackageInfo::VERSION,
            'schema' => (string) SchemaOwner::CURRENT_VERSION,
            'driver' => $health->driver,
            'ok' => $health->ok ? 'yes' : 'no',
        ];
        if ($health->message !== null) {
            $lines['message'] = $health->message;
        }
        if ($driver instanceof StatusAware) {
            $counts = $driver->countsByState();
            foreach (['pending', 'reserved', 'completed', 'dead', 'failed'] as $state) {
                $lines[$state] = (string) ($counts[$state] ?? 0);
            }
        }
        if ($driver instanceof StatusAware) {
            $lines['retrying'] = (string) $driver->retryingCount();
        }
        if (isset($health->details['endpoint']) && is_string($health->details['endpoint'])) {
            $lines['redis'] = $health->details['endpoint'];
        }
        if (isset($health->details['maxmemory_policy']) && is_string($health->details['maxmemory_policy'])) {
            $lines['redis_policy'] = $health->details['maxmemory_policy'];
        }
        $previous = $runtime->config()->driver;
        $lines['backend'] = $previous;
        $schedulers = $runtime->schedules()->store()->schedulers();
        $lines['schedulers'] = (string) count($schedulers);
        if ($schedulers !== []) {
            $latest = $schedulers[0]['last_heartbeat_at'] ?? '';
            $lines['scheduler_heartbeat'] = is_string($latest) ? $latest : '';
        }
        $this->printLines($lines);
    }

    /**
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function workers(array $args, array $assoc): void
    {
        unset($args, $assoc);
        $runtime = Coordinator::get();
        $driver = $runtime->driver();
        if (!$driver instanceof ProvidesWorkerStore) {
            $this->error('Worker registry is not available for this driver.');
            return;
        }
        $repo = $driver->workerStore();
        $threshold = $runtime->config()->staleWorkerSeconds;
        foreach ($repo->all() as $row) {
            $stale = $repo->isStale($row, $threshold) ? 'stale' : (string) ($row['status'] ?? '');
            $this->line(sprintf(
                '%s %s host=%s pid=%s queues=%s heartbeat=%s processed=%s',
                (string) ($row['worker_id'] ?? ''),
                $stale,
                (string) ($row['hostname'] ?? ''),
                (string) ($row['pid'] ?? ''),
                (string) ($row['queues'] ?? ''),
                (string) ($row['last_heartbeat_at'] ?? ''),
                (string) ($row['processed_count'] ?? '0')
            ));
        }
    }

    /**
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function queues(array $args, array $assoc): void
    {
        unset($args, $assoc);
        $runtime = Coordinator::get();
        $driver = $runtime->driver();
        if (!$driver instanceof MySqlDriver) {
            $this->error('Queue depth requires the mysql driver.');
            return;
        }
        foreach ($driver->queueSizes() as $row) {
            $this->line($row['queue'] . ' pending=' . $row['pending']);
        }
    }

    /**
     * List, inspect, or manually retry dead/failed jobs.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : list, show, or retry. Default: list.
     *
     * [<id>]
     * : Job ULID for show/retry.
     *
     * [--payload]
     * : Include a redacted payload on show.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function failed(array $args, array $assoc): void
    {
        $store = $this->failureStore();
        if ($store === null) {
            $this->error('Failed-job inspection requires a driver that implements FailureStore.');
            return;
        }
        $action = $args[0] ?? 'list';
        if ($action === 'show') {
            $id = $args[1] ?? '';
            $this->showFailed($store, $id, isset($assoc['payload']));
            return;
        }
        if ($action === 'retry') {
            $id = $args[1] ?? '';
            $this->retryFailed($store, $id);
            return;
        }
        foreach ($store->listStopped() as $envelope) {
            $last = $store->attemptsFor($envelope->jobId);
            $reason = $last === [] ? '' : $last[array_key_last($last)]->sanitizedMessage;
            $this->line(sprintf(
                '%s type=%s queue=%s origin=%s site=%s attempts=%d/%d state=%s last=%s',
                $envelope->jobId,
                $envelope->jobType,
                $envelope->queue,
                $envelope->origin->package,
                (string) $envelope->context->siteId,
                $envelope->attempt,
                $envelope->maxAttempts,
                $envelope->state->value,
                $reason
            ));
        }
    }

    /**
     * Queue health summary.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function health(array $args, array $assoc): void
    {
        unset($args);
        $ops = Coordinator::get()->operations();
        $json = isset($assoc['format']) && $assoc['format'] === 'json';
        $report = $ops->queueHealth(\Fuzeo\Queue\Operations\Operator::cli());
        $payload = $report->toArray();
        $payload['scheduler'] = $ops->schedulerHealth(\Fuzeo\Queue\Operations\Operator::cli())->toArray();
        $payload['metrics_degraded'] = Coordinator::get()->metrics()->isDegraded();
        if ($json) {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $this->line(is_string($encoded) ? $encoded : '{}');
            return;
        }
        $this->line('status=' . $payload['status']);
        foreach ($payload['reasons'] as $reason) {
            $this->line('reason=' . $reason);
        }
    }

    /**
     * Historical metrics.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function metrics(array $args, array $assoc): void
    {
        unset($args);
        $ops = Coordinator::get()->operations();
        $period = $assoc['period'] ?? '1h';
        $queue = $assoc['queue'] ?? null;
        $data = $ops->metrics(\Fuzeo\Queue\Operations\Operator::cli(), $period, $queue);
        if (isset($assoc['format']) && $assoc['format'] === 'json') {
            $encoded = json_encode($data, JSON_UNESCAPED_SLASHES);
            $this->line(is_string($encoded) ? $encoded : '{}');
            return;
        }
        $this->printLines([
            'period' => (string) $data['period'],
            'resolution' => (string) $data['resolution'],
            'degraded' => !empty($data['degraded']) ? 'yes' : 'no',
            'runtime_p95' => (string) ((is_array($data['runtime'] ?? null) ? $data['runtime']['p95'] : 0) ?? 0),
            'wait_p95' => (string) ((is_array($data['wait'] ?? null) ? $data['wait']['p95'] : 0) ?? 0),
        ]);
    }

    /**
     * List or show jobs.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function jobs(array $args, array $assoc): void
    {
        $ops = Coordinator::get()->operations();
        $operator = \Fuzeo\Queue\Operations\Operator::cli();
        $action = $args[0] ?? 'list';
        if ($action === 'show') {
            $id = $args[1] ?? '';
            $detail = $ops->job($operator, $id, isset($assoc['payload']));
            if (isset($assoc['format']) && $assoc['format'] === 'json') {
                $encoded = json_encode($detail, JSON_UNESCAPED_SLASHES);
                $this->line(is_string($encoded) ? $encoded : '{}');
                return;
            }
            $summary = is_array($detail['summary'] ?? null) ? $detail['summary'] : [];
            foreach ($summary as $key => $value) {
                if (!is_scalar($value) && $value !== null) {
                    continue;
                }
                $this->line($key . ': ' . (string) $value);
            }
            return;
        }
        $state = isset($assoc['state']) ? \Fuzeo\Queue\Jobs\JobState::tryFrom($assoc['state']) : null;
        $page = $ops->jobs($operator, new \Fuzeo\Queue\Inspection\JobQuery(
            state: $state,
            queue: $assoc['queue'] ?? null,
            jobType: $assoc['type'] ?? null,
            origin: $assoc['origin'] ?? null,
            jobId: $assoc['id'] ?? null,
            limit: isset($assoc['limit']) ? (int) $assoc['limit'] : 25,
        ));
        if (isset($assoc['format']) && $assoc['format'] === 'json') {
            $encoded = json_encode(array_map(static fn ($e) => \Fuzeo\Queue\Jobs\EnvelopeRedactor::summarize($e), $page->items));
            $this->line(is_string($encoded) ? $encoded : '[]');
            return;
        }
        foreach ($page->items as $envelope) {
            $this->line(sprintf(
                '%s type=%s state=%s queue=%s origin=%s site=%s',
                $envelope->jobId,
                $envelope->jobType,
                $envelope->state->value,
                $envelope->queue,
                $envelope->origin->package,
                (string) $envelope->context->siteId
            ));
        }
    }

    /**
     * Prune completed and dead history in bounded batches.
     *
     * ## OPTIONS
     *
     * [--batch-size=<n>]
     * : Rows per state per invocation.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function prune(array $args, array $assoc): void
    {
        unset($args);
        $store = $this->failureStore();
        if ($store === null) {
            $this->error('Prune requires a driver that implements FailureStore.');
            return;
        }
        $runtime = Coordinator::get();
        $batch = isset($assoc['batch-size']) ? (int) $assoc['batch-size'] : 500;
        $policy = new RetentionPolicy(
            $runtime->config()->completedRetentionDays,
            $runtime->config()->deadRetentionDays,
        );
        $result = $store->prune($policy, $batch);
        $orch = $runtime->orchestrator()->prune(
            $policy->completedBefore($runtime->clock()->now()),
            $policy->deadBefore($runtime->clock()->now()),
            $batch
        );
        $this->line(
            'pruned completed=' . $result->completedDeleted
            . ' dead=' . $result->deadDeleted
            . ' attempts=' . $result->attemptsDeleted
            . ' orchestration=' . $orch
            . ' metrics_audit=' . Coordinator::get()->operations()->prune(\Fuzeo\Queue\Operations\Operator::cli(), $batch)
        );
    }

    private function failureStore(): ?FailureStore
    {
        $driver = Coordinator::get()->driver();

        return $driver instanceof FailureStore ? $driver : null;
    }

    private function showFailed(FailureStore $store, string $id, bool $includePayload): void
    {
        if ($id === '') {
            $this->error('Provide a job id.');
            return;
        }
        try {
            $envelope = $store->job($id);
        } catch (\Fuzeo\Queue\Exceptions\DriverException) {
            $this->error('Unknown job ' . $id . '.');
            return;
        }
        if ($envelope->state->value !== 'dead' && $envelope->state->value !== 'failed') {
            $this->error('Job ' . $id . ' is ' . $envelope->state->value . ', not dead/failed.');
            return;
        }
        $summary = EnvelopeRedactor::summarize($envelope, $includePayload);
        foreach ($summary as $key => $value) {
            if (is_array($value)) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
                $this->line($key . ': ' . (is_string($encoded) ? $encoded : '[unencodable]'));
                continue;
            }
            $this->line($key . ': ' . (is_scalar($value) || $value === null ? (string) $value : ''));
        }
        $redactor = new SecretRedactor();
        foreach ($store->attemptsFor($id) as $attempt) {
            $this->line(sprintf(
                'attempt %d outcome=%s class=%s message=%s retry=%s',
                $attempt->attempt,
                $attempt->outcome,
                (string) $attempt->failureClass,
                $redactor->redactMessage($attempt->sanitizedMessage),
                $attempt->willRetry ? 'yes' : 'no'
            ));
            if ($attempt->sanitizedTrace !== '') {
                $this->line($attempt->sanitizedTrace);
            }
        }
    }

    private function retryFailed(FailureStore $store, string $id): void
    {
        if ($id === '') {
            $this->error('Provide a job id.');
            return;
        }
        try {
            $revived = $store->revive($id);
        } catch (UniqueConflictException $exception) {
            $this->error('Retry refused: unique key is held by ' . $exception->existingJobId . '.');
            return;
        }
        $this->line('retried ' . $revived->jobId . ' state=' . $revived->state->value);
        $runtime = Coordinator::get();
        $runtime->orchestrator()->onRevived($revived);
    }

    /**
     * List or manage chains.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function chains(array $args, array $assoc): void
    {
        unset($assoc);
        $orch = Coordinator::get()->orchestrator();
        $action = $args[0] ?? 'list';
        $id = $args[1] ?? '';
        if ($action === 'show') {
            $chain = $orch->store()->getChain($id);
            if ($chain === null) {
                $this->error('Unknown chain ' . $id . '.');
                return;
            }
            $this->printLines([
                'id' => $chain->chainId,
                'state' => $chain->state->value,
                'step' => $chain->currentStep . '/' . $chain->totalSteps,
                'origin' => $chain->origin->package,
                'site' => (string) $chain->context->siteId,
                'failed_step' => (string) ($chain->failedStep ?? ''),
                'failed_job' => (string) ($chain->failedJobId ?? ''),
            ]);
            return;
        }
        if ($action === 'cancel') {
            $orch->cancelChain($id);
            $this->line('cancelled ' . $id);
            return;
        }
        if ($action === 'retry') {
            $orch->retryChain($id);
            $this->line('retried ' . $id);
            return;
        }
        foreach ($orch->store()->listChains() as $chain) {
            $this->line(sprintf(
                '%s state=%s step=%d/%d origin=%s site=%s',
                $chain->chainId,
                $chain->state->value,
                $chain->currentStep,
                $chain->totalSteps,
                $chain->origin->package,
                (string) $chain->context->siteId
            ));
        }
    }

    /**
     * List or manage batches.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function batches(array $args, array $assoc): void
    {
        unset($assoc);
        $orch = Coordinator::get()->orchestrator();
        $action = $args[0] ?? 'list';
        $id = $args[1] ?? '';
        if ($action === 'show') {
            $batch = $orch->store()->getBatch($id);
            if ($batch === null) {
                $this->error('Unknown batch ' . $id . '.');
                return;
            }
            $done = $batch->completedJobs + $batch->failedJobs + $batch->cancelledJobs;
            $this->printLines([
                'id' => $batch->batchId,
                'name' => (string) $batch->name,
                'state' => $batch->state->value,
                'progress' => $done . '/' . $batch->totalJobs,
                'completed' => (string) $batch->completedJobs,
                'failed' => (string) $batch->failedJobs,
                'cancelled' => (string) $batch->cancelledJobs,
                'origin' => $batch->origin->package,
                'site' => (string) $batch->context->siteId,
                'policy' => $batch->failurePolicy->value,
            ]);
            return;
        }
        if ($action === 'cancel') {
            $orch->cancelBatch($id);
            $this->line('cancelled ' . $id);
            return;
        }
        foreach ($orch->store()->listBatches() as $batch) {
            $done = $batch->completedJobs + $batch->failedJobs + $batch->cancelledJobs;
            $this->line(sprintf(
                '%s state=%s progress=%d/%d origin=%s site=%s',
                $batch->batchId,
                $batch->state->value,
                $done,
                $batch->totalJobs,
                $batch->origin->package,
                (string) $batch->context->siteId
            ));
        }
    }

    /**
     * Cancel a job. Does not kill executing PHP.
     *
     * ## OPTIONS
     *
     * <id>
     * : Job ULID.
     *
     * [--force]
     * : Required. Cancellation is a terminal operator decision.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function cancel(array $args, array $assoc): void
    {
        if (!isset($assoc['force'])) {
            $this->error('Cancellation does not terminate executing PHP and is terminal. Pass --force if you understand the risk.');
            return;
        }
        $id = $args[0] ?? '';
        if ($id === '') {
            $this->error('Provide a job id.');
            return;
        }
        $result = Coordinator::get()->orchestrator()->cancelJob($id);
        $this->line('cancel ' . $id . ' outcome=' . $result->outcome);
    }

    /**
     * Request workers and schedulers to recycle after current work.
     *
     * ## OPTIONS
     *
     * [--wait]
     * : Wait until old processes exit.
     *
     * [--timeout=<seconds>]
     * : Wait timeout. Default 60.
     *
     * [--format=<format>]
     * : table or json.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function restart(array $args, array $assoc): void
    {
        unset($args);
        $ops = Coordinator::get()->operations();
        $result = $ops->requestRestart(\Fuzeo\Queue\Operations\Operator::cli());
        if (isset($assoc['wait'])) {
            $timeout = isset($assoc['timeout']) && is_numeric($assoc['timeout']) ? (int) $assoc['timeout'] : 60;
            $result = array_merge($result, $ops->waitForRestart($timeout, Coordinator::get()->config()->expectedWorkerCount));
            if (!empty($result['no_process_manager'])) {
                $result['warning'] = 'No replacement worker detected. Configure a process manager.';
            }
        }
        $this->emit($result, $assoc);
        if (!empty($result['timed_out'])) {
            $this->halt(1);
        }
    }

    /**
     * Stop workers from reserving new jobs. Does not kill active PHP.
     *
     * ## OPTIONS
     *
     * [--wait]
     * : Wait until reserved work finishes.
     *
     * [--timeout=<seconds>]
     * : Wait timeout. Default 300.
     *
     * [--cancel]
     * : Cancel an in-progress drain.
     *
     * [--format=<format>]
     * : table or json.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function drain(array $args, array $assoc): void
    {
        unset($args);
        $ops = Coordinator::get()->operations();
        if (isset($assoc['cancel'])) {
            $this->emit($ops->cancelDrain(\Fuzeo\Queue\Operations\Operator::cli()), $assoc);

            return;
        }
        $result = $ops->requestDrain(\Fuzeo\Queue\Operations\Operator::cli());
        if (isset($assoc['wait'])) {
            $timeout = isset($assoc['timeout']) && is_numeric($assoc['timeout']) ? (int) $assoc['timeout'] : 300;
            $result = array_merge($result, $ops->waitForDrain($timeout));
        } else {
            $result = array_merge($result, $ops->drainProgress());
        }
        $this->emit($result, $assoc);
        if (!empty($result['timed_out'])) {
            $this->halt(1);
        }
    }

    /**
     * Process/deployment readiness. Exit 0 when the fleet may accept work.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table or json.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function ready(array $args, array $assoc): void
    {
        unset($args);
        $report = Coordinator::get()->operations()->readiness(\Fuzeo\Queue\Operations\Operator::cli());
        $this->emit($report->toArray(), $assoc);
        $this->halt($report->exitCode);
    }

    /**
     * Apply Fuzeo Queue schema migrations owned by this package.
     *
     * ## OPTIONS
     *
     * [--check]
     * : Report whether a migration is required without applying it.
     *
     * [--format=<format>]
     * : table or json.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function migrate(array $args, array $assoc): void
    {
        unset($args);
        try {
            $result = Coordinator::get()->operations()->migrate(
                \Fuzeo\Queue\Operations\Operator::cli(),
                isset($assoc['check'])
            );
        } catch (\Fuzeo\Queue\Exceptions\DriverException $exception) {
            $this->error($exception->getMessage());
            $this->halt(1);

            return;
        }
        $this->emit($result, $assoc);
        if (!empty($assoc['check']) && !empty($result['required'])) {
            $this->halt(\Fuzeo\Queue\Deployment\ProcessExitCode::SCHEMA);
        }
    }

    /**
     * Operator support snapshot. Safe to paste into a GitHub issue. Never includes job payloads.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table or json. json is recommended for bug reports.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function diagnostics(array $args, array $assoc): void
    {
        unset($args);
        $ops = Coordinator::get()->operations();
        $payload = $ops->diagnostics(\Fuzeo\Queue\Operations\Operator::cli());
        $payload['runtime_diagnostics'] = array_map(
            static fn ($row) => (array) $row,
            Coordinator::diagnostics()
        );
        $assoc['format'] = $assoc['format'] ?? 'json';
        $this->emit($payload, $assoc);
    }

    /**
     * Reconcile chain/batch progression after crashes.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function reconcile(array $args, array $assoc): void
    {
        unset($args, $assoc);
        $n = Coordinator::get()->orchestrator()->reconcile(100);
        $this->line('reconciled=' . $n);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $assoc
     */
    private function emit(array $payload, array $assoc): void
    {
        if (isset($assoc['format']) && $assoc['format'] === 'json') {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $this->line(is_string($encoded) ? $encoded : '{}');

            return;
        }
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
                $this->line($key . ': ' . (is_string($encoded) ? $encoded : ''));
                continue;
            }
            if (is_bool($value)) {
                $this->line($key . ': ' . ($value ? 'yes' : 'no'));
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $this->line($key . ': ' . (string) $value);
            }
        }
    }

    /**
     * @param array<string, string> $lines
     */
    private function printLines(array $lines): void
    {
        foreach ($lines as $key => $value) {
            $this->line($key . ': ' . $value);
        }
    }

    private function line(string $message): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::log($message);
            return;
        }
        echo $message . PHP_EOL;
    }

    private function halt(int $code): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::halt($code);
        }
    }

    private function error(string $message): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::error($message);
            return;
        }
        fwrite(STDERR, $message . PHP_EOL);
    }
}
