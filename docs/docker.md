# Docker

Queue is not a Docker requirement. This is a production-shaped layout.

## Containers

| Container | Role |
| --- | --- |
| web | WordPress HTTP |
| worker | `wp fuzeo-queue work` (no HTTP server) |
| scheduler | `wp fuzeo-queue schedule-work` |
| mysql | Durable jobs (required for mysql driver) |
| redis | Optional redis driver |

All app containers **must run the same release** (shared image or shared code volume). Mismatched images are an incompatible fleet unless job schemas are dual-read.

Workers do not need nginx/php-fpm. Use the same WordPress code, configuration, and backend as web.

## Shutdown

```
SIGTERM → stop reserving → finish current job → exit 0
```

Set `stop_grace_period` (Compose) / `terminationGracePeriodSeconds` (Kubernetes) above worst-case job time you will wait. Queue does not ship a Kubernetes operator.

## Readiness

If WP-CLI is in the image:

```
wp fuzeo-queue ready --format=json
```

Exit 0 means this deployment may accept work. Do not use worker readiness as the **web** container health check.

See [examples/docker-compose.yml](examples/docker-compose.yml).
