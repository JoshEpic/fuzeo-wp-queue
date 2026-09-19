<?php

declare(strict_types=1);

namespace Fuzeo\Queue\WordPress\Admin;

use Fuzeo\Queue\Inspection\JobQuery;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Operations\Operator;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\WordPress\QueueAccess;
use Fuzeo\Queue\WordPress\Rest\RestRegistrar;

final class AdminPage
{
    public static function render(): void
    {
        if (!Coordinator::isBooted()) {
            echo '<div class="wrap"><h1>Fuzeo Queue</h1><p>Queue runtime is not booted.</p></div>';

            return;
        }
        $operator = self::operator();
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key($_GET['page']) : AdminRegistrar::MENU_SLUG;
        $view = isset($_GET['fq_view']) && is_string($_GET['fq_view']) ? sanitize_key($_GET['fq_view']) : 'overview';
        echo '<a class="screen-reader-text skip-link" href="#fuzeo-queue-main">Skip to Fuzeo Queue content</a>';
        echo '<div id="fuzeo-queue-main" class="wrap fuzeo-queue-admin" data-rest="' . esc_attr(self::restRoot()) . '" data-nonce="' . esc_attr(wp_create_nonce('wp_rest')) . '">';
        echo '<h1>Fuzeo Queue</h1>';
        self::nav($page, $view);
        match ($view) {
            'queues' => self::queues($operator),
            'jobs' => self::jobs($operator),
            'failed' => self::failed($operator),
            'workers' => self::workers($operator),
            'schedules' => self::schedules($operator),
            'orchestration' => self::orchestration($operator),
            'metrics' => self::metrics($operator),
            'diagnostics' => self::diagnostics($operator),
            default => self::overview($operator),
        };
        echo '</div>';
    }

    private static function nav(string $page, string $view): void
    {
        $items = [
            'overview' => 'Overview',
            'queues' => 'Queues',
            'jobs' => 'Jobs',
            'failed' => 'Failed',
            'workers' => 'Workers',
            'schedules' => 'Schedules',
            'orchestration' => 'Chains & Batches',
            'metrics' => 'Metrics',
            'diagnostics' => 'Diagnostics',
        ];
        echo '<nav class="fuzeo-queue-nav" aria-label="Fuzeo Queue">';
        echo '<ul class="subsubsub">';
        $last = array_key_last($items);
        foreach ($items as $key => $label) {
            $url = add_query_arg(['page' => $page, 'fq_view' => $key]);
            $class = $view === $key ? ' class="current"' : '';
            $current = $view === $key ? ' aria-current="page"' : '';
            echo '<li><a' . $class . $current . ' href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
            echo $key === $last ? '' : ' |';
            echo '</li>';
        }
        echo '</ul></nav>';
        echo '<p class="description">Dashboard updates by polling (not live). Times are UTC unless noted.</p>';
    }

