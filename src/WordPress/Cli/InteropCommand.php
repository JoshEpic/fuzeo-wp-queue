<?php

declare(strict_types=1);

namespace Fuzeo\Queue\WordPress\Cli;

use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Runtime\Coordinator;

final class InteropCommand
{
    /**
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        unset($args);
        $origin = new Origin('fuzeowp/queue', \Fuzeo\Queue\Runtime\PackageInfo::VERSION);
        $data = Coordinator::get()->interop()->status($origin, $this->site($assoc), $this->network());
        $this->out($data, $assoc);
    }

    /**
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function runtime(array $args, array $assoc): void
    {
        $this->status($args, $assoc);
    }

    /**
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function actionScheduler(array $args, array $assoc): void
    {
        unset($args);
        $site = $this->site($assoc);
        $data = [
            'summary' => Coordinator::get()->interop()->actionSchedulerSummary(),
            'candidates' => Coordinator::get()->interop()->actionSchedulerCandidates($site, $this->network(), 50, 0),
        ];
        $this->out($data, $assoc);
    }

    /**
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function cron(array $args, array $assoc): void
    {
        unset($args);
        $site = $this->site($assoc);
        $data = [
            'summary' => Coordinator::get()->interop()->cronInspector($site, $this->network())->summary(),
            'candidates' => Coordinator::get()->interop()->cronCandidates($site, $this->network()),
        ];
        $this->out($data, $assoc);
    }

    /**
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function plan(array $args, array $assoc): void
    {
        $hook = $args[0] ?? '';
        if ($hook === '') {
            $this->error('Provide a descriptor hook.');

            return;
        }
        $context = ExecutionContext::site($this->network(), $this->site($assoc));
        $system = $assoc['source'] ?? 'cron';
        $plan = $system === 'action-scheduler'
            ? Coordinator::get()->interop()->planActionScheduler($hook, $context, $assoc['action-id'] ?? null, isset($assoc['pending']))
            : Coordinator::get()->interop()->planCron($hook, $context);
        $this->out($plan->toArray(), $assoc);
    }

    /**
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function migrate(array $args, array $assoc): void
    {
        $hook = $args[0] ?? '';
        if ($hook === '') {
            $this->error('Provide a hook.');

            return;
        }
        $execute = isset($assoc['execute']);
        $context = ExecutionContext::site($this->network(), $this->site($assoc));
        $system = $assoc['source'] ?? 'cron';
        $plan = $system === 'action-scheduler'
            ? Coordinator::get()->interop()->planActionScheduler($hook, $context, $assoc['action-id'] ?? null, isset($assoc['pending']))
            : Coordinator::get()->interop()->planCron($hook, $context);
        $record = Coordinator::get()->interop()->migrate($plan, $execute);
        $this->out($record->toArray(), $assoc);
    }

    /**
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function rollback(array $args, array $assoc): void
    {
        $id = $args[0] ?? '';
        if ($id === '') {
            $this->error('Provide a migration id.');

            return;
        }
        $record = Coordinator::get()->interop()->rollback($id);
        $this->out($record->toArray(), $assoc);
    }

    /**
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function reconcile(array $args, array $assoc): void
    {
        $id = $args[0] ?? '';
        if ($id === '') {
            $this->error('Provide a migration id.');

            return;
        }
        $record = Coordinator::get()->interop()->reconcile($id);
        $this->out($record->toArray(), $assoc);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $assoc
     */
    private function out(array $data, array $assoc): void
    {
        $pretty = ($assoc['format'] ?? '') !== 'json';
        $json = (string) json_encode($data, JSON_UNESCAPED_SLASHES | ($pretty ? JSON_PRETTY_PRINT : 0));
        if (class_exists('WP_CLI')) {
            \WP_CLI::log($json);

            return;
        }
        echo $json . PHP_EOL;
    }

    private function error(string $message): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::error($message);

            return;
        }
        fwrite(STDERR, $message . PHP_EOL);
    }

    /**
     * @param array<string, string> $assoc
     */
    private function site(array $assoc): int
    {
        if (isset($assoc['site']) && is_numeric($assoc['site'])) {
            return (int) $assoc['site'];
        }

        return function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
    }

    private function network(): int
    {
        if (function_exists('get_current_network_id')) {
            return (int) get_current_network_id();
        }

        return 1;
    }
}
