# CLI and admin

## WP-CLI

Command namespace: **`wp fuzeo-queue`**

Reasons:

- `wp queue` collides with other queue plugins
- `wp fuzeo` is reserved for product CLIs (Bridge, Relay, Sync)
- `wp fuzeo-queue` is owned by this package

Only the winning runtime registers the command. Phase 1 does not implement worker commands.

## Admin UI

One menu slug: `fuzeo-queue`.

- Single site: capability `manage_options`
- Network admin: capability `manage_network`
- Origin filtering happens inside that UI, not as extra menus per plugin

Phase 1 registers ownership hooks only. No dashboard is rendered.
