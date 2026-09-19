# Roadmap

Public ideas, not dates or commitments.

- **Interoperability:** shipped in 1.1 (explicit descriptors; no global replacement).
- **Compatibility execution:** shipped in 1.2 (bounded WP / external cron; persistent workers remain recommended).
- Redis Cluster remains a possible future; 1.0 does not support it.
- Pause/resume, additional backends, Prometheus/OTel exporters, and workflow DAGs are non-goals until the 1.0 contract is proven in the wild.

Fuzeo Queue stays independently useful. It will not become a Fuzeo Bridge/Relay/Sync private dependency.
