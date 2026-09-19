# ADR-066 WordPress global and current-user isolation

## Context

`wp_set_current_user`, `setup_postdata`, and locale APIs leak across jobs.

## Decision

Capture baseline user id and locale at worker boot. After each job restore user, `wp_reset_query` / `wp_reset_postdata`, clear `$post`, restore locale via `restore_current_locale` when available. Leave `$wp`, `$wp_rewrite`, `$wp_roles`, `$wpdb` connections, and `$wp_object_cache` in place except runtime cache flush.

## Alternatives

`wp_set_current_user(0)` always (ignores baseline). Recreate `$wp_query` from scratch.

## Limitations

Custom user objects held in plugin statics are not cleared.

## Failure behavior

Next job starts as the worker identity (typically user 0).

## Compatibility

Handlers that need a user must set one every job.

## Phase 8/9

None.
