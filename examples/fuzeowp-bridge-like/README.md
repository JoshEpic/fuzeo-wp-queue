# Bridge-like runtime adapter example

Preferred runtime: Fuzeo Queue. Fallback: Action Scheduler.

This is not Fuzeo Bridge. It shows the public 1.1 pattern FuzeoWP products will use.

- New work uses whichever runtime the resolver selects at **dispatch**.
- Existing Action Scheduler actions are not migrated automatically.
- Adapter-owned fallback actions use group `fuzeo-interop-example-bridge-like`.
- If Fuzeo Queue is installed later, new dispatches go to Queue; legacy AS work drains in place.
