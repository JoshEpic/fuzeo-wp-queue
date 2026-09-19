<?php

declare(strict_types=1);

namespace Fuzeo\Queue\WordPress\Rest;

use Fuzeo\Queue\Exceptions\DriverException;
use Fuzeo\Queue\Exceptions\UniqueConflictException;
use Fuzeo\Queue\Inspection\JobQuery;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Operations\AccessDenied;
use Fuzeo\Queue\Operations\Operator;
use Fuzeo\Queue\Operations\Operations;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\WordPress\QueueAccess;

final class RestRegistrar
{
    public const NAMESPACE = 'fuzeo-queue/v1';

    public static function register(): void
    {
        if (!function_exists('register_rest_route')) {
            return;
        }
        $kernel = &$GLOBALS['fuzeo_queue_kernel'];
        if (!is_array($kernel) || ($kernel['rest_registered'] ?? 0) > 0) {
            return;
        }
        $kernel['rest_registered'] = 1;

        add_action('rest_api_init', static function (): void {
            self::routes();
        });
    }

    private static function routes(): void
    {
        $get = ['GET'];
        $write = ['POST'];
        self::route('/overview', $get, static fn ($req) => self::ops()->overview(self::operator(), self::site($req)));
        self::route('/queues', $get, static fn ($req) => ['items' => self::ops()->queues(self::operator(), self::site($req))]);
        self::route('/jobs', $get, static function ($req): array {
            $page = self::ops()->jobs(self::operator(), self::jobQuery($req));

            return [
                'items' => array_map(static fn ($e) => \Fuzeo\Queue\Jobs\EnvelopeRedactor::summarize($e), $page->items),
                'limit' => $page->limit,
                'offset' => $page->offset,
                'total' => $page->total,
                'total_is_approximate' => $page->truncated,
            ];
        });
        self::route('/jobs/(?P<id>[A-Za-z0-9]+)', $get, static function ($req): array {
            $id = self::id($req);
            $payload = self::bool($req, 'payload');

            return self::ops()->job(self::operator(), $id, $payload);
        });
        self::route('/jobs/(?P<id>[A-Za-z0-9]+)/retry', $write, static function ($req): array {
            return self::ops()->retry(self::operator(), self::id($req));
        });
        self::route('/jobs/retry', $write, static function ($req): array {
            $ids = self::ids($req);

            return ['items' => self::ops()->retryMany(self::operator(), $ids)];
        });
        self::route('/jobs/(?P<id>[A-Za-z0-9]+)/cancel', $write, static function ($req): array {
            return self::ops()->cancel(self::operator(), self::id($req));
        });
        self::route('/workers', $get, static fn () => ['items' => self::ops()->workers(self::operator())]);
        self::route('/schedules', $get, static fn ($req) => ['items' => self::ops()->schedules(self::operator(), self::site($req))]);
        self::route('/schedules/(?P<id>[A-Za-z0-9_-]+)/enable', $write, static function ($req): array {
            self::ops()->enableSchedule(self::operator(), self::id($req));

            return ['ok' => true];
        });
        self::route('/schedules/(?P<id>[A-Za-z0-9_-]+)/disable', $write, static function ($req): array {
            self::ops()->disableSchedule(self::operator(), self::id($req));

            return ['ok' => true];
        });
        self::route('/schedules/(?P<id>[A-Za-z0-9_-]+)/run', $write, static function ($req): array {
            $n = self::ops()->runSchedule(self::operator(), self::id($req));

            return ['dispatched' => $n];
        });
        self::route('/chains', $get, static fn ($req) => ['items' => self::ops()->chains(self::operator(), self::site($req))]);
        self::route('/chains/(?P<id>[A-Za-z0-9]+)', $get, static fn ($req) => self::ops()->chain(self::operator(), self::id($req)));
        self::route('/chains/(?P<id>[A-Za-z0-9]+)/retry', $write, static function ($req): array {
            self::ops()->retryChain(self::operator(), self::id($req));

            return ['ok' => true];
        });
        self::route('/chains/(?P<id>[A-Za-z0-9]+)/cancel', $write, static function ($req): array {
            self::ops()->cancelChain(self::operator(), self::id($req));

            return ['ok' => true];
        });
        self::route('/batches', $get, static fn ($req) => ['items' => self::ops()->batches(self::operator(), self::site($req))]);
        self::route('/batches/(?P<id>[A-Za-z0-9]+)', $get, static function ($req): array {
            $offset = self::int($req, 'offset', 0);
            $limit = self::int($req, 'limit', 25);
            $status = self::string($req, 'status');

            return self::ops()->batch(self::operator(), self::id($req), $offset, $limit, $status !== '' ? $status : null);
        });
        self::route('/batches/(?P<id>[A-Za-z0-9]+)/cancel', $write, static function ($req): array {
            self::ops()->cancelBatch(self::operator(), self::id($req));

            return ['ok' => true];
        });
        self::route('/metrics', $get, static function ($req): array {
            return self::ops()->metrics(
                self::operator(),
                self::period($req),
                self::string($req, 'queue') !== '' ? self::string($req, 'queue') : null,
                self::string($req, 'job_type') !== '' ? self::string($req, 'job_type') : null,
                self::string($req, 'origin') !== '' ? self::string($req, 'origin') : null,
                self::site($req),
            );
        });
        self::route('/diagnostics', $get, static fn () => self::ops()->diagnostics(self::operator()));
        self::route('/health', $get, static fn ($req) => self::ops()->queueHealth(self::operator(), self::site($req))->toArray());
        self::route('/ready', $get, static fn () => self::ops()->readiness(self::operator())->toArray());
        self::route('/operations/deployment', $get, static fn () => self::ops()->deploymentStatus(self::operator()));
        self::routeWrite('/operations/restart', static fn () => self::ops()->requestRestart(self::operator()));
        self::routeWrite('/operations/drain', static function ($req) {
            return self::bool($req, 'cancel')
                ? self::ops()->cancelDrain(self::operator())
                : self::ops()->requestDrain(self::operator());
        });
        self::route('/operations/drain', $get, static fn () => self::ops()->drainProgress());
        self::route('/reconcile', $write, static function (): array {
            $n = self::ops()->reconcile(self::operator());

            return ['reconciled' => $n];
        });
        self::route('/interop/status', $get, static function (): array {
            $op = self::operator();
            $origin = new \Fuzeo\Queue\Jobs\Origin('fuzeowp/queue', \Fuzeo\Queue\Runtime\PackageInfo::VERSION);

            return Coordinator::get()->interop()->status($origin, $op->currentSiteId, 1);
        });
        self::route('/interop/action-scheduler', $get, static function ($req): array {
            $op = self::operator();
            $limit = self::int($req, 'limit', 50);
            $offset = self::int($req, 'offset', 0);

            return [
                'summary' => Coordinator::get()->interop()->actionSchedulerSummary(),
                'items' => Coordinator::get()->interop()->actionSchedulerCandidates($op->currentSiteId, 1, $limit, $offset),
            ];
        });
        self::route('/interop/cron', $get, static function (): array {
            $op = self::operator();

            return [
                'summary' => Coordinator::get()->interop()->cronInspector($op->currentSiteId, 1)->summary(),
                'items' => Coordinator::get()->interop()->cronCandidates($op->currentSiteId, 1),
            ];
        });
        self::route('/interop/migrations', $get, static function ($req): array {
            $op = self::operator();

            return ['items' => Coordinator::get()->interop()->history($op->currentSiteId, self::int($req, 'limit', 50), self::int($req, 'offset', 0))];
        });
        self::routeWrite('/interop/migrations/plan', static function ($req): array {
            $op = self::operator();
            $op->assertManage($op->currentSiteId);
            $hook = self::string($req, 'hook');
            $source = self::string($req, 'source');
            $context = \Fuzeo\Queue\Jobs\ExecutionContext::site(1, $op->currentSiteId);
            $plan = $source === 'action-scheduler'
                ? Coordinator::get()->interop()->planActionScheduler($hook, $context, self::string($req, 'action_id') !== '' ? self::string($req, 'action_id') : null, self::bool($req, 'pending'))
                : Coordinator::get()->interop()->planCron($hook, $context);

            return $plan->toArray();
        });
        self::routeWrite('/interop/migrations/execute', static function ($req): array {
            $op = self::operator();
            $op->assertManage($op->currentSiteId);
            $hook = self::string($req, 'hook');
            $source = self::string($req, 'source');
            $context = \Fuzeo\Queue\Jobs\ExecutionContext::site(1, $op->currentSiteId);
            $interop = Coordinator::get()->interop();
            $plan = $source === 'action-scheduler'
                ? $interop->planActionScheduler($hook, $context, self::string($req, 'action_id') !== '' ? self::string($req, 'action_id') : null, self::bool($req, 'pending'))
                : $interop->planCron($hook, $context);

            return $interop->migrate($plan, true)->toArray();
        });
        self::routeWrite('/interop/migrations/rollback', static function ($req): array {
            $op = self::operator();
            $op->assertManage($op->currentSiteId);
            $id = self::string($req, 'migration_id');

            return Coordinator::get()->interop()->rollback($id)->toArray();
        });
        self::routeWrite('/interop/migrations/reconcile', static function ($req): array {
            $op = self::operator();
            $op->assertManage($op->currentSiteId);

            return Coordinator::get()->interop()->reconcile(self::string($req, 'migration_id'))->toArray();
        });
    }

