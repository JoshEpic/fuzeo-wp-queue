# FAQ

**Is this WP-Cron?**  
No. WP-Cron is a page-view triggered pseudo-cron. Fuzeo Queue is durable jobs plus persistent workers.

**Is this Action Scheduler?**  
No. Queue does not wrap or intercept Action Scheduler. 1.1 can detect it, fall back at **dispatch** for consuming plugins that use `Interop::runtime()`, and migrate only declared-compatible hooks.

**Why do I still see Action Scheduler actions?**  
By default they drain in place. Queue does not steal third-party AS work.

**Why isn't this cron event migratable?**  
No migration descriptor is registered. Unknown ≠ broken.

**Why isn't Queue automatically replacing WP-Cron?**  
Global replacement would break plugins that assume web-request cron semantics. Migration is explicit.

**What happens if Queue goes down?**  
Jobs already in Queue stay in Queue. New adapter dispatches may use AS fallback if policy is `prefer_queue`. Already-queued work is not copied to AS.

**Will Queue duplicate my AS jobs?**  
Not if you follow the default: new work → Queue, existing AS drains. Do not migrate in-progress actions. Preview before `--execute`.

**Do I need Redis?**  
No. MySQL/MariaDB with SKIP LOCKED is a full production driver.

**Can I use MySQL?**  
Yes. MySQL 8.0.1+ or MariaDB 10.6+.

**Does it guarantee exactly once?**  
No. It is at-least-once. Use uniqueness to prevent duplicate *enqueue*, and idempotency (including vendor keys) to protect *effects*.

**Does it work on multisite?**  
Yes, with network vs site scope, site deletion fail-closed, and network-only fleet controls.

**Can I use it with WooCommerce?**  
Yes. Persist order/product IDs. Queue does not depend on WooCommerce. HPOS-safe as long as you reload objects by ID.

**Does it require a separate server?**  
A process manager (Supervisor, systemd, Docker) on a host that can run `wp fuzeo-queue work` is required for the primary architecture. That can be the web server if it allows long-lived CLI.

**Can shared hosting use it?**  
Usually not well. See [shared-hosting.md](shared-hosting.md). Do not pretend a $3 shared host is ideal.

**Is there a commercial license check?**  
No. MIT. Fuzeo product plugins may consume Queue; Queue does not require them.
