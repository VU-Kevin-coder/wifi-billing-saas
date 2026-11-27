# Production Readiness Implementation Plan

**Created:** 2026-11-27
**Status:** Proposed execution plan; production go-live is not approved.
**Source assessment:** [Production-readiness audit](./PRODUCTION-READINESS-AUDIT-2026-11-27.md)
**Scope:** Laravel 12 / PHP 8.2 SaaS application, tenant subscriptions and customer payments, deployment, data recovery, operational readiness, and optional router/RADIUS service.

## Current CI status — immediate release blocker

The current release candidate failed **Pint, SQLite, MySQL, PostgreSQL, and
dependency audit**. The failing jobs and root causes have now been identified;
local fixes and verification are recorded below. Keep this as a release blocker
until the full GitHub Actions workflow is green on the same release candidate.

The current failing [run 45982911074](https://github.com/VU-Kevin-coder/wifi-billing-saas/actions/runs/45982911074)
was for PR #76, head `580ec5a17bac1148122c69120a7a2e52db37c8b4` and merge
`100c81074bfe69fb6bcbd1ec142aaf92f605d67a`. Pint reported seven style issues.
The database suites exposed stale UI assertions: a no-plan tenant was expected
to show the self-serve plan-change button; a nested MFA partial was treated as
a standalone layout in the SweetAlert test; and the standalone landing page was
expected to load the portal Vite stylesheet. Those assertions have been
corrected without changing production behavior. The Composer audit reported
five Symfony advisories affecting `symfony/http-kernel`, `symfony/mailer`, and
`symfony/routing` (CVE-2026-133743, CVE-2026-133746, CVE-2026-133751,
CVE-2026-133754, and CVE-2026-133755); the lockfile now contains patched
versions.

Local verification on the modified worktree: Pint passes; SQLite passes
(766 tests, 6,150 assertions, 2 skipped); Composer audit and npm production
audit pass. The local MySQL run could not complete because Docker Desktop
became unavailable during MySQL startup. The full GitHub Actions workflow has
not yet been rerun on these changes, so require a green run on the release
candidate before clearing this gate.

**Order of work:** complete remaining Phase 1 tasks T001–T006 before starting
feature implementation, provider enablement, or any production action. Both
audits now run independently even if Composer audit fails. Do not treat local
success or old green jobs as a substitute for the required full CI rerun.

## Summary

The application has meaningful product and payment foundations, but cannot yet
be treated as a production-ready release. The main work is not simply adding
another payment button: it is proving subscription entitlement and expiry,
closing payment recovery and financial operations, validating the exact release
candidate on PostgreSQL, proving backups and restoration, verifying runtime
configuration and alerting, and accepting any claimed router/network path only
after isolated hardware validation.

Execution is ordered through independent go/no-go gates. Live tenant checkout
must remain disabled until the commercial decisions, subscription lifecycle,
payment evidence, and data-integrity gates pass. Router/RADIUS work is a
separate capability gate: if the first launch is explicitly limited to
non-RADIUS routers and that scope is enforced, physical RADIUS acceptance need
not block that limited release; it does block enabling or claiming RADIUS.

This plan assigns no dates or numeric SLOs without team capacity and baseline
measurements. It assumes no authorized sandbox credentials, production
configuration, production database, or physical router is presently available.
The first execution task is to identify the release candidate and preserve the
existing dirty worktree. No task authorizes a live deployment, production
database operation, provider charge, or router change; these require separate
explicit approvals.

## Technical Context

**Language/framework:** PHP 8.2+, Laravel 12, Blade, Tailwind CSS, Vite.
**Payments:** Existing OBPay, PawaPay, and ioTec adapters support customer
collections; subscription billing uses separate invoice, attempt, and history
tables and currently shares the selected voucher-collection merchant
configuration.
**Persistence:** Laravel migrations; Render blueprint specifies PostgreSQL,
with Redis-backed cache/session and a separate scheduler worker.
**Tests/build:** PHPUnit, Playwright, Vite; GitHub Actions declares SQLite,
MySQL 8, PostgreSQL 16, dependency audit, static/style and asset jobs.
**Target runtime:** Render Docker image with PHP-FPM/Nginx web service,
PostgreSQL, Redis, and scheduler worker. Actual DNS, proxy chain, mail provider,
monitoring, database plan, backups, and secrets are environment decisions that
must be verified.
**Constraints:** Preserve current tenant isolation and payment/access
contracts; do not inspect or commit `.env` secrets; do not use production for
testing; no destructive migration or live customer/router cutover without
approved backup and rollback. Subscription checkout is default-off.
**Performance goals:** Measure current service and database baselines first.
Product/operations must approve measurable SLOs and recovery objectives before
load/availability acceptance.

### Source requirements derived from the audit

The repository does not contain a production-readiness feature specification
with formal requirement IDs. These plan-local IDs are derived from the
findings in the linked audit and must be replaced or reconciled with product
acceptance criteria during Gate 0.

- **REQ-PROD-001:** A single reviewed, reproducible release candidate passes
  the complete project validation gates.
- **REQ-PROD-002:** Tenant plan entitlement, trial, expiry, grace, renewal,
  suspension, and access policy agree and remain correct under retries and
  concurrency.
- **REQ-PROD-003:** Ambiguous, failed, late, mismatched, or exceptional
  subscription payments can be safely verified and resolved by authorized
  staff without accidental duplicate charges or entitlements.
- **REQ-PROD-004:** Subscription pricing, provider/account, renewal/consent,
  currency, cancellation, refund, and notice behavior are explicitly approved.
- **REQ-PROD-005:** Payment submission and status are tested against the
  authorized provider contract; only authenticated, matching evidence can
  settle an invoice or grant entitlement.
- **REQ-PROD-006:** Settlements, refunds, reversals, disputes, voucher sales,
  withdrawals, and application ledgers have an auditable reconciliation
  procedure and operational owner.
- **REQ-PROD-007:** Production-engine migrations, uniqueness/locking,
  idempotency, and concurrent financial operations are validated on isolated
  PostgreSQL matching the target.
- **REQ-PROD-008:** Encrypted production backups can be restored and verified;
  migration and release recovery procedures are documented and rehearsed.
- **REQ-PROD-009:** Runtime configuration, mail, tenant domains, proxy trust,
  readiness, scheduler/queue health, payment lag, alerts, and logging are
  verified in the target deployment.
- **REQ-PROD-010:** Security, dependency, access-control, secret-handling,
  privacy/retention, incident, key-rotation, and release-control obligations
  are reviewed and operationally owned.
- **REQ-PROD-011:** RADIUS/router operation is enabled or represented as
  supported only after contract, network, concurrency, revocation, hardware,
  and rollback evidence passes for the declared scope.
- **REQ-PROD-012:** A controlled, monitored rollout has explicit owners,
  customer/support readiness, success/rollback triggers, and final go/no-go
  approvals.

## Constitution Check

No repository `constitution.md` was found. Apply the existing approved
constraints in
[clarification.md](../.github/modernize/rearchitecture/clarification.md) and
the current product/deployment documentation:

| Principle | Check |
|---|---|
| Preserve current contracts and tenant isolation | Pass: every implementation task must include tenant-bound authorization and relevant regression coverage. |
| Additive, data-preserving changes | Pass: use reviewed migrations; do not rewrite or discard paid financial history. |
| Fail closed for money, access, and privileged network operations | Pass: browser returns/callbacks alone never confirm payment; RADIUS remains opt-in until verified. |
| Secrets stay out of source, logs, artifacts, and reports | Pass: use environment/secret-management flows; never read `.env` values for this plan. |
| Production and hardware operations require separate authorization | Pass: this plan is not deployment or router authorization. |
| Evidence-based targets | Pass: derive SLOs, supported hardware, recovery objectives, and launch scope from measurements and approvals. |
| Worktree safety | Pass: keep existing local changes; establish a clean reviewed release candidate without destructive Git operations. |

**Gate result:** plan-level pass subject to the constraints above. Recheck
before implementation and go-live.

## Applied Guidelines

No framework migration/transformation is being planned, so no
language-to-language migration guideline applies. Use the repository's Laravel
patterns and existing CI/test conventions. The implementation must follow
Laravel additive migrations, tenant-scoped queries, existing service
boundaries, fail-closed payment verification, and the project's no-production
testing boundary.

## Decisions required before execution

These are business/deployment decisions, not safe assumptions for an
implementation agent. Record decisions in a reviewed product decision record
before the dependent plan items proceed.

1. **Launch scope:** Is the initial commercial launch limited to standard
   voucher/customer payment and operator subscriptions, or does it include
   PPPoE, RADIUS, VPN/WireGuard, withdrawals, SMS, and all currently surfaced
   modules? An unaccepted capability must be disabled and excluded from launch
   claims.
2. **Subscription product:** Which plans/prices/currency/tax treatment apply?
   Is renewal manual or recurring? What are trial length, grace duration,
   access restrictions, cancellation, refund, notice, and support policies?
3. **Payment provider/account:** Which provider and merchant account are
   approved for first launch? Does the account support subscription payments
   on the same merchant balance as voucher collections? How are fee, settlement,
   refunds, reversals, and disputes handled?
4. **Tenant routing:** Is production to use wildcard subdomains or path-based
   tenancy? Which DNS owner, certificates, domain, and email-sending domain are
   available?
5. **Operations:** Who owns production incidents, payment reconciliation,
   tenant invoice review, database restoration, security notifications, and
   after-hours support?
6. **Availability and recovery:** What measured service baselines and agreed
   business recovery objectives should drive eventual SLOs, RPO, and RTO?
7. **RADIUS decision:** Is RADIUS explicitly out of initial launch? If included,
   which exact RouterOS models/versions, network topology, and authorized lab
   hardware will be supported?

See [open decisions and evidence needed](./production-readiness/decisions.md).

## Implementation Steps

### Phase 1 — Setup: establish a controlled release candidate

#### 1.1 Inventory and preserve the current worktree

- **Requirements:** REQ-PROD-001, REQ-PROD-010
- **Design inputs:** Current Git state; linked audit; existing CI workflow.
- **Description:** Assign the existing uncommitted changes to their authors,
  review ownership and intended scope, and identify the exact proposed release
  commit. Resolve changes through normal reviewed branches/PRs. Do not reset,
  discard, or deploy the developer worktree. Inventory every public route,
  admin feature, payment path, scheduled job, and optional router/network
  capability; label each launch-critical, explicitly excluded/disabled, or
  not yet accepted. Reconcile stale/conflicting readiness documents.
- **Exit:** One reproducible candidate and an explicit list of included changes,
  migrations, and known deviations.

#### 1.2 Validate the integrated candidate

- **Requirements:** REQ-PROD-001, REQ-PROD-005, REQ-PROD-007, REQ-PROD-010
- **Design inputs:** `.github/workflows/ci.yml`, `composer.json`,
  `package.json`, PHPUnit and Playwright suites.
- **Description:** First capture the exact failing workflow run, commit SHA,
  job logs, environment, and failure summary. Fix Pint formatting/style
  failures; fix SQLite failures; fix MySQL-specific failures; remediate
  Composer advisories without unsafe broad upgrades; and run npm audit
  independently so a Composer failure cannot skip it. Then run PHP syntax,
  full PHPUnit, Playwright, Vite production build, both dependency audits, and
  CI's SQLite/MySQL/PostgreSQL jobs on the same candidate. Investigate rather
  than waive failures, warnings, skips, or engine differences. Ensure branch
  protection requires intended checks.
- **Exit:** All required CI jobs pass on the same commit; exact commands,
  results, and remaining gaps are recorded.

### Phase 2 — Foundational: commercial contract and tenant lifecycle

#### 2.1 Approve subscription and launch policy

- **Requirements:** REQ-PROD-004, REQ-PROD-012
- **Design inputs:** `docs/subscription-billing-local.md`,
  platform plan settings, billing UI, clarification decisions.
- **Description:** Resolve the subscription product, selected provider/account,
  manual versus recurring renewal, trial/grace/expiry behavior, cancellation,
  notices, refund/dispute handling, and launch scope. Update user-facing
  terms/help text and operator/admin runbooks after approval.
- **Exit:** Product and finance decisions are written down, reviewed, and
  trace to acceptance tests and operational procedures.

#### 2.2 Enforce plan/trial expiry consistently

- **Requirements:** REQ-PROD-002
- **Design inputs:** `app/Services/Policy/Policy.php`,
  `routes/console.php`, `app/Models/Tenant.php`, `config/platform_control_settings.php`.
- **Description:** Define one canonical effective-entitlement rule. Implement
  idempotent scheduled transitions for expired trials and subscriptions,
  including approved grace periods; update authorization policy so expired
  `active` tenants cannot bypass expiry merely because the status transition
  has not run yet. Renewals must extend from the correct existing entitlement
  end and remain safe when repeated or concurrent.
- **Exit:** Policy and scheduler agree at all time boundaries; renewal,
  expiry, suspension, and restoration preserve tenant data and produce audit
  evidence.

#### 2.3 Add platform invoice review and recovery

- **Requirements:** REQ-PROD-003, REQ-PROD-006, REQ-PROD-010
- **Design inputs:** `app/Services/Subscriptions/SubscriptionBilling.php`,
  `app/Http/Controllers/Platform/BillingController.php`,
  `app/Models/AuditLog.php`, subscription tables and current platform-admin
  MFA/authorization patterns.
- **Description:** Implement a tenant-bound, platform-admin-only review queue
  for open/review invoices and ambiguous attempts. Show only necessary
  provider/reference evidence; allow explicit, audited actions for verified
  paid, confirmed unpaid/closed, refund/credit, or continued review. Require
  recent MFA and reason capture for money/entitlement changes. Add safe
  recovery for submission timeouts without blindly recharging or replaying.
  Add bounded scheduled re-verification of existing attempts and alerts for
  aged unresolved invoices; reconciliation must never submit a new charge.
- **Exit:** Every unresolved state has a documented and tested safe action;
  duplicate action, cross-tenant access, stale provider state, and plan-change
  races cannot grant duplicate entitlements.

#### 2.4 Make subscription history tamper-evident operationally

- **Requirements:** REQ-PROD-003, REQ-PROD-006, REQ-PROD-010
- **Design inputs:** `subscription_history`, existing audit log conventions,
  database-engine capabilities.
- **Description:** Define the immutable evidence required for quotes,
  submission, provider observations, settlement, entitlement change, admin
  resolution, refund, and reversal. Restrict application write paths and add
  actor/time/reason/correlation fields where needed. Choose a database-enforced
  append-only mechanism only if portability and support burden are acceptable;
  otherwise enforce append-only service policy and audit external privileged
  access.
- **Exit:** Financial state changes have a complete, queryable audit trail and
  tests prove normal application roles cannot rewrite or erase past events.

### Phase 3 — User story: safe payment collection and financial operations

#### 3.1 Document and verify selected provider contract

- **Requirements:** REQ-PROD-004, REQ-PROD-005
- **Design inputs:** `app/Services/Subscriptions/ActiveVoucherSubscriptionGateway.php`,
  `app/Services/Payments/*Gateway.php`,
  `app/Services/Subscriptions/SubscriptionGatewayContext.php`.
- **Description:** For the selected provider/account, obtain authorized
  sandbox credentials and provider documentation/response evidence. Record
  exact reference semantics, amount units, currency, status mapping, webhook
  signature contract, idempotency, timeouts, reversal/refund behavior,
  settlement, and configuration/environment identity. Amend adapters only
  where proven contract deviations require it.
- **Exit:** Evidence from real sandbox responses agrees with strict adapter
  normalization; no assumptions based only on mocked fixtures remain.

#### 3.2 Exercise adversarial payment flows

- **Requirements:** REQ-PROD-003, REQ-PROD-005
- **Design inputs:** `tests/Feature/ActiveVoucherSubscriptionGatewayTest.php`,
  `tests/Feature/SubscriptionBillingTest.php`,
  `tests/Feature/MobileMoneyPaymentFlowTest.php`,
  `tests/Feature/CriticalFinancialSecurityRegressionTest.php`.
- **Description:** Add/run cases for pending/success/failure/reversal,
  duplicate/out-of-order callbacks, invalid signature, replay, timeouts,
  wrong reference/tenant/provider account/amount/currency, malformed status,
  provider downtime, and config/key rotation between start and verification.
  Verify return URLs and callbacks cannot settle an invoice without an
  authenticated provider lookup matching the exact local attempt.
- **Exit:** Only the strict verified state grants entitlement; ambiguous states
  stay unresolved and visible; no automatic second submission occurs.

#### 3.3 Reconcile settlements, refunds, and reversals

- **Requirements:** REQ-PROD-006
- **Design inputs:** `app/Console/Commands/ReconcilePendingPayments.php`,
  payment ledger models/services, subscription history, provider settlement
  exports/API.
- **Description:** Define and implement a periodic reconciliation report for
  provider settlements against subscription invoices, voucher sales, refunds,
  reversals, and withdrawals. Add actionable discrepancy states, tenant/provider
  scoping, secure report access, and escalation/ownership. Do not conflate an
  application ledger with provider/bank settlement.
- **Exit:** Finance can identify and investigate every unmatched amount and
  status; no sensitive payment data is unnecessarily exposed.

#### 3.4 Close paid-but-not-provisioned and refund policy gaps

- **Requirements:** REQ-PROD-003, REQ-PROD-006
- **Design inputs:** `docs/payment-fulfillment-durability.md`,
  `app/Services/SaleFulfillmentService.php`,
  `app/Services/ProvisioningWatchdog.php`, refund services and transaction
  models.
- **Description:** Define what customers/operators see for exhausted
  fulfillment, notification/escalation to support, safe authorized replay,
  package edits/deletion after purchase, and refund/credit when access is not
  delivered or a payment is reversed. Add tested, tenant-scoped tools and
  reporting; never blindly reset durable work or replay historical sales.
- **Exit:** Paid sale, voucher/access state, support escalation, reversal and
  refund states are distinguishable, recoverable, and auditable.

### Phase 4 — User story: production database correctness and recoverability

#### 4.1 Validate PostgreSQL migration and concurrency behavior

- **Requirements:** REQ-PROD-001, REQ-PROD-007
- **Design inputs:** `phpunit.ci-db.xml`, PostgreSQL CI job,
  `database/migrations/*subscription*`, financial locking/idempotency services.
- **Description:** Run the exact release candidate on a disposable PostgreSQL
  instance matching production. Verify clean install and upgrade migrations,
  indexes/constraints, repeat deployment, tenant scoping, concurrent checkout,
  duplicate callbacks, simultaneous reconciliation, renewal/expiry races, and
  safe forward recovery. Resolve SQLite-only behavior instead of assuming
  portability.
- **Exit:** PostgreSQL test tier passes against the candidate and concurrency
  tests prove one invoice/charge/entitlement per approved idempotency key.

#### 4.2 Prove backups and restoration

- **Requirements:** REQ-PROD-008
- **Design inputs:** `render.yaml`, provider database backup policy, migration
  history, key-management procedures.
- **Description:** Confirm actual backup retention, encryption, access, and
  restore points with the deployment owner. Restore a backup into an isolated
  database, verify migrations and representative tenant/payment records,
  validate application connectivity, measure recovery steps, and record
  encryption/key dependencies. Document database rollback versus
  forward-fix decisions.
- **Exit:** A responsible operator witnesses and signs off an isolated restore
  rehearsal; no destructive data rollback is assumed.

### Phase 5 — User story: deployment security and operations

#### 5.1 Validate runtime configuration and deployment image

- **Requirements:** REQ-PROD-009, REQ-PROD-010, REQ-PROD-012
- **Design inputs:** `render.yaml`, `Dockerfile.render`,
  `scripts/preflight.sh`, `scripts/00-laravel-deploy.sh`,
  `docs/production-env.example`.
- **Description:** Validate the blueprint and final image; verify shared stable
  APP_KEY, production/debug settings, database/Redis, secure cookies,
  wildcard/path tenant domain requirements, exact proxy trust, provider secret
  references, file/storage behavior, migration startup ordering, and least
  privilege. Upgrade preflight from warn to fail for required production
  settings where safe. Do not print secret values.
- **Exit:** Repeatable deployment starts with expected safe settings and
  refuses missing critical configuration.

#### 5.2 Prove tenant-domain and email delivery

- **Requirements:** REQ-PROD-009, REQ-PROD-012
- **Design inputs:** tenant URL generation, registration/password reset/MFA
  flows, `render.yaml`, `scripts/preflight.sh`.
- **Description:** Obtain production domain/DNS/TLS ownership and validate
  wildcard tenant routing (or approved path mode), canonical URLs, cookies,
  mail SPF/DKIM/DMARC and bounce handling as applicable. Test registration
  verification, password reset, MFA, invitations, invoices, and operations
  alerts to controlled mailboxes.
- **Exit:** Required customer and operator email journeys deliver correctly
  from the production-like deployment, with sensitive links handled safely.

#### 5.3 Exercise monitoring and operational alerts

- **Requirements:** REQ-PROD-009, REQ-PROD-012
- **Design inputs:** `app/Http/Controllers/HealthController.php`,
  `app/Services/SystemHealthService.php`, `routes/console.php`,
  Render health-check configuration.
- **Description:** Configure independent external liveness/readiness probes,
  scheduler heartbeat, queue worker heartbeat if asynchronous queues are
  enabled, DB/cache health, provider reconciliation lag, failed fulfillment,
  stale payment/invoice review, storage, and mail delivery alerts. Trigger
  controlled failures in staging and verify the correct alert and recovery
  signal.
- **Exit:** Operators can detect web, scheduler, provider, database/cache, and
  fulfillment failures independently of the affected process.

#### 5.4 Complete access, secrets, privacy, and incident controls

- **Requirements:** REQ-PROD-010
- **Design inputs:** existing MFA and role middleware, secret encryption,
  audit log, logging config, data-retention commands/docs.
- **Description:** Review platform/tenant role boundaries, MFA enforcement,
  recovery codes, key rotation/re-encryption, provider webhook secrets,
  trusted proxies, log redaction, data retention/deletion, customer-data
  access, dependency/image scanning, vulnerability response, incident
  escalation, and admin account recovery. Test high-impact operations and
  ensure production secrets remain in managed secret configuration.
- **Exit:** Security owner accepts a documented access/rotation/retention/
  incident checklist and tests show privileged actions are authorized,
  audited, and tenant-scoped.

#### 5.5 Define support and operational runbooks

- **Requirements:** REQ-PROD-006, REQ-PROD-008, REQ-PROD-009, REQ-PROD-012
- **Design inputs:** current operational commands, health docs, payment/
  fulfillment reconciliation, admin support workflows.
- **Description:** Write concise operator runbooks for payment mismatch,
  pending invoice, paid/no-access, provider outage, refund/reversal, tenant
  expiry/renewal, restore, failed deployment, credential rotation, and
  security incident. Identify authorized operator, escalation, evidence,
  customer communication, and forbidden ad-hoc data mutation for each case.
- **Exit:** On-call/support staff can follow tested recovery procedures without
  reading secrets or making unreviewed database edits.

### Phase 6 — Capability gate: router and RADIUS (only if in launch scope)

#### 6.1 Reconcile access contract and close known races

- **Requirements:** REQ-PROD-011
- **Design inputs:** `.github/modernize/rearchitecture/phase2-6-checklist.md`,
  `app/Services/Radius*`, router model/migrations, security gate reports.
- **Description:** Product/architecture must confirm the canonical opt-in
  selector and preserve default behavior. Implement atomic pending device
  capacity reservation and safe revocation across backend changes; prove
  simultaneous admissions, mode switch, rollback, and retries on PostgreSQL.
- **Exit:** Prior release-gate selector/capacity/revocation blockers are closed
  by tests and an updated architecture/security review.

#### 6.2 Complete isolated network and hardware acceptance

- **Requirements:** REQ-PROD-011
- **Design inputs:** `docs/RADIUS-POLICY-CHANNEL.md`,
  `docs/RADIUS-CHR-LAB.md`, `docs/HARDWARE-MATRIX.md`,
  `.github/modernize/rearchitecture/phase2-6-checklist.md`.
- **Description:** In an authorized isolated environment, verify private
  policy reachability, CIDR allowlists, TLS/proxy topology, credential
  rotation, WireGuard identity/isolation, FreeRADIUS behavior, payment-to-
  access, session accounting/disconnect, failure recovery, device limits, and
  rollback for each exact model/RouterOS release. Record evidence and retain
  the legacy/default path where required.
- **Exit:** Only validated hardware/software combinations are enabled or
  represented as supported. This task does not authorize production router
  changes.

### Phase 7 — Final acceptance and staged launch

#### 7.1 Run complete pre-production acceptance

- **Requirements:** REQ-PROD-001 through REQ-PROD-012
- **Design inputs:** All previous phase exit evidence, CI workflow, product
  decisions, operations/security approvals.
- **Description:** Execute full regression, browser journey, PostgreSQL
  integration/concurrency, provider sandbox, backup restore, deployment
  preflight, email, monitoring/alert, privacy/security, and only-in-scope
  router acceptance on the exact release commit. Review all skips and
  unsupported capabilities.
- **Exit:** Each requirement has evidence; no P0 blocker remains; all P1
  items required for the chosen launch scope are accepted by named owners.

#### 7.2 Approve, canary, monitor, and expand

- **Requirements:** REQ-PROD-012
- **Design inputs:** Release checklist, support roster, rollback and incident
  runbooks, observed pre-production baselines.
- **Description:** Obtain written product, engineering, security, finance,
  and operations approval for the exact release, provider/account, tenant
  cohort, enabled capabilities, support coverage, and rollback trigger. Keep
  subscription checkout disabled until separate live enablement approval.
  Start with a limited cohort, monitor payment-to-entitlement, settlement,
  fulfillment, errors, and support volume; pause/rollback on pre-agreed
  criteria, and expand only after the agreed observation period.
- **Exit:** Explicit go/no-go record and a stable, reconciled first cohort.

## Task Breakdown

Task IDs are execution references; `[P]` indicates work that can proceed in
parallel after its phase prerequisites. A task marked P is not permission to
change production.

### Phase 1 — Release candidate

- [ ] T001 [Plan:1.1] Inventory the existing modified/untracked worktree, assign owners, identify the exact release candidate, and record the disposition in `docs/production-readiness/release-candidate.md` without discarding changes.
- [ ] T002 [Plan:1.1] Review modified subscription/MFA code, `routes/web.php`, `routes/api.php`, `routes/console.php`, migrations, tests, and docs; label launch-critical, disabled/excluded, or unaccepted capabilities and reconcile stale readiness claims in `docs/production-readiness/release-candidate.md`.
- [x] T003 [Plan:1.2] Capture failing run 45982911074, head/merge SHAs, and Pint/database/dependency-audit job evidence; identify the stale assertions and reconcile against older run 45774107231.
- [x] T004 [Plan:1.2] Format the seven Pint-reported PHP files and verify `vendor/bin/pint --test` passes.
- [ ] T005 [Plan:1.2] Correct the confirmed billing, SweetAlert-layout, and public-assets assertions; the full SQLite suite now passes (766 tests, 6,150 assertions, 2 skipped). Run the full MySQL suite and record a passing result; local verification is currently blocked by Docker Desktop startup failure.
- [ ] T006 [Plan:1.2] The lockfile now uses patched Symfony dependencies and both `composer audit --locked` and `npm audit --omit=dev --audit-level=high` pass locally. Rerun the complete CI workflow (PHP lint/style, PHPUnit SQLite/MySQL/PostgreSQL, Playwright, Vite build, static checks, dependency audits) and verify GitHub branch protection requires all checks.

### Phase 2 — Product contract and lifecycle

- [ ] T007 [Plan:2.1] Record approved launch scope, subscription prices/currency, manual/recurring renewal, trial/grace/expiry, cancellation, notices, refunds, support, and provider/account decisions in `docs/production-readiness/decisions.md`.
- [ ] T008 [Plan:2.1] Update `docs/subscription-billing-local.md` and the operator-facing terms/help content to match the approved commercial policy; keep recurring charges disabled unless separately approved.
- [ ] T009 [Plan:2.2] Implement one canonical tenant entitlement/expiry rule in `app/Services/Policy/Policy.php` and the relevant tenant subscription service, including active-but-expired terms and approved grace behavior.
- [ ] T010 [Plan:2.2] Add an idempotent expired-trial/subscription scheduler command and schedule it in `routes/console.php`; ensure retries cannot extend or suspend the wrong tenant.
- [ ] T011 [Plan:2.2] Add focused feature tests for active-but-expired, trial expiry, grace boundary, renewal, suspension, repeat execution, timezone boundary, and concurrent entitlement changes.
- [ ] T012 [Plan:2.3] Add platform-admin invoice review routes/controller/view under `routes/web.php`, `app/Http/Controllers/Platform/`, and `resources/views/platform/` with strict tenant/payment lookup, role authorization, recent MFA, rate limits, and no secret-bearing provider payload display.
- [ ] T013 [Plan:2.3,2.4] Add an additive migration under `database/migrations/` and implement idempotent audited invoice-resolution actions in `app/Services/Subscriptions/SubscriptionBilling.php` with actor, reason, timestamp, provider reference, and resulting entitlement/refund state.
- [ ] T014 [Plan:2.3] Add bounded scheduled re-verification in `app/Console/Commands/` and `routes/console.php` plus alerts for existing unknown/pending attempts; require review where no authoritative result exists and prohibit new charge submission.
- [ ] T015 [Plan:2.3,2.4] Add feature tests for cross-tenant denial, MFA/role enforcement, duplicate resolution, stale provider data, plan change race, audit completeness, and no duplicate entitlement.

### Phase 3 — Payments and finance operations

- [ ] T016 [Plan:3.1] Record the selected gateway's verified sandbox contract and account/environment identity in `docs/production-readiness/decisions.md`; do not store credentials in the document.
- [ ] T017 [P] [Plan:3.2] Extend `tests/Feature/ActiveVoucherSubscriptionGatewayTest.php` and `tests/Feature/SubscriptionBillingTest.php` for authenticated status envelopes, exact reference/amount/currency matching, terminal states, malformed responses, and provider downtime.
- [ ] T018 [P] [Plan:3.2] Extend `tests/Feature/MobileMoneyPaymentFlowTest.php`, `tests/Feature/SubscriptionBillingTest.php`, and `tests/Feature/CriticalFinancialSecurityRegressionTest.php` for invalid signatures, replay, duplicates, out-of-order states, config rotation, ambiguous timeout, wrong tenant/account, and reversal.
- [ ] T019 [Plan:3.2] Run an authorized sandbox matrix against the selected provider and capture sanitized collection/status/webhook/duplicate/failure/reversal evidence in `docs/production-readiness/provider-sandbox-evidence.md`.
- [ ] T020 [Plan:3.3] Implement a tenant/provider-scoped settlement reconciliation service and command under `app/Services/Payments/` and `app/Console/Commands/` for subscriptions, voucher sales, payouts/withdrawals, refunds, and reversals; include discrepancy ownership and safe export.
- [ ] T021 [Plan:3.3] Add feature tests under `tests/Feature/` for settlement mismatch, missing provider rows, duplicate settlement records, refunds/reversals, access control, and sensitive-data minimization.
- [ ] T022 [Plan:3.4] Implement paid-but-not-provisioned alert/escalation and an authorized safe recovery flow using `app/Services/SaleFulfillmentService.php` and the existing durable fulfillment work model.
- [ ] T023 [Plan:3.4] Document and test package change/deletion, fulfillment exhaustion, customer support, refund/credit, and charge-reversal procedures without replaying historic sales blindly.

### Phase 4 — PostgreSQL and recovery

- [ ] T024 [Plan:4.1] Provision an isolated disposable PostgreSQL instance matching production version and run clean-install plus upgrade-path migrations for the candidate using the database CI configuration in `phpunit.ci-db.xml`.
- [ ] T025 [Plan:4.1] Add/run PostgreSQL tests for checkout idempotency, concurrent callback/reconciliation, invoice locks, plan/expiry races, duplicate entitlements, FK/index constraints, and retry-safe migrations.
- [ ] T026 [Plan:4.1] Resolve all SQLite-versus-PostgreSQL differences and verify the candidate's PostgreSQL CI tier passes without touching production.
- [ ] T027 [Plan:4.2] Confirm database backup retention/encryption/access with the deployment owner and document the restore/key dependencies.
- [ ] T028 [Plan:4.2] Restore a backup into an isolated environment, verify schema and representative tenant/payment records, run application integrity checks, and record measured recovery steps.
- [ ] T029 [Plan:4.2] Write and rehearse the release migration recovery procedure, including forward-fix boundaries and prohibition on destructive rollback of financial history.

### Phase 5 — Runtime and operations

- [ ] T030 [Plan:5.1] Validate `render.yaml`, `Dockerfile.render`, and the final image; reconcile web `/up` versus external `/health/ready` probes and verify scheduler service requirements.
- [ ] T031 [Plan:5.1,5.4] Review `scripts/preflight.sh` and deployment startup checks; make critical production misconfiguration fail deployment without exposing environment values.
- [ ] T032 [Plan:5.2] Verify selected domain mode, wildcard DNS/TLS or path routing, tenant URL/cookie behavior, and trusted-proxy forwarded-header behavior on a production-like deployment.
- [ ] T033 [Plan:5.2] Configure approved SMTP and verify controlled registration, verification, password reset, MFA, invitation, invoice, and alert delivery; strengthen preflight to reject required but non-delivering mail configuration.
- [ ] T034 [P] [Plan:5.3] Configure independent external probes and alert rules for readiness, scheduler/queue heartbeat, DB/cache, payment lag, stale invoices, fulfillment exhaustion, and mail failure; execute controlled alert tests.
- [ ] T035 [P] [Plan:5.4] Review admin/tenant authorization, MFA recovery, secret rotation, webhook controls, log redaction, privacy/retention, dependency/image scanning, and incident response; record security-owner sign-off.
- [ ] T036 [Plan:5.5] Write and tabletop the payment, fulfillment, tenant billing, provider outage, refund, restore, failed deploy, credential rotation, and security incident runbooks.

### Phase 6 — Optional router/RADIUS capability

- [ ] T037 [Plan:6.1] Obtain architecture/product decision on `access_mode` versus `access_backend` and align schema, code, tests, and docs while preserving the approved default.
- [ ] T038 [Plan:6.1] Implement atomic pending capacity reservations and backend-switch-safe revocation; add PostgreSQL concurrency and rollback/retry tests.
- [ ] T039 [Plan:6.2] Validate private policy channel, source CIDR, TLS/proxy, WireGuard identity, FreeRADIUS contract, secret rotation, and network isolation in the authorized lab.
- [ ] T040 [Plan:6.2] Execute CHR and exact physical model/RouterOS acceptance for login, limits, payment-to-access, accounting, expiry/disconnect, restart/retry, and rollback; update `docs/HARDWARE-MATRIX.md`.

### Phase 7 — Go/no-go

- [ ] T041 [Plan:7.1] Assemble one release evidence index with exact commit, CI outputs, sandbox results, PostgreSQL/concurrency results, backup restore, email, monitoring, security, and in-scope router results.
- [ ] T042 [Plan:7.1] Confirm every P0 and launch-scope P1 finding is closed or explicitly blocks launch; record accountable owner and evidence for each.
- [ ] T043 [Plan:7.2] Obtain written product, engineering, finance, security, and operations approval for provider/account, cohort, live switch, support coverage, monitoring, and rollback triggers.
- [ ] T044 [Plan:7.2] Execute the approved limited-cohort rollout, monitor agreed payment/access/support metrics, reconcile transactions, and pause/rollback on agreed thresholds before expansion.

## Dependencies and sequencing

1. T001–T006 establish and clear the reported CI failures before feature
   implementation. If any of these tasks fails, stop and do not proceed to a
   production enablement or go-live task.
2. T007 must precede T008–T023; commercial/payment decisions are required
   before implementation and sandbox acceptance.
3. T009–T011 define entitlement semantics and can proceed after T007.
4. T012–T015 depend on the subscription lifecycle/state model in T009 and
   provider contracts from T016; design can begin earlier, but safe resolution
   semantics must be approved before merging.
5. T016 precedes T017–T020; T019 requires provider-approved sandbox access.
6. T024–T026 require a disposable PostgreSQL environment; T025 validates
   concurrency of lifecycle and payment changes before live acceptance.
7. T027–T029 require deployment-owner access to backup policy and isolated
   restore destination.
8. T030–T036 require a production-like environment and named operations owner.
9. T037–T040 run only if RADIUS is in the approved launch scope. Otherwise
   record RADIUS as disabled/out-of-scope and require no claim of support.
10. T041–T044 depend on all applicable prior gates and separate written
    authorization; none is permission to infer approval from this plan.

## Testing Strategy

- **appType:** Multi-tenant server-rendered Laravel application with JSON APIs,
  webhooks, payment integrations, scheduled jobs, and optional router/RADIUS
  network services.
- **Critical user journeys:**
  1. Tenant registers, verifies email, signs in with MFA where required, and
     accesses only its own workspace.
  2. Tenant reviews a quoted subscription plan, explicitly consents, starts
     payment, and is entitled only after authenticated provider verification.
  3. Expired/trial/past-due tenant is restricted according to approved policy;
     payment/renewal restores the correct entitlement without duplication.
  4. Ambiguous/failed/reversed payment is not granted as paid and can be
     investigated/resolved by a platform admin with MFA and an audit record.
  5. Customer purchases a voucher, provider confirms, durable fulfillment
     provisions access, and payment/access status remains truthful across
     retries and router/provider failures.
  6. Operations detects scheduler/provider/database degradation, restores a
     database backup, and follows a tested recovery/rollback runbook.
  7. If RADIUS is in scope: isolated router login, policy, accounting, expiry,
     disconnect, failure recovery, and rollback pass on each declared target.
- **Primary validation stack:** Existing PHPUnit suite, Playwright, npm/Vite
  build, Pint/PHP syntax, Composer/npm audit, and CI database matrix
  (SQLite/MySQL/PostgreSQL). Authorized provider sandbox and isolated CHR/
  physical acceptance are separate integration evidence, not mocked unit
  tests.
- **Fallback matrix:**
  - Database: PostgreSQL 16 matching Render as primary; SQLite is fast local
    feedback only and does not replace PostgreSQL locking/migration evidence.
  - Browser: Playwright E2E primary; unit/feature tests are not a substitute
    for customer journey browser coverage.
  - Router: isolated CHR then specifically approved hardware; mocks do not
    establish RouterOS compatibility.
  - Provider: authenticated sandbox primary; fakes are for deterministic
    regressions and do not establish settlement or provider contract.
- **Environment requirements:** No `.env` access for planning; use documented
  sanitized test settings. Disposable PostgreSQL/MySQL, Redis, Node/npm,
  PHP/Composer, Playwright browser, provider sandbox credentials from approved
  secret storage, production-like DNS/TLS/mail/proxy staging, and authorized
  isolated router lab only for relevant scope.
- **Known gaps:** Provider sandbox access, provider settlement contract,
  Render trust boundary, wildcard DNS/TLS, SMTP delivery, backup restore,
  production proxy behavior, physical router test, and current full-tree test
  evidence are not established by this plan's source audit.
- **Test data:** Isolate tenants and payment references per test; use
  disposable DBs and provider sandbox identifiers; never use real customer
  PII or production payment/database data in tests. Preserve test evidence
  sanitized of secrets and phone numbers.
- **Acceptance criteria:** All applicable CI checks pass on the same release
  commit; PostgreSQL and concurrency checks pass; no payment grants from
  unverified input; subscription lifecycle is enforced; all unresolved money
  states have authorized recovery; restore and alert drills pass; supported
  router claims match physical acceptance; product and operational approvals
  are recorded.
- **Validation review expectations:** Confirm exact candidate commit, no
  unexplained skips, tenant isolation, independent financial reconciliation,
  audit completeness, correct environment switch defaults, successful
  recovery, approved launch scope, and that live enablement/deployment actions
  were separately authorized.

## Project Structure

```text
app/
  Console/Commands/              # subscription lifecycle and reconciliation jobs
  Http/Controllers/Platform/     # invoice review and finance operations
  Services/Subscriptions/        # entitlement, recovery, and gateway boundary
  Services/Payments/             # settlement/refund/reconciliation integration
  Services/Policy/               # canonical tenant entitlement policy
database/migrations/             # additive subscription/audit schema changes
tests/Feature/                   # billing, lifecycle, payment, operations tests
tests/e2e/                       # Playwright customer/admin journeys
docs/
  PRODUCTION-READINESS-AUDIT-2026-11-27.md
  PRODUCTION-IMPLEMENTATION-PLAN.md
  production-readiness/
    decisions.md                 # approved policy decisions and evidence
    runbooks/                    # production operator procedures
```

## Requirement Mapping

| REQ ID | Description | Plan Items | Implementation Evidence |
|---|---|---|---|
| REQ-PROD-001 | One reviewed, fully validated candidate | 1.1, 1.2, 4.1, 7.1 | Release candidate review, `.github/workflows/ci.yml`, full test/build/audit evidence |
| REQ-PROD-002 | Correct tenant subscription lifecycle | 2.1, 2.2 | `app/Services/Policy/Policy.php`, subscription lifecycle command/service, scheduler, focused feature tests |
| REQ-PROD-003 | Safe payment recovery and admin resolution | 2.3, 2.4, 3.2, 3.4 | Platform billing controller/views, `SubscriptionBilling`, `subscription_history`, tests for resolution and fulfillment |
| REQ-PROD-004 | Approved subscription commercial policy | 2.1, 3.1 | `docs/production-readiness/decisions.md`, updated billing disclosures and checkout tests |
| REQ-PROD-005 | Verified provider contract and payment settlement | 3.1, 3.2 | Selected `app/Services/Subscriptions/*Gateway.php`, provider contract evidence, sandbox results |
| REQ-PROD-006 | Financial reconciliation and support operations | 2.3, 2.4, 3.3, 3.4, 5.5 | Reconciliation report/command, payment audit history, finance and support runbooks |
| REQ-PROD-007 | PostgreSQL correctness and concurrency | 1.2, 4.1 | PostgreSQL CI job, `phpunit.ci-db.xml`, migration and concurrency tests |
| REQ-PROD-008 | Verified backup restore and release recovery | 4.2, 5.5 | Restore evidence, database recovery runbook, witnessed rehearsal |
| REQ-PROD-009 | Runtime configuration and operational monitoring | 5.1, 5.2, 5.3 | `render.yaml`, `scripts/preflight.sh`, health/readiness probes, mail and alert evidence |
| REQ-PROD-010 | Security, privacy, access, secrets, incident controls | 1.1, 1.2, 2.3, 2.4, 5.1, 5.4 | Authorization/MFA tests, secret/log review, retention and incident controls, security approval |
| REQ-PROD-011 | Explicitly accepted router/RADIUS scope | 6.1, 6.2 | Canonical selector, capacity/revocation tests, RADIUS policy docs, `docs/HARDWARE-MATRIX.md` acceptance |
| REQ-PROD-012 | Approved and monitored staged launch | 2.1, 5.1, 5.2, 5.3, 5.5, 7.1, 7.2 | Product/finance/engineering/security/operations approvals, canary metrics and rollback evidence |

## Go-live checklist (must be completed for selected scope)

- [ ] Exact release commit reviewed; worktree not used as deployment source.
- [ ] Required CI checks and dependency/security scans pass.
- [ ] No known P0 finding remains; launch-scope P1 items have evidence.
- [ ] Subscription pricing, renewal, grace, cancellation, refund and notices are approved.
- [ ] Selected provider/account passes sandbox verification and reconciliation.
- [ ] Subscription expiry and invoice review/recovery workflows pass.
- [ ] PostgreSQL migration/concurrency validation and isolated backup restore pass.
- [ ] Production-like DNS/TLS, proxy, SMTP, secrets, scheduler, readiness,
  alerts, and logs are verified.
- [ ] Support, finance reconciliation, incident, restore, and rollback owners are named.
- [ ] RADIUS is either explicitly excluded/disabled, or its separate gate passes.
- [ ] Product, engineering, security, finance, and operations sign off exact scope.
- [ ] Live subscription checkout and any production rollout have separate explicit approval.

