# Implementation Plan: Phase 1 Application Domain and Payment Safety

**Date**: 2026-11-13 | **Spec**: `.github/modernize/rearchitecture/artifacts/t3-pm.md`

## Summary

Implement only the Phase 1 repository/domain and payment-safety slice for an optional per-router access backend. Keep Laravel 12 / PHP 8.2 and the current Blade UI. Existing routers remain on the site-agent path by default; verified provider settlement and `WalletService` remain the only source of sale/wallet truth. Additive records support typed sale-to-voucher linkage and replay-safe, tenant/router-bound accounting evidence. No Phase 1 task may configure or deploy FreeRADIUS/WireGuard, create or change Docker/Compose lab infrastructure, contact/configure MikroTik CHR, alter production routers, run a migration against the default database, or begin hub onboarding.

The current checkout already contains the reviewed Pesapal/Airtel payment-verification changes. Do not duplicate or weaken those changes. Implement only a narrowly scoped defect if new tests prove one exists, and preserve the current route, token, status, sales, access, and agent contracts.

## Baseline and Current Checkout

- Latest applicable source baseline: t6 ran `php artisan test --do-not-cache-result` at `29aad52ee185b5734e1abfc645f9cea1faa3e871`: **540 passed, 0 failed, 0 skipped; 2,910 assertions; exit 0**. Its `phpunit.xml` forces SQLite `:memory:`.
- Baseline build: `npm run build`: **passed, exit 0**.
- Current HEAD is `c1fd3bd273e9b692a37b80bc63755affeda37b86` (`phase 2: Phase 1 Data Model completed`). `git show --stat` shows that commit changes rearchitecture artifacts only; current uncommitted changes are `.github/modernize/rearchitecture/artifacts/project-profile.yaml` and `board.md`. No `app`, `database`, `routes`, `tests`, or runtime configuration source paths are dirty, so t6's 540-test source baseline remains the latest applicable baseline. Rerun the complete suite before Phase 1 code changes and again at the release gate.
- The older t5 baseline of 515 passing tests predates t4.1–t4.4 and is superseded by t6's 540-test run.
- No baseline migration was run against the default database. Do not infer the production database engine from Laravel's default SQLite configuration.

## Technical Context

| Area | Plan input |
|---|---|
| Language/framework | PHP 8.2 / Laravel 12; preserve Blade and existing Tailwind patterns. |
| Application shape | Single Laravel modular monolith; no project topology groups exist. |
| Persistence | Existing Laravel database and models remain authoritative. PHPUnit runs on isolated in-memory SQLite. Production DB engine/version is not established and must be confirmed before engine-specific release evidence. |
| Existing integration boundary | Provider verification, `PaymentService::handleSuccessfulPackagePayment()`, `WalletService::apply()`, `VoucherActivationService`, `Session`, and the site-agent command queue are existing seams. |
| New domain data | Router-level backend selection/identity, typed sale-to-voucher relation for new sales, and tenant/router-bound normalized accounting evidence; no parallel editable entitlement or wallet ledger. |
| External access stack | FreeRADIUS 3.2 and WireGuard are future approved targets, not Phase 1 deployment/configuration work. T6's app-adapter-versus-restricted-SQL choice and the production DB engine must be decided in T001. |
| Testing | `php artisan test --do-not-cache-result`; `npm run build`; add a Playwright tier for the server-rendered portal and a disposable target-engine DB test when that engine is identified and an isolated instance is approved. |
| Performance | Measure the existing baseline; no new latency, throughput, availability, or recovery SLOs are invented by this plan. |
| Constraints | Preserve APIs, payment token transport, response/status semantics, package terms, tenant isolation, and agent behavior. Additive/reversible only; no unverified legacy backfill or default-DB migration. |

## Constitution Check

No separate `constitution.md` was found in the rearchitecture tree. The following explicit invariants from the approved clarification and PM/architecture artifacts serve as the binding constraints pending a project constitution:

