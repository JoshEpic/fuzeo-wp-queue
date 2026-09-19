# Long-running WordPress workers

WordPress is request-scoped. A Fuzeo Queue worker is not:

```
process boot → WordPress bootstrap → Fuzeo Queue boot → capture baseline
loop:
  prepare → switch site → execute → settle → reset → health → recycle?
graceful exit
```

Treat request-scoped assumptions as a first-class risk.

## Do

- Load entities by ID inside the handler (`get_post($id)`, `wc_get_order($id)`).
- Pass Queue identifiers in payloads, never live objects.
- Register `fuzeo_queue_runtime_reset` or `fuzeo_queue_job_finished` if your plugin keeps process-local caches.
- Set `Origin` `plugin_file` so per-site activation can fail closed.
- Expect workers to recycle on max jobs, max runtime, memory, and runtime generation changes.

## Do not

- Call `exit`, `die`, or `wp_die()` from a handler. That kills the worker; lease recovery retries the job.
- Store per-job state in statics or singletons without resetting it.
- Assume `template_redirect`, `wp`, or HTTP `shutdown` run like a frontend request.
- Flush the entire object cache after a job (`wp_cache_flush()` is not used by Queue).
- Assume newly activated plugins appear in an already-running process. Workers recycle on generation change.

## Isolation that Queue restores

Current user, locale, blog switch stack, extra output buffers, working directory, request superglobals (`$_GET`/`$_POST`/…), PHP session, and unexpected SQL transactions (rolled back; worker recycles).

## Isolation Queue cannot restore

Shutdown functions, arbitrary `static` locals, third-party singletons, dynamically added hooks, most `ini_set`/`putenv` mutations. Recycle is the isolation mechanism.

## Lifecycle hooks

`fuzeo_queue_worker_started`, `fuzeo_queue_job_preparing`, `fuzeo_queue_job_starting`, `fuzeo_queue_before_job`, `fuzeo_queue_job_completed`, `fuzeo_queue_after_job`, `fuzeo_queue_job_failed`, `fuzeo_queue_job_cancelled`, `fuzeo_queue_job_finished`, `fuzeo_queue_runtime_reset`, `fuzeo_queue_worker_stopping`, `fuzeo_queue_worker_stopped`.

Idempotency still matters. Delivery remains at-least-once.
