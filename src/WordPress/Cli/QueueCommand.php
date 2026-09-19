<?php

declare(strict_types=1);

namespace Fuzeo\Queue\WordPress\Cli;

use Fuzeo\Queue\Drivers\MySql\MySqlDriver;
use Fuzeo\Queue\Persistence\SchemaOwner;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Runtime\PackageInfo;
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