| Invariant | Result | Plan evidence |
|---|---|---|
| Existing Laravel APIs, authentication, payment-token header, and response meanings remain compatible. | PASS | Items 2.1, 3.1–3.2; REQ-001, REQ-009, REQ-010. |
| Existing agent path remains default; no global bypass; pending until confirmation. | PASS | Items 1.2 and 2.1; REQ-002, REQ-003, REQ-011. |
| Payment is verified, tenant-bound, atomic, replay-safe; wallet balance only changes through `WalletService`. | PASS | Items 1.3 and 3.1–3.2; REQ-004, REQ-006–REQ-008. |
| Preserve sale/access terms and truthful statuses. | PASS | Items 2.1, 3.1–3.2; REQ-005, REQ-010. |
| No default-database migration, deployment, router configuration, or Phase 2 onboarding. | PASS | Items 1.1, 4.2; REQ-011–REQ-012. |

## Applied Guidelines

- No matching migration guideline applies. The available `struts-to-spring` guideline does not match this Laravel/PHP project. Apply current repository Laravel conventions and the upstream architecture/DBA contracts; do not introduce a framework or deployment migration.

## Implementation Steps

### Stage A — Foundational Decisions and Additive Domain Schema

#### Step 1.1: Confirm database and access-adapter boundary
- **Requirements**: REQ-004, REQ-011
- **Design inputs**: `t1-architect.md#Backend selection and identity`; `t6-dba.md#Open risks / decisions required`; approved clarification.
- **Description**: Before implementation, confirm the supported production DB engine/version from an authoritative non-secret project/operations source, and record the selected Laravel-owned adapter or narrowly scoped protocol/database integration boundary. The decision must explain how a per-router RADIUS secret is used without exposing Laravel's `APP_KEY` or granting broad DB access. If either decision cannot be established, stop schema/integration implementation and escalate; do not guess, inspect secrets, or configure infrastructure.
- **Constraints**: No FreeRADIUS container/configuration, SQL grants, WireGuard changes, or lab setup in this item.

#### Step 1.2: Add explicit per-router backend identity
- **Requirements**: REQ-002, REQ-004, REQ-011
- **Design inputs**: `t1-architect.md#Backend selection and identity`; `t6-dba.md#1. Explicit router access mode and RADIUS identity`.
- **Description**: Add an additive `routers.access_backend` column defaulting to `site_agent`, plus nullable unique NAS identity and a separately encrypted nullable RADIUS shared secret. Keep all existing routers on the legacy default; invalid or incomplete RADIUS configuration must fail closed. Add model casts/validation only after confirming established Laravel conventions. Never reuse API, site-agent, user, or WireGuard credentials.

#### Step 1.3: Add durable sale-to-voucher identity
- **Requirements**: REQ-004, REQ-008
- **Design inputs**: `t1-architect.md#Payment and fulfillment`; `t2-backend.md#Payment and fulfillment path`; `t6-dba.md#2. Durable sale-to-voucher identity for new sales`.
- **Description**: Add nullable, unique `vouchers.sale_transaction_id` with restrictive FK behavior and tenant correspondence constraints where supported by the confirmed engine. Keep `transactions.meta.voucher_id` as the legacy fallback; do not backfill old sales. New linkage is written only through the existing verified, transaction-locked fulfillment operation.

#### Step 1.4: Add tenant/router-bound accounting evidence
- **Requirements**: REQ-004, REQ-008, REQ-010
- **Design inputs**: `t1-architect.md#RADIUS authorization and accounting`; `t6-dba.md#3. RADIUS accounting identity and evidence`.
- **Description**: Add the accounting-session projection and append-only normalized event evidence with unique router/NAS-session and event-fingerprint keys, non-negative cumulative counters, and composite tenant/router/session constraints where supported. Unknown or mismatched sessions remain unbound/quarantined; no direct entitlement or session-state mutation is exposed to a RADIUS DB principal. Migration rollback must refuse destructive removal when populated.

### Stage B — Preserve Legacy Access and Implement the Optional Domain Boundary