    /**
     * @param callable(object): mixed $callback
     */
    private static function routeWrite(string $path, callable $callback): void
    {
        if (!function_exists('register_rest_route')) {
            return;
        }
        register_rest_route(self::NAMESPACE, $path, [
            'methods' => ['POST'],
            'permission_callback' => static function (): bool {
                $access = new QueueAccess();
                if ($access->isNetworkInstall()) {
                    return $access->canManageNetwork();
                }

                return $access->canManageSite(function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1);
            },
            'callback' => static function ($request) use ($callback) {
                try {
                    $data = $callback($request);

                    return rest_ensure_response($data);
                } catch (AccessDenied $exception) {
                    return new \WP_Error('fuzeo_queue_forbidden', $exception->getMessage(), ['status' => 403]);
                } catch (\Fuzeo\Queue\Exceptions\InteropException $exception) {
                    return new \WP_Error('fuzeo_queue_error', $exception->getMessage(), ['status' => 400]);
                } catch (DriverException $exception) {
                    return new \WP_Error('fuzeo_queue_error', $exception->getMessage(), ['status' => 400]);
                }
            },
        ]);
    }

    /**
     * @param list<string> $methods
     * @param callable(object): mixed $callback
     */
    private static function route(string $path, array $methods, callable $callback): void
    {
        register_rest_route(self::NAMESPACE, $path, [
            'methods' => $methods,
            'permission_callback' => static function (): bool {
                $access = new QueueAccess();

                return $access->canViewSite(function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1)
                    || $access->canViewNetwork();
            },
            'callback' => static function ($request) use ($callback) {
                try {
                    $data = $callback($request);

                    return rest_ensure_response($data);
                } catch (AccessDenied $exception) {
                    return new \WP_Error('fuzeo_queue_forbidden', $exception->getMessage(), ['status' => 403]);
                } catch (\Fuzeo\Queue\Exceptions\InteropException $exception) {
                    return new \WP_Error('fuzeo_queue_error', $exception->getMessage(), ['status' => 400]);
                } catch (UniqueConflictException $exception) {
                    return new \WP_Error('fuzeo_queue_conflict', $exception->getMessage(), ['status' => 409]);
                } catch (DriverException $exception) {
                    return new \WP_Error('fuzeo_queue_error', $exception->getMessage(), ['status' => 400]);
                }
            },
        ]);
    }