    private static function overview(Operator $operator): void
    {
        $data = Coordinator::get()->operations()->overview($operator);
        if (!empty($data['empty'])) {
            echo '<div class="notice notice-info"><p>Queue is installed. No jobs have been dispatched yet.</p></div>';
        }
        if (!empty($data['no_workers'])) {
            echo '<div class="notice notice-warning"><p>Jobs are waiting, but no active Fuzeo Queue worker is detected. Run <code>wp fuzeo-queue work</code>.</p></div>';
        }
        $health = is_array($data['health'] ?? null) ? $data['health'] : [];
        echo '<section aria-labelledby="fq-health"><h2 id="fq-health">Queue health</h2>';
        echo '<p><span class="fuzeo-status fuzeo-status--' . esc_attr((string) ($health['status'] ?? 'unknown')) . '">' . esc_html((string) ($health['status'] ?? 'unknown')) . '</span></p>';
        if (!empty($health['reasons']) && is_array($health['reasons'])) {
            echo '<ul>';
            foreach ($health['reasons'] as $reason) {
                echo '<li>' . esc_html((string) $reason) . '</li>';
            }
            echo '</ul>';
        }
        echo '</section>';
        echo '<section aria-labelledby="fq-cards"><h2 id="fq-cards" class="screen-reader-text">Counts</h2><ul class="fuzeo-cards">';
        foreach (['pending' => 'Pending', 'reserved' => 'Processing', 'retrying' => 'Retrying', 'dead' => 'Failed/dead', 'workers_alive' => 'Workers', 'processing_lag_seconds' => 'Processing lag (s)'] as $key => $label) {
            echo '<li><strong>' . esc_html($label) . '</strong> <span>' . esc_html((string) ($data[$key] ?? '0')) . '</span></li>';
        }
        echo '</ul></section>';
        echo '<p>Dispatch/min: ' . esc_html((string) round((float) ($data['dispatch_per_minute'] ?? 0), 2));
        echo ' · Completion/min: ' . esc_html((string) round((float) ($data['completion_per_minute'] ?? 0), 2)) . '</p>';
        if (!empty($data['falling_behind'])) {
            echo '<p>Dispatch volume is greater than processing volume.</p>';
        }
        $deploy = Coordinator::get()->operations()->deploymentStatus($operator);
        $state = is_array($deploy['state'] ?? null) ? $deploy['state'] : [];
        $gen = is_array($deploy['generation'] ?? null) ? $deploy['generation'] : [];
        echo '<section aria-labelledby="fq-deploy"><h2 id="fq-deploy">Deployment</h2>';
        echo '<p>Loaded package ' . esc_html((string) ($gen['loaded'] ?? '')) . ' (series ' . esc_html((string) ($gen['compatibility_series'] ?? '')) . ').';
        echo ' Deployment generation <code>' . esc_html(substr((string) ($gen['deployment_generation'] ?? ''), 0, 12)) . '</code>.</p>';
        if (!empty($state['draining'])) {
            echo '<p>Fleet is draining. Workers are not reserving new jobs.</p>';
        }
        if (!empty($state['maintenance'])) {
            echo '<p>Deployment maintenance is active (' . esc_html((string) ($state['maintenance_reason'] ?? '')) . ').</p>';
        }
        if (!empty($deploy['no_process_manager']) && !empty($state['restart_generation'])) {
            echo '<div class="notice notice-warning"><p>A recycle was requested, but no replacement worker appeared. Configure a process manager (Supervisor, systemd, or a container restart policy).</p></div>';
        }
        echo '<p>Fuzeo Queue will request active workers and schedulers to exit after current work. Your process manager is responsible for starting replacements.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="fuzeo_queue_restart" />';
        wp_nonce_field('fuzeo_queue_restart');
        echo '<button class="button" type="submit">Request worker recycle</button></form> ';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        echo '<input type="hidden" name="action" value="fuzeo_queue_drain" />';
        wp_nonce_field('fuzeo_queue_drain');
        echo '<button class="button" type="submit">Request drain</button></form>';
        echo '</section>';
    }