#### Step 2.1: Keep legacy activation default and add explicit backend dispatch
- **Requirements**: REQ-001, REQ-002, REQ-003, REQ-004, REQ-005, REQ-010, REQ-011
- **Design inputs**: `t1-architect.md#Access selection`; `t2-backend.md#Current access fulfillment path`; `t3-pm.md#Site-agent and access behavior`.
- **Description**: Update the activation seam in `app/Services/VoucherActivationService.php` and router domain model so the default and every pre-existing router follow the current site-agent command/acknowledgment path unchanged. Only a valid, explicit router mode may select the future RADIUS adapter. Missing identity/configuration fails closed; do not silently fall back. The RADIUS branch must not dispatch site-agent commands and must not report active access until reconciled accounting confirms the session. Preserve package limits and current public response/status contracts.

#### Step 2.2: Implement replay-safe accounting reconciliation
- **Requirements**: REQ-004, REQ-008, REQ-010, REQ-011
- **Design inputs**: `t1-architect.md#RADIUS authorization and accounting`; `t6-dba.md#Tenant-safe database access`.
- **Description**: Implement a Laravel-owned, normalized accounting service that binds the authenticated NAS/router to its server-side tenant, records each event once, updates cumulative counters only for accepted newer events, keeps Stop terminal for a NAS-session identity, and updates `sessions_log` only after exact tenant/router/session/voucher validation. Unmatched events never grant access. Keep the service behind the integration decision from item 1.1; do not expose a public endpoint or database principal beyond the approved design.

### Stage C — Verified and Idempotent Payment Fulfillment

#### Step 3.1: Persist typed linkage through the existing fulfillment transaction
- **Requirements**: REQ-004, REQ-006, REQ-007, REQ-008, REQ-010
- **Design inputs**: `t1-architect.md#Payment and fulfillment`; `t2-backend.md#Successful fulfillment`; `t6-dba.md#2. Durable sale-to-voucher identity`.
- **Description**: Update `app/Services/PaymentService.php` and relevant `Transaction`/`Voucher` relationships to link each new paid sale to exactly one voucher inside the existing lock/DB transaction. Keep provider verification before fulfillment, preserve `WalletService::apply()` as the sole wallet writer, return the existing fulfillment on retries, and retain metadata fallback for legacy sales. Do not change any route or status response.

#### Step 3.2: Characterize provider verification, token, status, and replay contracts
- **Requirements**: REQ-001, REQ-005, REQ-006, REQ-007, REQ-008, REQ-009, REQ-010, REQ-012
- **Design inputs**: `t3-pm.md#Explicit Characterization Evidence`; `t4.5-security.md#Review evidence`; `t2-backend.md#Existing tests that anchor the extension boundary`.
- **Description**: Extend focused existing tests and add the missing dedicated Flutterwave charge-webhook characterization if required to cover matching identity/amount/currency, mismatch/unavailable denial, and replay. Assert no wallet, voucher, or session side effect on denial; assert one fulfillment on repeat. Keep the Pesapal/Airtel remediation tests intact and do not invent Airtel amount/currency requirements absent authoritative contract evidence.

### Stage D — Regression Validation and Phase 1 Release Gate

#### Step 4.1: Run complete server, browser, migration, and build evidence
- **Requirements**: REQ-001 through REQ-010, REQ-012
- **Design inputs**: `t5-tester.md#Test Results`; `t6-dba.md#Required implementation tests`; runtime-validation strategy below.
- **Description**: Execute the pre-change baseline, focused feature tests, full PHPUnit suite, isolated migration tests, isolated target-engine constraint/locking tests if the engine is identified, Playwright portal journeys, and the production Vite build. Preserve all existing tests and record exact commands, exit codes, pass/fail/skip counts, and per-axis gaps. Do not use the default DB. Test failures block completion.

#### Step 4.2: Apply the binary Phase 1 release gate and stop
- **Requirements**: REQ-011, REQ-012
- **Design inputs**: `t3-pm.md#Success Criteria`; `t4.5-security.md#Verdict`; user-approved Phase 1 boundary.
- **Description**: The gate is PASS only if all required code and migration evidence is complete, existing and new tests/build pass with zero failures, no high/critical issue remains, the compatibility checks pass, and no prohibited infrastructure/router/deployment action occurred. Otherwise report FAIL with evidence and owners. List remaining low/contract/engine risks. Stop after the Phase 1 report; Phase 2 requires separate explicit user approval.

