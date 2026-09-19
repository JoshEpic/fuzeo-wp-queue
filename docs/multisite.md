# Multisite

Every envelope stores:

- `network_id`
- `site_id`
- `scope` (`site` or `network`)

Site-scoped jobs require `site_id >= 1`. Network-scoped jobs use `site_id = 0`.

Dispatch can pin context instead of using the current blog:

```php
Queue::on('imports')->onSite($networkId, 42)->dispatch($job);
Queue::on('network-maintenance')->networkScoped($networkId)->dispatch($job);
```

Workers in a later phase will:

```php
switch_to_blog($siteId);
try {
    // handle job
} finally {
    restore_current_blog();
}
```

Persistence is designed for **shared network-level tables**, not a full schema per blog. Uniqueness and future rate limits can include network, site, origin package, queue, and job type.
