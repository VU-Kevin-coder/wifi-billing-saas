# Phase 1 Blocker Fixes — Backend

## Summary

Aligned router selection to `access_mode`, added durable RADIUS capacity reservations and per-session access-mode snapshots, and closed the stale-snapshot gap by counting capacity with locking/current reads. Added a CI-only two-process admission test. The default SQLite suite remains free of concurrency-test skips.

## Changed Files

- `app/Services/RadiusAccountingService.php`
- `app/Services/AccessRevoker.php`
- `app/Services/RadiusRouterCredentialService.php`
- `app/Services/VoucherActivationService.php`
- `app/Console/Commands/ExpireSessions.php`
- `app/Console/Commands/RetryPendingRevocations.php`
- `app/Http/Controllers/Operator/LiveSessionController.php`
- `app/Models/Router.php`
- `app/Models/Session.php`
- `app/Models/RadiusDeviceReservation.php`
- `database/migrations/2026_11_17_000001_align_access_mode_and_add_radius_device_reservations.php`
- `tests/Feature/Phase1DomainMigrationTest.php`
- `tests/Feature/RadiusPolicyBoundaryTest.php`
- `tests/Feature/RouterRadiusCredentialDeliveryTest.php`
- `tests/Feature/SiteAgentControlPlaneTest.php`
- `tests/Concurrency/RadiusDeviceCapacityConcurrencyTest.php`
- `tests/Concurrency/radius_authorize_worker.php`
- `phpunit.ci-db.xml`
- `phpunit.xml`
- `tests/TestCase.php`
- `docs/ROTATION-RUNBOOK.md`
- `.github/modernize/rearchitecture/team/backend/log.md`
- `.github/modernize/rearchitecture/learnings/backend/radius-session-snapshots-and-capacity.md`

## Upstream Artifacts Consumed

none — no dependency artifacts provided

## Evidence Mapping

none — no dependency artifacts provided

## Test Results

- Focused command: `vendor/bin/phpunit --configuration=phpunit.xml --no-coverage --do-not-cache-result tests/Feature/RadiusPolicyBoundaryTest.php`
  - Passed: 17; failed: 0; skipped: 0; assertions: 102.
- Full default SQLite command: `vendor/bin/phpunit --configuration=phpunit.xml --no-coverage --do-not-cache-result`
  - Passed: 576; failed: 0; skipped: 0; assertions: 3,127.
- Formatting: `vendor/bin/pint --test app/Services/RadiusAccountingService.php tests/TestCase.php tests/Concurrency/RadiusDeviceCapacityConcurrencyTest.php tests/Concurrency/radius_authorize_worker.php` — passed.
- PHP syntax checks: `php -l` on the service, test base, concurrency test, and worker — all passed.
- `git diff --check` on the scoped implementation/harness/docs files — passed.
- PostgreSQL concurrency test: **not run**. No local disposable PostgreSQL 16 target or `psql` client was available. The test is included only by `phpunit.ci-db.xml`; SQLite results do not prove row-lock concurrency.

## Remaining Blockers

- Keep the Phase 1 gate blocked until the two-process test passes against an isolated PostgreSQL 16 `testing` database. No production database was accessed.
- Airtel status polling remains fail-closed pending official `/standard/v1/payments/{reference}` status-response documentation or a redacted provider example; no callback handler was added.
- Private-network deployment reachability is unverified. Docker/CHR and router operations were not performed; lab availability remains unverified.
- Rotation/revocation/recovery steps are documented in the runbook; deployment-specific verification remains outstanding.
