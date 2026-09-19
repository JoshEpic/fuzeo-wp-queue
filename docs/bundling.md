# Bundling Fuzeo Queue in WordPress plugins

## First autoloader wins

PHP cannot unload a class. If Plugin A loads Fuzeo Queue 1.0.0 and Plugin B bundles 1.0.1, the **already loaded** classes stay. Diagnostics warn when candidate metadata is newer than `PackageInfo::VERSION`.

Make the race boring:

```json
{
  "require": {
    "fuzeowp/queue": "^1.0"
  }
}
```

Prefer a site-level Composer install when you control the whole app. When you must zip `vendor/`:

- **Do not** scope `Fuzeo\Queue\`
- **Do** scope *your* other dependencies if they collide
- Share the Fuzeo Queue namespace intentionally so one runtime, schema, admin, and CLI exist

Incompatible `COMPATIBILITY_SERIES` copies fail closed and refuse to boot a mixed runtime.

## Driver and schema

Only the winning runtime migrates schema and registers WP-CLI/admin/REST. Consumers still register jobs on `fuzeo_queue_ready`.

If the plugin that happened to boot first is removed, the next request can establish runtime from another compatible copy. There is no permanent owner plugin.