## Implementation Task Breakdown

### Stage A — Foundational Decisions and Additive Domain Schema

- [ ] T001 [Plan:1.1] Record the confirmed production DB engine/version and approved RADIUS/application adapter plus secret-isolation boundary in `.github/modernize/rearchitecture/artifacts/phase1-implementation-decisions.md`; escalate if unresolved [Owner: architect, DBA, security]
- [ ] T002 [Plan:1.2] Add a migration at `database/migrations/2026_11_13_192100_add_access_backend_and_radius_identity_to_routers_table.php` for `site_agent` default, unique nullable NAS identity, and nullable encrypted-secret storage; add corresponding `Router` cast/validation in `app/Models/Router.php` [Owner: DBA, backend]
- [ ] T003 [Plan:1.3] Add `database/migrations/2026_11_13_192200_add_sale_transaction_id_to_vouchers_table.php` with nullable unique restrictive sale FK and tenant-safe constraint; add `Voucher`/`Transaction` relation in `app/Models/Voucher.php` and `app/Models/Transaction.php` [Owner: DBA, backend]
- [ ] T004 [Plan:1.4] Add `database/migrations/2026_11_13_192300_create_radius_accounting_tables.php` and `app/Models/RadiusAccountingSession.php`, `app/Models/RadiusAccountingEvent.php` with replay, cumulative-counter, tenant/router, and guarded-rollback invariants [Owner: DBA, backend]
- [ ] T005 [P] [Plan:1.2,1.3,1.4] Add `tests/Feature/Phase1DomainMigrationTest.php` to prove legacy router defaults, nullable legacy sale links, uniqueness/FK/tenant constraints, encrypted credential behavior, and refusal to destructively roll back populated accounting state using only an explicit disposable test database [Owner: tester]

### Stage B — Legacy-Compatible Access and Accounting

- [ ] T006 [Plan:2.1] Update `app/Services/VoucherActivationService.php` and `app/Models/Router.php` for explicit per-router backend dispatch; preserve the site-agent default, command/ack behavior, package terms, and existing API/status contracts [Owner: backend]
- [ ] T007 [Plan:2.1] Extend `tests/Feature/SiteAgentControlPlaneTest.php` and `tests/Feature/TenantIsolationTest.php` for old-router default, explicit-mode validation, fail-closed missing credentials, tenant rejection, pending-until-confirmed state, and unchanged agent command lifecycle [Owner: tester]
- [ ] T008 [Plan:2.2] Implement normalized, idempotent accounting ingestion/reconciliation in `app/Services/RadiusAccountingService.php` and update `app/Models/Session.php` only after exact tenant/router/voucher matching [Owner: backend]
- [ ] T009 [Plan:2.2] Add `tests/Feature/RadiusAccountingTest.php` for duplicate fingerprints, repeated Start/Interim/Stop, stale events, terminal Stop, counter monotonicity, unknown-session quarantine, and cross-tenant/router binding rejection [Owner: tester]

### Stage C — Verified and Idempotent Payment Fulfillment

- [ ] T010 [Plan:3.1] Update `app/Services/PaymentService.php`, `app/Models/Transaction.php`, and `app/Models/Voucher.php` to persist the typed relation atomically while retaining provider verification, `WalletService`, replay outcome, and legacy metadata fallback [Owner: backend]
- [ ] T011 [Plan:3.2] Extend `tests/Feature/MobileMoneyPaymentFlowTest.php`, `tests/Feature/PesapalPaymentTest.php`, and `tests/Feature/PaymentTokenTransportTest.php`; add `tests/Feature/FlutterwaveWebhookTest.php` for verified identity/amount/currency, mismatch/unavailable denial, header-only token, unchanged status semantics, and idempotent fulfillment [Owner: tester]

