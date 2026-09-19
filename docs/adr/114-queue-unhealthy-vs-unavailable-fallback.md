# ADR-114 Queue-unhealthy vs Queue-unavailable fallback

## Decision

Fallback is chosen only at the dispatch boundary. A job accepted by Queue stays owned by Queue if the backend later becomes unhealthy. Package-not-installed is a consumer concern (call AS directly). Package installed + unhealthy driver may use AS fallback under `prefer_queue`.