    private static function queues(Operator $operator): void
    {
        $rows = Coordinator::get()->operations()->queues($operator);
        echo '<table class="widefat striped"><caption>Queues</caption><thead><tr>';
        echo '<th scope="col">Name</th><th scope="col">Pending</th><th scope="col">Processing</th><th scope="col">Retrying</th><th scope="col">Dead</th><th scope="col">Oldest wait</th><th scope="col">Health</th>';
        echo '</tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td colspan="7">No queues have jobs yet.</td></tr>';
        }
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html((string) $row['name']) . '</td>';
            echo '<td>' . esc_html((string) $row['pending']) . '</td>';
            echo '<td>' . esc_html((string) $row['reserved']) . '</td>';
            echo '<td>' . esc_html((string) $row['retrying']) . '</td>';
            echo '<td>' . esc_html((string) $row['dead']) . '</td>';
            echo '<td>' . esc_html((string) ($row['oldest_waiting_seconds'] ?? '—')) . '</td>';
            echo '<td>' . esc_html((string) $row['health']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function jobs(Operator $operator): void
    {
        $state = isset($_GET['state']) && is_string($_GET['state']) ? JobState::tryFrom(sanitize_key($_GET['state'])) : null;
        $page = Coordinator::get()->operations()->jobs($operator, new JobQuery(state: $state, limit: 25));
        if ($page->truncated) {
            echo '<p role="status">Job totals for Redis are bounded. This list scanned at most 2,000 keys and may omit matching jobs. Filter by job ID when possible.</p>';
        }
        echo '<table class="widefat striped"><caption>Jobs</caption><thead><tr>';
        echo '<th scope="col">ID</th><th scope="col">Type</th><th scope="col">State</th><th scope="col">Queue</th><th scope="col">Origin</th><th scope="col">Site</th>';
        echo '</tr></thead><tbody>';
        if ($page->items === []) {
            echo '<tr><td colspan="6">No jobs match this filter.</td></tr>';
        }
        foreach ($page->items as $envelope) {
            echo '<tr><td><code>' . esc_html($envelope->jobId) . '</code></td>';
            echo '<td>' . esc_html($envelope->jobType) . '</td>';
            echo '<td>' . esc_html($envelope->state->value) . '</td>';
            echo '<td>' . esc_html($envelope->queue) . '</td>';
            echo '<td>' . esc_html($envelope->origin->package) . '</td>';
            echo '<td>' . esc_html((string) $envelope->context->siteId) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function failed(Operator $operator): void
    {
        $page = Coordinator::get()->operations()->jobs($operator, new JobQuery(state: JobState::Dead, limit: 25));
        echo '<p>Manual retry reuses Queue retry semantics. This job may have already produced side effects before failing. Fuzeo Queue provides at-least-once delivery.</p>';
        echo '<table class="widefat striped"><caption>Failed and dead jobs</caption><thead><tr>';
        echo '<th scope="col">ID</th><th scope="col">Type</th><th scope="col">Origin</th><th scope="col">Actions</th>';
        echo '</tr></thead><tbody>';
        foreach ($page->items as $envelope) {
            echo '<tr><td><code>' . esc_html($envelope->jobId) . '</code></td>';
            echo '<td>' . esc_html($envelope->jobType) . '</td>';
            echo '<td>' . esc_html($envelope->origin->package) . '</td>';
            echo '<td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="fuzeo_queue_retry" />';
            echo '<input type="hidden" name="job_id" value="' . esc_attr($envelope->jobId) . '" />';
            wp_nonce_field('fuzeo_queue_retry');
            echo '<button class="button" type="submit">Retry</button></form></td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function workers(Operator $operator): void
    {
        $rows = Coordinator::get()->operations()->workers($operator);
        echo '<table class="widefat striped"><caption>Workers</caption><thead><tr>';
        echo '<th scope="col">ID</th><th scope="col">Host</th><th scope="col">PID</th><th scope="col">Status</th><th scope="col">Version</th><th scope="col">Generation</th><th scope="col">Heartbeat age</th><th scope="col">Memory</th><th scope="col">Processed</th>';
        echo '</tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td colspan="9">No workers have registered. Start <code>wp fuzeo-queue work</code>.</td></tr>';
        }
        foreach ($rows as $row) {
            echo '<tr><td><code>' . esc_html((string) $row['worker_id']) . '</code></td>';
            echo '<td>' . esc_html((string) $row['hostname']) . '</td>';
            echo '<td>' . esc_html((string) $row['pid']) . '</td>';
            echo '<td>' . esc_html((string) $row['health']) . '</td>';
            echo '<td>' . esc_html((string) ($row['runtime_version'] ?? '')) . '</td>';
            echo '<td><code>' . esc_html(substr((string) ($row['deployment_generation'] ?? $row['runtime_generation'] ?? ''), 0, 12)) . '</code></td>';
            echo '<td>' . esc_html((string) ($row['heartbeat_age_seconds'] ?? '—')) . '</td>';
            echo '<td>' . esc_html((string) $row['memory_bytes']) . '</td>';
            echo '<td>' . esc_html((string) $row['processed_count']) . '</td></tr>';
        }
        echo '</tbody></table>';
        $deploy = Coordinator::get()->operations()->deploymentStatus($operator);
        echo '<h2>Fleet by generation</h2><ul>';
        $fleet = is_array($deploy['fleet'] ?? null) ? $deploy['fleet'] : [];
        if ($fleet === []) {
            echo '<li>No live processes grouped yet.</li>';
        }
        foreach ($fleet as $row) {
            if (!is_array($row)) {
                continue;
            }
            echo '<li>' . esc_html((string) ($row['runtime_version'] ?? '')) . ' / '
                . esc_html(substr((string) ($row['generation'] ?? ''), 0, 12)) . ': '
                . esc_html((string) ($row['workers'] ?? 0)) . ' worker(s)</li>';
        }
        echo '</ul>';
        $stale = is_array($deploy['stale_processes'] ?? null) ? $deploy['stale_processes'] : [];
        if ($stale !== []) {
            echo '<p>Stale (old generation) processes:</p><ul>';
            foreach ($stale as $row) {
                if (!is_array($row)) {
                    continue;
                }
                echo '<li><code>' . esc_html((string) ($row['worker_id'] ?? '')) . '</code> '
                    . esc_html((string) ($row['runtime_version'] ?? '')) . '</li>';
            }
            echo '</ul>';
        }
        $progress = Coordinator::get()->operations()->drainProgress();
        if (!empty($progress['draining'])) {
            echo '<p>Draining. Elapsed ' . esc_html((string) ($progress['elapsed_seconds'] ?? 0))
                . 's. Reserved jobs: ' . esc_html((string) ($progress['reserved'] ?? 0)) . '. Backlog: '
                . esc_html((string) ($progress['pending'] ?? 0)) . '.</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="fuzeo_queue_drain" />';
            echo '<input type="hidden" name="cancel" value="1" />';
            wp_nonce_field('fuzeo_queue_drain');
            echo '<button class="button" type="submit">Cancel drain</button></form>';
        }
        echo '<p>Fuzeo Queue requests recycle; it does not restart OS processes.</p>';
    }

    private static function schedules(Operator $operator): void
    {
        $rows = Coordinator::get()->operations()->schedules($operator);
        echo '<table class="widefat striped"><caption>Schedules</caption><thead><tr>';
        echo '<th scope="col">ID</th><th scope="col">Name</th><th scope="col">Enabled</th><th scope="col">Next run</th><th scope="col">Blocked</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td><code>' . esc_html((string) $row['id']) . '</code></td>';
            echo '<td>' . esc_html((string) $row['name']) . '</td>';
            echo '<td>' . esc_html(!empty($row['enabled']) ? 'yes' : 'no') . '</td>';
            echo '<td>' . esc_html((string) $row['next_run']) . '</td>';
            echo '<td>' . esc_html((string) ($row['blocked'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function orchestration(Operator $operator): void
    {
        $chains = Coordinator::get()->operations()->chains($operator);
        $batches = Coordinator::get()->operations()->batches($operator);
        echo '<h2>Chains</h2><table class="widefat striped"><caption>Chains</caption><thead><tr><th scope="col">ID</th><th scope="col">State</th><th scope="col">Step</th></tr></thead><tbody>';
        foreach ($chains as $row) {
            echo '<tr><td><code>' . esc_html((string) $row['id']) . '</code></td><td>' . esc_html((string) $row['state']) . '</td><td>' . esc_html((string) $row['current_step']) . '/' . esc_html((string) $row['total_steps']) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Batches</h2><table class="widefat striped"><caption>Batches</caption><thead><tr><th scope="col">ID</th><th scope="col">State</th><th scope="col">Progress</th></tr></thead><tbody>';
        foreach ($batches as $row) {
            $bar = $row['total'] > 0 ? (int) round(100 * ((int) $row['progress'] / (int) $row['total'])) : 0;
            echo '<tr><td><code>' . esc_html((string) $row['id']) . '</code></td><td>' . esc_html((string) $row['state']);
            if (!empty($row['reconciling'])) {
                echo ' (reconciling)';
            }
            echo '</td><td><progress max="100" value="' . esc_attr((string) $bar) . '">' . esc_html((string) $bar) . '%</progress> ' . esc_html((string) $row['progress']) . '/' . esc_html((string) $row['total']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function metrics(Operator $operator): void
    {
        $period = isset($_GET['period']) && is_string($_GET['period']) ? sanitize_text_field($_GET['period']) : '1h';
        $data = Coordinator::get()->operations()->metrics($operator, $period);
        echo '<p>Range: ' . esc_html($period) . ' (resolution ' . esc_html((string) $data['resolution']) . ', timezone UTC).</p>';
        if (!empty($data['degraded'])) {
            echo '<div class="notice notice-warning"><p>Metrics storage is degraded or unavailable. Queue processing is unaffected.</p></div>';
        }
        echo '<p>Runtime p50/p95/p99: ';
        $runtime = is_array($data['runtime'] ?? null) ? $data['runtime'] : [];
        echo esc_html((string) ($runtime['p50'] ?? 0)) . ' / ' . esc_html((string) ($runtime['p95'] ?? 0)) . ' / ' . esc_html((string) ($runtime['p99'] ?? 0)) . ' ms (approximate histogram).</p>';
        echo '<div id="fuzeo-queue-charts" role="img" aria-label="Throughput charts"></div>';
    }

    private static function diagnostics(Operator $operator): void
    {
        $data = Coordinator::get()->operations()->diagnostics($operator);
        echo '<table class="widefat striped"><caption>Diagnostics</caption><tbody>';
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            echo '<tr><th scope="row">' . esc_html((string) $key) . '</th><td>' . esc_html(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public static function handleRestart(): void
    {
        check_admin_referer('fuzeo_queue_restart');
        Coordinator::get()->operations()->requestRestart(self::operator());
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=' . AdminRegistrar::MENU_SLUG));
        exit;
    }

    public static function handleDrain(): void
    {
        check_admin_referer('fuzeo_queue_drain');
        $ops = Coordinator::get()->operations();
        if (isset($_POST['cancel'])) {
            $ops->cancelDrain(self::operator());
        } else {
            $ops->requestDrain(self::operator());
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=' . AdminRegistrar::MENU_SLUG));
        exit;
    }

    public static function handleRetry(): void
    {
        check_admin_referer('fuzeo_queue_retry');
        $id = isset($_POST['job_id']) && is_string($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : '';
        if ($id !== '') {
            Coordinator::get()->operations()->retry(self::operator(), $id);
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=' . AdminRegistrar::MENU_SLUG));
        exit;
    }

    public static function enqueueAssets(string $hook): void
    {
        if (!str_contains($hook, AdminRegistrar::MENU_SLUG)) {
            return;
        }
        $base = dirname(__DIR__, 3) . '/assets';
        $url = plugins_url('assets', dirname(__DIR__, 2));
        if (!is_dir($base)) {
            $url = '';
        }
        $css = dirname(__DIR__, 3) . '/assets/admin.css';
        $js = dirname(__DIR__, 3) . '/assets/admin.js';
        if (is_file($css)) {
            wp_enqueue_style('fuzeo-queue-admin', self::assetUrl('admin.css'), [], \Fuzeo\Queue\Runtime\PackageInfo::VERSION);
        }
        if (is_file($js)) {
            wp_enqueue_script('fuzeo-queue-admin', self::assetUrl('admin.js'), [], \Fuzeo\Queue\Runtime\PackageInfo::VERSION, true);
        }
        unset($url);
    }

    private static function assetUrl(string $file): string
    {
        $path = dirname(__DIR__, 3) . '/assets/' . $file;
        if (function_exists('plugins_url') && defined('WP_PLUGIN_DIR') && str_starts_with($path, WP_PLUGIN_DIR)) {
            return plugins_url('assets/' . $file, dirname(__DIR__, 2) . '/dummy.php');
        }

        return content_url('fuzeo-queue/' . $file);
    }

    private static function restRoot(): string
    {
        return function_exists('rest_url') ? rest_url(RestRegistrar::NAMESPACE . '/') : '';
    }

    private static function operator(): Operator
    {
        return new Operator(
            new QueueAccess(),
            get_current_user_id(),
            get_current_blog_id(),
            function_exists('is_network_admin') && is_network_admin(),
        );
    }
}
