# CLI exit codes

Namespace: `wp fuzeo-queue`.

| Code | Commands | Meaning |
| --- | --- | --- |
| 0 | `ready`, `work`, `restart` wait success, `migrate` | Ready / clean recycle / migrated |
| 1 | `ready`, `drain --wait` timeout, generic errors | Not ready (drain/maintenance) or wait timed out |
| 2 | `ready`, `migrate --check` | Schema mismatch / migration required |
| 3 | `ready` | Backend unavailable |
| 4 | `ready` / worker boot | Runtime incompatibility |

Workers exiting after a requested recycle use 0 so process managers restart them. Schema/incompatibility exits are non-zero so supervisors can alarm instead of tight-looping on a broken deploy.
