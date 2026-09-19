# Runtime compatibility

Several plugins may bundle Fuzeo Queue. Only one runtime may own bootstrap, hooks, CLI, admin, and schema migrations.

## Compatible copies

Candidates share `PackageInfo::COMPATIBILITY_SERIES`. Plugins register jobs and consumers on that single runtime.

PHP can load only one definition of `Fuzeo\Queue\...` classes. The copy whose Composer autoloader first defines those classes is the loaded implementation. Candidate metadata still records every bundled copy. If a newer compatible copy lost the autoload race, the runtime records a `newer_copy_unused` diagnostic. Align versions or use a shared Composer install at the project root.

PHP-Scoper is not used: scoping would create parallel class trees and make a shared runtime harder, which is the opposite of the goal.

## Incompatible copies

Different compatibility series (effectively incompatible majors) throw `IncompatibleRuntimeException` with an actionable message. Dispatch is refused. The runtime does not mix envelope formats or fight over schema.

## Autoload vs highest version

Highest compatible version is preferred **among candidate metadata**. It cannot replace classes already loaded by PHP. That tradeoff is documented in [ADR-001](adr/001-runtime-ownership.md).
