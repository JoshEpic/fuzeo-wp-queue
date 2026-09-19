# ADR-080 WordPress admin architecture

## Context

Composer consumers must not run npm. Multiple plugins bundle Queue.

## Decision

One menu slug `fuzeo-queue` owned by the winning runtime. WordPress PHP screens + prebuilt `assets/admin.css` / `assets/admin.js` (polling, no WebSockets, pause when hidden). Server render uses `Operations`. Not a Horizon clone. Network admin vs site admin menus are separate.

## Alternatives

React/`@wordpress/scripts` SPA. Per-origin menus.

## Performance

First paint is SSR. Polling 10s.

## Security

`manage_options` / `manage_network_options` fallbacks. Assets enqueue only on Queue screens. Retry uses `admin-post.php` + nonce.

## Failure behavior

Empty install copy is informational, not alarming.

## Compatibility

UI version equals loaded `PackageInfo::VERSION`.

## Future

Optional React rebuild can consume the same REST v1.
