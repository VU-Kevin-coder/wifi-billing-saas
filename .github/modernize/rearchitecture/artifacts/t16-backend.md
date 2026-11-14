# t16 — Dependency Advisory Scan and Remediation

## Summary

Scanned the Composer and npm lockfiles. The initial Composer audit found one LOW Laravel framework advisory; updated only `laravel/framework` in `composer.lock` from `v12.70.2` to `v12.72.0`. Final Composer and npm audits are clear. Laravel 12 and PHP `^8.2` remain unchanged.

## Deliverables

- [cve-fix-summary.md](./cve-fix-summary.md) — scan coverage, finding, targeted fix, validation, and constraints.
- [cve-report-1.json](./cve-report-1.json) — initial shared-tool npm scan of direct dependencies (no findings).
- [cve-report-2.json](./cve-report-2.json) — post-fix shared-tool npm rescan (no findings).
- [cve-report-1-composer.json](./cve-report-1-composer.json) — initial Composer advisory record.
- [final-cve-report.json](./final-cve-report.json) — final shared-tool npm report (no findings); final Composer audit also returned no advisories.

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/artifacts/t15-tester.md` — confirmed pinned npm dependency/test setup and prior scoped validation; no `.env` processing.
- `.github/modernize/rearchitecture/clarification.md` — preserved Laravel 12/PHP 8.2 and the existing application/API behavior.

## Evidence Mapping

- `clarification.md#Backend` → Laravel 12/PHP 8.2 preserved; `composer.lock` resolves Laravel 12.72.0, whose published requirement is PHP `^8.2`.
- `t15-tester.md#Key Deliverables` → scanned the npm package manifest/lockfile containing the pinned Playwright setup; full lockfile audit found no advisories.
- Composer audit finding `CVE-2026-128083` → `cve-report-1-composer.json` and the single-package `composer.lock` change; post-fix Composer audit returned no advisories.

## Test Results

- Command: No application test command run.
- Passed: 0
- Failed: 0
- Skipped: 1 application test/build suite (intentionally not run).
- Details: Running Laravel tests could process a project `.env`, which is explicitly prohibited. The updated package was not installed into `vendor`; the PHP 8.2 environment also exposed pre-existing PHP `^8.3` requirements in locked `coding-libs/laravel-mfa v2.0.0` and `spatie/laravel-permission 8.3.2`. Metadata and advisory checks are recorded in the summary; runtime verification remains for the independent gates.

## Findings and Risks

- Initial: 1 LOW, 0 MEDIUM, 0 HIGH, 0 CRITICAL.
- Final: no known advisories in the final npm or Composer scans.
- The pre-existing PHP-version mismatch in other locked packages is outside t16's approved dependency-CVE scope and remains unmodified.
- No `.env` file was inspected or processed. No application source, API, tenant behavior, sales/access terms, site-agent path, infrastructure, database, or router was changed.
