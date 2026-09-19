# ADR-110 Migration descriptor contract

## Decision

Developers register versioned descriptors (`Interop::cron` / `Interop::actionScheduler`) with an explicit mapper `array → Job`. Identity is origin + source system + hook + optional group + version. Two origins claiming the same source identity throw `DescriptorConflictException`. Closures are not persisted.
