<?php

declare(strict_types=1);

namespace Fuzeo\Queue\WordPress\Cli;

use Fuzeo\Queue\Drivers\FailureStore;
use Fuzeo\Queue\Drivers\MySql\MySqlDriver;
use Fuzeo\Queue\Jobs\EnvelopeRedactor;
use Fuzeo\Queue\Persistence\SchemaOwner;
use Fuzeo\Queue\Retention\RetentionPolicy;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Runtime\PackageInfo;
use Fuzeo\Queue\Support\SecretRedactor;
use Fuzeo\Queue\Worker\WorkerLoop;
use Fuzeo\Queue\Worker\WorkerOptions;
use Fuzeo\Queue\Worker\WorkerRepository;

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
        $options = WorkerOptions::fromCli($assoc, $runtime->config()->defaultQueue);
        $worker = WorkerLoop::fromManager($runtime, $options);
        if (class_exists('WP_CLI')) {
            \WP_CLI::log('Fuzeo Queue worker ' . $worker->identity()->workerId . ' listening on ' . implode(',', $options->queues));
        }
        $worker->run();
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
        if (method_exists($driver, 'countsByState')) {
            /** @var array<string, int> $counts */
            $counts = $driver->countsByState();
            foreach (['pending', 'reserved', 'completed', 'dead', 'failed'] as $state) {
                $lines[$state] = (string) ($counts[$state] ?? 0);
            }
        }
        if (method_exists($driver, 'retryingCount')) {
            $lines['retrying'] = (string) $driver->retryingCount();
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
        if (!$driver instanceof MySqlDriver) {
            $this->error('Worker registry requires the mysql driver.');
            return;
        }
        $repo = new WorkerRepository($driver->connection(), $runtime->clock());
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
        $this->line(
            'pruned completed=' . $result->completedDeleted
            . ' dead=' . $result->deadDeleted
            . ' attempts=' . $result->attemptsDeleted
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
        $revived = $store->revive($id);
        $this->line('retried ' . $revived->jobId . ' state=' . $revived->state->value);
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

    private function error(string $message): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::error($message);
            return;
        }
        fwrite(STDERR, $message . PHP_EOL);
    }
}
