# t14 — Isolated Migration and Domain Regression Tests

## Summary

Added five Phase 1 migration tests on the existing PHPUnit-forced SQLite `:memory:` database and strengthened the explicit per-router rollback regression to prove retry idempotency. Only test files were changed; no production code, external database, migration command, router, infrastructure, deployment, or Phase 2 operation was touched.

## Deliverables

- `tests/Feature/Phase1DomainMigrationTest.php` — checks SQLite memory isolation, additive migration re-entry, legacy defaults/null sale links, sale-link uniqueness and tenant scoping, and guarded rollback for configured routers, typed sale links, and accounting identity evidence.
- `tests/Feature/SiteAgentControlPlaneTest.php` — repeats activation after explicit RADIUS-to-site-agent rollback and proves the same session and single agent command are reused.

## Test Results

- Focused command: `php artisan test tests/Feature/Phase1DomainMigrationTest.php tests/Feature/SiteAgentControlPlaneTest.php --do-not-cache-result`
  - Passed: 23
  - Failed: 0
  - Skipped: 0
  - Assertions: 186
  - Exit code: 0
- Full command: `php artisan test --do-not-cache-result`
  - Passed: 555
  - Failed: 0
  - Skipped: 0
  - Assertions: 3,016
  - Exit code: 0
- `php -l` on both changed test files: passed.
- `vendor/bin/pint --test tests/Feature/Phase1DomainMigrationTest.php tests/Feature/SiteAgentControlPlaneTest.php`: passed.
- `git diff --check` on the tracked test change: passed; separate trailing-whitespace scan of the new test file found no matches.
- The new migration test asserts `sqlite` and `:memory:`. PHPUnit's existing `RefreshDatabase` migration run supplies the fresh-schema pass; the test explicitly calls both migrations again to verify safe re-entry. No standalone migration command or `.env` database connection was used.

## Validation Verdict

integration: PASS — SQLite-memory migration and domain regressions, plus the complete PHPUnit suite; no failures or skips.
e2e: UNVERIFIED — browser journey is outside t14 scope and remains assigned to t15/t20.
overall: PASS — t14's requested regression scope passed; this is not the Phase 1 release-gate verdict.

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/artifacts/t12-dba.md` — additive schema, rollback guard expectations, and SQLite-only execution boundary.
- `.github/modernize/rearchitecture/artifacts/t13-backend.md` — implemented per-router dispatch and rollback behavior covered by regression tests.
- `.github/modernize/rearchitecture/artifacts/t7-teamlead-plan.md` — T005/T007 and the isolated migration/domain test strategy.
- `.github/modernize/rearchitecture/clarification.md` — approved Phase 1 limits and prohibition on production DB, router, infrastructure, and deployment actions.

## Evidence Mapping

- `t12-dba.md#Schema notes` and `#Test Results` → `Phase1DomainMigrationTest::test_phase_one_migrations_are_repeatable_and_keep_legacy_defaults_on_sqlite_memory` verifies additive schema re-entry and legacy defaults only on SQLite `:memory:`.
- `t12-dba.md#Residual Risks / Handoff` and `t7-teamlead-plan.md#Testing Strategy` → the typed sale-link uniqueness/tenant-scope tests and rollback-guard tests cover data-preserving constraints without claiming production-engine behavior.
- `t13-backend.md#Status` and `#Test Results` → the expanded `SiteAgentControlPlaneTest::test_radius_activation_is_pending_and_explicit_router_rollback_uses_site_agent` proves explicit rollback retries reuse the existing provisioning session and do not duplicate the agent command.
- `clarification.md#Backend` and `#Generic` → test-only changes, no live database or router actions, and the task stops before the Phase 1 release gate.

## Remaining Gaps

- Production DB engine remains unknown; engine-specific DDL, FK, lock, and concurrency behavior is not established by SQLite.
- Browser E2E and the Phase 1 release gate remain for their later assigned tasks.
- Existing t13 operational blockers remain unchanged; this task neither resolves nor expands them.
