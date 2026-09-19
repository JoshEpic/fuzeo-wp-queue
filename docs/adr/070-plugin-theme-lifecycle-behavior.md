# ADR-070 Plugin and theme lifecycle behavior

## Context

Activation during a live worker does not load new files. Deactivation does not unload classes.

## Decision

Do not `include` newly activated plugins into an old process. Generation change ⇒ recycle. Origin `plugin_file` is checked with `is_plugin_active` / `is_plugin_active_for_network` **after** site switch. Missing file on the origin is treated as available (Composer-only jobs). Theme changes are part of generation.

## Alternatives

Dynamic plugin load. Trust in-memory classes after deactivation.

## Limitations

Plugins that omit `plugin_file` cannot be fail-closed per site.

## Failure behavior

`HandlerUnavailableException` is terminal.

## Compatibility

Origin already had optional `plugin_file`.

## Phase 8/9

Dashboard should show origin plugin and site.
