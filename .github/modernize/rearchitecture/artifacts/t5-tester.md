# t5 — Current-checkout test and build baseline

## Summary

- Baseline revision: `f9643fc759623e58596720f6609f872fe9bef6d9` (`feat(tests): enhance platform control settings and data retention tests`)
- Baseline scope: existing Laravel PHPUnit suite and Vite production asset build, before Phase 1 implementation changes.
- Outcome: both scoped checks passed. No production source or tracked project files were changed.
- Test database: `phpunit.xml` forces `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. Tests using `RefreshDatabase` therefore run against an ephemeral in-memory SQLite database; no migrations were run against the default `.env` database.
- `phpunit.ci-db.xml` was not used because it intentionally reads database settings from the environment and may connect to an external database.

## Checkout and Environment

- Initial revision: `f9643fc759623e58596720f6609f872fe9bef6d9`
- Tracked checkout changes: none before or after baseline (`git diff --exit-code` succeeded).
- Pre-existing workspace state: `.github/modernize/` was untracked at task start; its existing files were not edited.
- Toolchain: PHP 8.2.12, PHPUnit 11.5.57, Composer 2.10.2, Node.js v24.15.0, npm 11.12.1, Vite 6.4.5.
- Docker Desktop was available (`docker info` succeeded); it was not needed for this baseline and no containers or Compose services were started.

## Existing Coverage Relevant to Phase 1

The full current suite includes payment, accounting, access provisioning, agent-control, and router-related feature tests, including:

- Payment/fulfillment: `MobileMoneyPaymentFlowTest`, `PesapalPaymentTest`, `FlutterwaveConfigurationTest`, `FlutterwaveWithdrawalTest`, `PaymentTokenTransportTest`, `RefundFlowTest`, `ReconcilePendingPaymentsTest`, and `WithdrawalReconciliationTest`.
- Wallet/accounting: `WalletAccountingTest` and `WalletLedgerTest`.
- Existing access/agent behavior: `SiteAgentControlPlaneTest`, `SiteAgentOfflineAlertTest`, `RouterProvisioningTest`, `RouterServiceTest`, and `WireGuardPeerTest`.

These are existing tests and were executed as part of the full suite. This inventory is not a claim that every external integration, production database behavior, or browser flow is exercised.

## Test Results

- Command: `php artisan test --do-not-cache-result`
- Configuration: default `phpunit.xml`; its forced in-memory SQLite environment settings were retained.
- Exit code: `0`
- Passed: `515`
- Failed: `0`
- Skipped: `0`
- Assertions: `2,774`
- Duration: `79.87s`

An initial attempt to pass `--configuration=phpunit.xml` through `artisan test` exited non-zero because Artisan already supplies the PHPUnit configuration (`Option --configuration cannot be used more than once`). It was not counted as the baseline result; the canonical command above was then run to completion successfully.

## Build Results

- Command: `npm run build`
- Exit code: `0`
- Result: Vite production build succeeded; 69 modules transformed in 18.46s.
- Generated ignored output under `public/build/`: `manifest.json` and five hashed CSS/JS assets. No tracked build/configuration files changed.

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/clarification.md` — used for the zero-regression requirement and the preservation constraints for existing APIs, site-agent routers, and access/payment behavior.

## Evidence Mapping

- `clarification.md#Generic — Existing test posture` → `Test Results`: the full baseline suite completed with 515 passed and zero failed/skipped.
- `clarification.md#Downstream Usage Notes` (preserve agent-managed routers and existing user/API flows) → `Existing Coverage Relevant to Phase 1`: named current suites were present in and passed as part of the full run. This evidence does not replace focused post-change regression validation.

## Gaps and Risks

- This is a source-checkout test/build baseline, not a Phase 1 release verdict.
- Tests use SQLite in memory. MySQL/PostgreSQL row-lock and concurrency behavior was not verified; the CI database configuration was deliberately not pointed at any external/default database.
- App process startup, browser E2E/Playwright, and real router/provider integrations were not run in this task. No Playwright install was attempted.
- Baseline does not establish latency, throughput, availability, recovery, or production SLOs.
- The malformed initial PHPUnit invocation is documented above; the correct full suite command passed.

## Verdict

```text
environment:
  docker: AVAILABLE — Docker Desktop; `docker info` succeeded (no containers started)
  node: AVAILABLE — v24.15.0
  playwright: UNVERIFIED — not checked; browser E2E was outside this baseline assignment
  infra-tier: FALLBACK(embedded/in-memory) — existing PHPUnit configuration forces SQLite `:memory:`
  browser-tier: SKIPPED — no E2E execution was requested for this baseline task
startup: UNVERIFIED — application process startup was not part of the test/build baseline
integration: PASS — exit_code: 0, passed: 515, failed: 0, skipped: 0; all existing PHPUnit unit/feature suites; external DB concurrency behavior remains unverified
e2e: UNVERIFIED — exit_code: n/a; no browser flows executed
overall: NEEDS_SIGNOFF — scoped test and build baseline passed; runtime/browser and real-database behavior remain unverified
```