    private static function ops(): Operations
    {
        return Coordinator::get()->operations();
    }

    private static function operator(): Operator
    {
        $network = function_exists('is_network_admin') && is_network_admin();

        return new Operator(
            new QueueAccess(),
            function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1,
            $network,
        );
    }

    private static function jobQuery(object $req): JobQuery
    {
        $state = self::string($req, 'state');
        $jobState = $state !== '' ? JobState::tryFrom($state) : null;
        if ($state !== '' && $jobState === null) {
            throw new DriverException('Invalid state filter.');
        }

        return new JobQuery(
            state: $jobState,
            queue: self::string($req, 'queue') !== '' ? self::string($req, 'queue') : null,
            jobType: self::string($req, 'job_type') !== '' ? self::string($req, 'job_type') : null,
            origin: self::string($req, 'origin') !== '' ? self::string($req, 'origin') : null,
            siteId: self::site($req),
            jobId: self::string($req, 'job_id') !== '' ? self::string($req, 'job_id') : null,
            tag: self::string($req, 'tag') !== '' ? self::string($req, 'tag') : null,
            limit: self::int($req, 'limit', 25),
            offset: self::int($req, 'offset', 0),
        );
    }

    private static function id(object $req): string
    {
        $id = is_callable([$req, 'get_param']) ? (string) $req->get_param('id') : '';
        if ($id === '' || strlen($id) > 64) {
            throw new DriverException('Invalid id.');
        }

        return $id;
    }

    /**
     * @return list<string>
     */
    private static function ids(object $req): array
    {
        $raw = is_callable([$req, 'get_param']) ? $req->get_param('ids') : [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id) {
            if (is_string($id) && $id !== '' && strlen($id) <= 64) {
                $out[] = $id;
            }
        }

        return array_slice($out, 0, Operations::BULK_MAX);
    }

    private static function site(object $req): ?int
    {
        $value = is_callable([$req, 'get_param']) ? $req->get_param('site_id') : null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new DriverException('Invalid site_id.');
        }

        return (int) $value;
    }

    private static function int(object $req, string $key, int $default): int
    {
        $value = is_callable([$req, 'get_param']) ? $req->get_param($key) : null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_numeric($value)) {
            throw new DriverException('Invalid ' . $key . '.');
        }

        return (int) $value;
    }

    private static function string(object $req, string $key): string
    {
        $value = is_callable([$req, 'get_param']) ? $req->get_param($key) : '';

        return is_string($value) ? $value : '';
    }

    private static function bool(object $req, string $key): bool
    {
        $value = is_callable([$req, 'get_param']) ? $req->get_param($key) : false;

        return $value === true || $value === 'true' || $value === '1' || $value === 1;
    }

    private static function period(object $req): string
    {
        $period = self::string($req, 'period');
        if (!in_array($period, ['1h', '6h', '24h', '7d', '30d', ''], true)) {
            throw new DriverException('Invalid period.');
        }

        return $period === '' ? '1h' : $period;
    }
}