### Stage D — Regression Validation and Phase 1 Release Gate

- [ ] T012 [Plan:4.1] Add pinned `@playwright/test` tooling and a server-rendered portal journey in `playwright.config.ts` and `tests/e2e/phase1-payment-status.spec.ts`; configure an explicitly disposable test DB and expose a canonical aggregate test command in `composer.json`/`package.json` without removing `php artisan test --do-not-cache-result` [Owner: tester]
- [ ] T013 [Plan:4.1,4.2] Run the complete test/build/migration/browser matrix, produce the per-axis verdict and evidence report under `.github/modernize/rearchitecture/artifacts/`, apply the binary Phase 1 gate, and stop without beginning Phase 2 [Owner: tester, teamlead]

## Dependencies and Execution Order

1. T001 blocks T002–T004 and any protocol/database integration.
2. T002–T004 are schema prerequisites for T005–T011. Keep migrations additive and test only against an isolated disposable database.
3. T006 depends on T002 and the approved boundary from T001; T007 follows T006.
4. T008 depends on T004/T005 and T001; T009 follows T008.
5. T010 depends on T003; T011 follows T010.
6. T012 may be prepared independently of server-side code but must use the final existing routes/status contract and a separately configured disposable database. T013 waits for T005, T007, T009, T011, and T012.
7. Each implementation task runs and records its focused module tests; no task may defer its first verification to T013.

## Project Structure

```text
app/
  Models/
    Router.php                         # explicit backend selector and secret cast
    Transaction.php                    # typed sale fulfillment relation
    Voucher.php                        # nullable unique sale relation
    Session.php                        # reconcile verified accounting facts
    RadiusAccountingSession.php        # new projection
    RadiusAccountingEvent.php          # new normalized immutable evidence
  Services/
    PaymentService.php                 # typed linkage in existing atomic fulfillment
    VoucherActivationService.php       # explicit backend branch; legacy default
    RadiusAccountingService.php        # new normalized reconciliation boundary
database/migrations/
  2026_11_13_192100_add_access_backend_and_radius_identity_to_routers_table.php
  2026_11_13_192200_add_sale_transaction_id_to_vouchers_table.php
  2026_11_13_192300_create_radius_accounting_tables.php
tests/
  Feature/Phase1DomainMigrationTest.php
  Feature/RadiusAccountingTest.php
  Feature/FlutterwaveWebhookTest.php
  Feature/SiteAgentControlPlaneTest.php  # extend, do not weaken
  Feature/MobileMoneyPaymentFlowTest.php # extend, do not weaken
  Feature/PesapalPaymentTest.php         # extend, do not weaken
  Feature/PaymentTokenTransportTest.php  # extend, do not weaken
  e2e/phase1-payment-status.spec.ts
playwright.config.ts
composer.json                           # aggregate test entry point
package.json / package-lock.json        # pinned browser-test tooling
```

Do not modify `routes/api.php`, `routes/web.php`, Dockerfiles, `docker-compose.yml`, router provisioning defaults, deployment configuration, or production secrets unless a separate approved task demonstrates a necessary contract-preserving application change. No infrastructure or router operations are planned here.

## Testing Strategy

### App Type and Legacy Test Assets

- **appType**: Mixed server-rendered Laravel Blade pages and JSON/webhook API endpoints.
- **legacyTestAssets**: Discovered PHPUnit unit and feature suites; keep and run unchanged except narrowly additive assertions. `t5-tester.md` identifies payment, wallet, refund, agent, router, package, and tenant-isolation suites.
- **migrationDecision**: Reuse unchanged for the existing behavior; add focused tests only for new domain behavior. No existing browser E2E suite was identified in the supplied baseline inventory; tester checks `tests/e2e/` and Cypress assets before adding a new browser harness.

### Critical User Journeys

