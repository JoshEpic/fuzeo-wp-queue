# Multisite

Every envelope stores `network_id`, `site_id`, and `scope` (`site` or `network`).

Site-scoped jobs require `site_id >= 1`. Network-scoped jobs use `site_id = 0` and run at the worker baseline blog (not an implicit switch to blog 1).

```php
Queue::on('imports')->onSite($networkId, 42)->dispatch($job);
Queue::on('network-maintenance')->networkScoped($networkId)->dispatch($job);
```

## Execution order

1. Capture worker baseline blog.
2. For site jobs: refuse deleted/archived/spam sites, then `switch_to_blog(target)`.
3. Resolve origin plugin activity **on the target site**.
4. Execute the handler.
5. Unwind nested `switch_to_blog()` back to baseline.
6. `RuntimeResetter` asserts baseline; recycle if restoration fails.

Jobs identify sites by ID. Domain or path changes do not invalidate queued work.

## Plugin activation

Network-active plugins may handle jobs on eligible sites. A per-site inactive origin (`Origin::$pluginFile`) fails closed even if classes remain in the long-running process.

Chains and batches inherit one execution context at dispatch. They do not switch sites per step.

## Access

Network admins (`fuzeo_queue_manage_network` / `manage_network_options`) may inspect network-wide state. Site admins may only see their site. Phase 8 REST must go through `QueueAccess`, not repositories.

Persistence remains **shared network-level tables**.
