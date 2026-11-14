## [t7] Phase 1 implementation plan and test strategy
- Codebase/domain discoveries: t6's current source baseline at `29aad52` is 540 passing PHPUnit tests and a passing Vite build; HEAD `c1fd3bd` and current worktree changes are metadata/artifacts only. The PHP test config forces SQLite `:memory:`; production database engine remains unknown.
- Non-obvious constraints: t6 leaves the app-adapter versus restricted-SQL boundary unresolved; do not choose silently. Docker CLI is present but its daemon currently fails on the Docker Desktop Linux-engine named pipe. Node 24 is present; Playwright is not installed. These findings affect separate test capability axes.
- Migration naming check: the existing migration directory already uses several 2026-11-13 timestamp prefixes and contains 2026-11-15 work; proposed filenames were adjusted to unique same-day timestamps after the existing 19:00 migrations.
- Planning choices: Keep the old site-agent route default, keep callback verification/WalletService as the only sale and wallet authority, require explicit router opt-in, and stop at the Phase 1 gate. Phase 2/production exposure requires the user’s separate approval.
- Wrong assumptions/corrections: The earlier t5 baseline of 515 is stale; t6's 540-test run supersedes it. The current commit is artifact-only, so t6 remains the latest applicable source baseline.
- Techniques/patterns worth reusing: Record source baseline provenance and test configuration; isolate every schema/browser test database explicitly; treat DB engine/adapter uncertainty as a blocking prerequisite rather than inventing a choice.
- Learnings consumed: [(none)]

## [t8] Phase 1 plan and strategy gate
- Codebase/domain discoveries: The fresh current checkout at `c1fd3bd` still passes 540 PHPUnit tests (2,910 assertions) with PHPUnit forcing SQLite `:memory:`; the Vite build also passes. Existing activation still pairs the site-agent requirement with command/ack behavior, and payment fulfillment still locks the transaction and uses `WalletService`.
- Wrong assumptions and corrections: A plan-gate PASS does not mean the Phase 1 implementation release gate passed; no implementation/migration/browser/target-engine evidence exists yet. The t7 trace checkpoint under-reports T005's references to plan items 1.2 and 1.3.
- Debugging dead-ends and what actually worked: `Get-Date -AsUTC` is not supported by this PowerShell version; `[DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')` gives the needed UTC timestamp. Docker CLI availability alone was misleading; `docker info` confirmed the daemon is unavailable.
- Techniques/patterns worth reusing: Read `phpunit.xml` before baseline runs to establish DB isolation; review plan-item/task annotations against checkpoint mappings independently; keep Docker and browser prerequisites as separate capability axes.
- Learnings consumed: [teamlead/phase1-planning-boundaries]

## [t8.1] T005 plan traceability correction
- Codebase/domain discoveries: The plan already had T005's full `[Plan:1.2,1.3,1.4]` annotation; only the t7 checkpoint's 1.2/1.3 task lists and upstream traces were incomplete.
- Wrong assumptions and corrections: The first validator draft expected a `plan_items` block, but the source t7 checkpoint stores these rows under `phases`. Adapted the comparison to the actual checkpoint structure; the final validation passed.
- Debugging dead-ends and what actually worked: A PowerShell interpolated string ending a variable name with `:` needs `${item}` or `$($item)`; corrected before final validator run. Comparing inline task references against both phase mappings and upstream traces detected the exact inconsistency.
- Techniques/patterns worth reusing: Keep the original review artifacts as history; record post-review remediation and rerun results in a new checkpoint. Confirm the source revision and application status before relying on a recent baseline.
- Learnings consumed: [teamlead/phase1-planning-boundaries]

## [t21] Phase 1 binary release gate — FAIL
- Codebase/domain discoveries: Full SQLite PHPUnit and Playwright evidence passes, but the explicit plan requires target-engine migration/constraint/locking/concurrency validation; production DB engine is unidentified and Docker daemon is unavailable, so no engine-parity claim is valid. User-approved selector `access_mode=radius` conflicts with implementation artifacts using `access_backend=radius`.
- Wrong assumptions and corrections: Do not treat `access_mode` and `access_backend` as synonyms; do not turn all-green SQLite/browser evidence into a PASS when required target-engine and concurrency evidence is absent.
- Debugging dead-ends and what actually worked: Read-only review of the approved plan, t17–t20 evidence, clarification and a specialized security review supplied enough evidence for the binary gate; context.md and decisions.md were absent at their expected paths.
- Techniques/patterns worth reusing: Apply release criteria exactly; separate verified local correctness from engine/deployment readiness; retain new static security findings with severity and targeted remediation; never access `.env` or use unknown/default DB for validation.
- Learnings consumed: [teamlead/phase1-planning-boundaries]