1. **Existing agent-backed activation and expiry**: activate a tenant-owned voucher on a legacy/default router; verify session stays provisioning/disconnecting until the paired agent acknowledges the existing command. Missing agent or ambiguous router fails closed.
2. **Verified sale and one-time fulfillment**: simulate the existing provider verification seam, complete a pending sale, and assert exactly one voucher, session, and wallet-ledger effect with a typed transaction link; resend callback/status/activation and assert no duplicates.
3. **Untrusted or uncertain payment**: provider mismatch, unavailable verification, wrong amount/currency, wrong provider reference, invalid token, and token-in-URL cases do not disclose the sale or create voucher/session/wallet effects; existing pending/failed statuses remain unchanged.
4. **Tenant-safe optional access selection**: an explicit valid router mode may enter only the future approved adapter; absent/invalid identity or cross-tenant router/voucher/accounting data is rejected without falling back to another backend or site-agent token.
5. **Accounting replay and truthful session state**: duplicate Start/Interim/Stop events map to one NAS session; stale counters do not regress usage; unmatched evidence remains unbound; active/disconnected is shown only after verified accounting/acknowledgment. Phase 1 tests the Laravel domain boundary only—not live RADIUS, router traffic, or browsing.

### Primary Validation Stack

- **PHP unit/feature/integration**: canonical current command `php artisan test --do-not-cache-result`; preserve and rerun the entire current suite. All new code paths receive focused feature tests first. PHPUnit currently forces SQLite `:memory:`.
- **Migration/domain constraints**: run fresh/up/repeat/guarded-down migration tests against an explicit disposable SQLite test DB. After T001 identifies the actual supported DB engine, run constraint, locking, and concurrency checks against a separate disposable instance of that engine before the release gate. Never infer/use the `.env` default database.
- **Browser tier**: Playwright against the running local Laravel app for one or more real Blade customer journeys, using an explicit testing environment and disposable DB. Node.js is available but Playwright is not installed; T012 adds pinned test tooling, and tester must attempt Chromium setup. Do not replace browser tests with HTTP probes while the Node prerequisite remains usable.
- **Build**: `npm run build` remains the canonical asset build. Run `composer test` (or its approved equivalent) after T012 to aggregate PHPUnit and Playwright so the new suite is reachable from the repository's canonical validation entry point; retain the direct PHP command for focused diagnosis.
- **Static checks**: run `php -l` on changed PHP files and `git diff --check`; these supplement, not replace, build/tests.

### Capability Probe and Fallback Matrix (observed during planning)

| Axis | Current evidence | Primary | Fallback and gap |
|---|---|---|---|
| Infrastructure / DB | `docker info` exit 1: `failed to connect ... npipe:////./pipe/dockerDesktopLinuxEngine ... The system cannot find the file specified.` Docker CLI v29.9.3 exists, daemon is not responding. | Once the production DB engine is identified and an isolated instance is approved/available, execute engine-specific migration/FK/locking tests using that disposable engine. | Existing PHPUnit SQLite `:memory:` suite is available. If Docker remains unavailable, continue safe in-memory coverage but report loss of production-engine FK/locking/concurrency fidelity; Phase 1 release gate cannot claim engine parity until an isolated target-engine run succeeds. Do not create/modify Compose lab infrastructure to work around this. |
| Browser | Node.js v24.15.0 available; `npx --no-install playwright --version` exit 1 because Playwright package is missing. No browser install attempted in planning. | Add pinned Playwright test dependency and attempt browser installation/run in T012/T013. | Only if the Playwright install itself reproducibly fails after attempt: framework-native Laravel HTTP tests remain, but actual Blade rendering, browser behavior, and static asset loading are unverified. Record exact install error and escalate; do not cite Docker unavailability as a reason to skip Playwright. |

Docker and browser are independent axes. A Docker failure does not justify skipping Playwright; a browser failure does not justify dropping isolated DB/infra tests. Re-probe both at execution time.

### External Dependency and Test Infrastructure Strategy

