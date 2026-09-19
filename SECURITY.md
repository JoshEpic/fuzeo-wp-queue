# Security Policy

## Supported versions

| Version | Supported |
| --- | --- |
| 1.x | Yes |
| 0.x | No, except as a path to 1.0 |

## Reporting a vulnerability

Do **not** open a public GitHub issue for an active vulnerability.

Use GitHub's private vulnerability reporting on this repository:

https://github.com/JoshEpic/fuzeo-wp-queue/security/advisories/new

If that UI is unavailable, open a **blank** issue titled "Security contact request" with no technical details and wait for a maintainer to respond privately.

Please include:

- Fuzeo Queue version
- PHP, WordPress, driver, MySQL/Redis versions
- Impact and reproduction
- Logs with secrets removed

## Expectations

There is no dedicated security mailbox yet. Reports are reviewed as maintainer time allows. We will not ship commercial licensing checks or "phone home" behavior as a response to incidents.

Fuzeo Queue is at-least-once infrastructure. Duplicate execution after a crash is expected, not a vulnerability, unless an ownership token, authz check, or isolation boundary is bypassed.