| Dependency/boundary | Test treatment |
|---|---|
| Laravel database | PHPUnit current configuration uses isolated SQLite `:memory:`. Migration tests use an explicit disposable DB path. Target-engine verification uses only an approved throwaway test instance; never default `.env` or production database. |
| Payment providers | Use Laravel HTTP fakes/mocks with contract-shaped responses; never call live provider APIs. Verify expected local transaction reference, tenant, package, amount, and currency before fulfillment. |
| Site-agent | Use feature tests through existing authenticated routes and factories; do not change token or command payload contracts. |
| RADIUS/NAS/WireGuard | Unit/feature tests call the normalized Laravel service boundary with synthetic, non-secret events. No FreeRADIUS process, router, CHR, WireGuard network, or customer traffic is configured in Phase 1. |
| SMS, cache, queue, filesystem | Use Laravel fakes/array/sync test drivers already established by the test environment; assert side effects only where relevant. |
| Browser to Laravel | Playwright targets a local Laravel server with testing-only config and seeded disposable data. No live provider or production credentials are used. |

**Test base/setup**: Reuse existing PHPUnit base classes, factories, tenant context helpers, and `RefreshDatabase`; create new fixtures with unique IDs. For browser tests, provide a separate setup/teardown script that chooses a disposable DB explicitly and removes it. No test may rely on pre-existing database rows.

**Per-module startup skeletons**: This repository is a single Laravel service; PHPUnit application boot is the service startup check. The Playwright `webServer` config supplies the only separate HTTP-server startup check. There are no independent service modules.

**Per-task rule**: Each implementation task runs its focused tests and reports exact pass/fail/skip counts and exit code. Any failure means that task is not complete. T013 performs the full final matrix and is not a substitute for task-level tests.

### Acceptance Criteria

- Journeys 1–5 satisfy their asserted state, tenant, package-term, wallet, voucher, and idempotency invariants.
- Existing routes, request/response shapes, authentication, token header handling, status names/meanings, throttle/idempotency behavior, and site-agent protocol remain unchanged.
- Existing `php artisan test --do-not-cache-result` suite and all new tests exit 0 with zero failed/skipped tests; `npm run build` exits 0.
- Migration tests prove default legacy mode, new relation uniqueness/foreign keys, and safe rollback guards, with no default database touched.
- Browser test tier passes if Playwright's available prerequisites permit it; if it does not, exact reproducible failure and per-axis gap are reported, and a fallback cannot be accepted without coordinator sign-off.
- Engine-specific FK/locking/concurrency assertions pass against the confirmed production engine in an isolated disposable instance; absent that evidence, Phase 1 gate is FAIL/blocked, not assumed PASS.

### Release Gate / Validation Review Expectations

Binary verdict only: PASS or FAIL. Any HIGH/CRITICAL finding, missing required evidence, failed test/build, changed legacy contract, weakened/skipped existing test, unverified default-DB exposure, or prohibited deployment/router/Phase 2 operation is FAIL. The gate reviewer checks:

1. Correct source baseline and exact test/build commands, exit codes, pass/fail/skip counts.
2. Full REQ-001–REQ-012 evidence, including same package/access terms and no wallet/voucher/session duplication on replay.
3. Separate evidence for the Docker/DB and browser capability axes; fallback prerequisites and gaps are not conflated.
4. Per-router default and fail-closed behavior, tenant-derived identity, no agent bypass, no secrets in logs/raw accounting evidence.
5. Migration results on disposable test DB(s) only, correct rollback protection, no historical backfill.
6. No app routes or public response contracts changed without approval; no Docker/Compose, FreeRADIUS, CHR, router, deployment, or hub onboarding work performed.
7. Remaining risks: t4.5's LOW Laravel advisory (`CVE-2026-128083` on `laravel/framework v12.70.2`, advisory says upgrade to 12.71.0+), Airtel amount/currency response-contract uncertainty, and any production-engine/browser capability gaps. These risks must be carried forward and cannot be hidden; resolve or explicitly accept them before any later external exposure.
8. End at the Phase 1 gate. No Phase 2 work without separate explicit user approval.

## Requirement Mapping

| REQ ID | Description | Plan Items | Implementation Evidence |
|---|---|---|---|
| REQ-001 | Existing API compatibility | 2.1, 3.2, 4.1 | `routes/api.php` remains unchanged; `VoucherActivationService.php`; API feature and browser status tests |
| REQ-002 | Agent compatibility | 1.2, 2.1, 4.1 | `Router.php`; `SiteAgentControlPlaneTest.php` |
| REQ-003 | Truthful agent-backed state | 2.1, 4.1 | `VoucherActivationService.php`; agent acknowledgment regression tests |
| REQ-004 | Tenant isolation | 1.1–1.4, 2.1–2.2, 3.1 | Composite constraints, `RadiusAccountingService.php`, tenant-isolation feature tests |
| REQ-005 | Unchanged commercial offer | 2.1, 3.2 | Existing package fields/terms unchanged; package, purchase, and renewal tests |
| REQ-006 | Payment confirmation before entitlement | 3.1–3.2 | Existing provider verifiers and `PaymentService.php`; provider match/mismatch tests |
| REQ-007 | Safe payment uncertainty | 3.2 | Provider unavailable/mismatch refusal tests; unchanged pending/failed status assertions |
| REQ-008 | Single fulfillment | 1.3–1.4, 2.2, 3.1–3.2 | Unique sale link, unique accounting fingerprints, repeated callback/activation assertions |
| REQ-009 | Payment-token confidentiality | 3.2, 4.1 | `PaymentTokenTransportTest.php`; header-only browser/API tests |
| REQ-010 | Truthful customer status and package enforcement | 1.4, 2.1–2.2, 3.1–3.2 | Activation/accounting state assertions and package-limit/status tests |
| REQ-011 | No bypass or premature cutover | 1.1–1.2, 2.1–2.2, 4.2 | Default-site-agent tests, negative operation checklist, release report |
| REQ-012 | Regression gate | 3.2, 4.1–4.2 | Full PHPUnit, Playwright, build, migration, and final gate evidence |

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/artifacts/t1-architect.md` — design constraints, per-router default, payment boundary, and phase boundary.
- `.github/modernize/rearchitecture/artifacts/t2-backend.md` — current source seams and existing regression suites.
- `.github/modernize/rearchitecture/artifacts/t3-pm.md` — requirements and acceptance scenarios.
- `.github/modernize/rearchitecture/artifacts/t4.5-security.md` — payment security re-review and residual risks.
- `.github/modernize/rearchitecture/artifacts/t5-tester.md` — prior baseline and test environment.
- `.github/modernize/rearchitecture/artifacts/t6-dba.md` — data shape, replay constraints, rollback policy, open DB/adapter decisions.
- `.github/modernize/rearchitecture/clarification.md` — approved scope and non-negotiable exclusions.
- `.github/modernize/rearchitecture/clarification-answers.md` — explicit user decisions.
- `.github/modernize/rearchitecture/artifacts/project-profile.yaml` and `board.md` — repository scope and phase status.

## Evidence Mapping

- `t3-pm.md#REQ-001–REQ-012` → Implementation Steps, Requirements Mapping table, and `checkpoints/t7-spec-to-plan.yaml`.
- `t1-architect.md#Frozen compatibility surface` → tasks T006–T011 and the no-route/no-status-change acceptance checks.
- `t2-backend.md#Payment and fulfillment path` → T010–T011 keep the verified callback and locked fulfillment seam; the route/token and site-agent test files are named.
- `t6-dba.md#Proposed additive schema` and `#Rollback and irreversible effects` → T002–T005 and isolated migration gate.
- `t5-tester.md#Test Results` plus `t6-dba.md#Test Results` → 540-test/2,910-assertion current source baseline and post-change comparison requirement.
- `t4.5-security.md#Remaining risks / non-blocking findings` → known LOW advisory and Airtel uncertainty in the gate report; no risk is represented as resolved by this plan.

## Baseline Test Results (Planning Evidence)

- Command: `php artisan test --do-not-cache-result` (t6 current source baseline)
- Passed: 540; Failed: 0; Skipped: 0; Assertions: 2,910; Exit: 0.
- Build: `npm run build` — passed; exit 0.
- Tests run by t7: none; this is a plan/test-strategy artifact, not implementation or runtime validation.
