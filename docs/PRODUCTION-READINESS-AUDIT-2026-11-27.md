# Production-readiness audit and implementation plan

**Assessment date:** 2026-11-27
**Scope:** Current repository snapshot, with emphasis on customer payments, SaaS
tenant subscriptions, tenant lifecycle, production operations, and the router
access path. This is a repository-evidence audit, not a certification or a
claim that the system is ready to accept production payments.
**Implementation plan:** [Production Readiness Implementation Plan](./PRODUCTION-IMPLEMENTATION-PLAN.md)

## Executive decision

**Do not declare production readiness or enable live tenant-subscription
checkout yet.** The repository has substantial working foundations: tenant
scoping, three customer-payment gateway adapters, provider-status
reconciliation, a separate subscription invoice/history ledger, a default-off
subscription checkout switch, MFA controls, PostgreSQL-oriented deployment
configuration, and CI definitions for SQLite, MySQL, PostgreSQL, assets,
static checks, dependency audits, and browser tests.

Those foundations do not close the launch gates. The current worktree contains
many uncommitted changes, including the subscription flow and MFA work, and
there is no current full validation result for that exact tree. Static review
also identified a tenant-expiry enforcement gap. Subscription exception and
ambiguous-payment paths can leave invoices unresolved, while the repository's
own billing notes identify the missing human resolution, refund/reversal, and
recovery operations. Provider sandbox/settlement evidence, production database
recovery, live email/proxy/monitoring configuration, and physical router
acceptance are not established by the repository evidence reviewed here.
GitHub Actions confirms current CI failures in Pint, SQLite, MySQL, PostgreSQL,
and dependency auditing; see PR-14. The diagnosed formatting, stale test
assertions, dependency advisories, and local verification status are recorded
in the [implementation plan](./PRODUCTION-IMPLEMENTATION-PLAN.md). Keep CI as
the first release blocker until the full workflow passes on one candidate SHA.

The dated Phase 1 gate in
[t21-teamlead.md](../.github/modernize/rearchitecture/artifacts/t21-teamlead.md)
is **FAIL** and predates much of the current worktree. Some later task reports
record specific remediations, but they do not constitute a fresh release-gate
assessment of the current working tree or clear the remaining gates below.

## Evidence boundary

- The working tree was already substantially modified before this audit. Do
  not deploy those changes as a release candidate until they are reviewed,
  integrated, and validated together.
- No application test, build, provider call, database connection/migration,
  deployment, or router operation was performed for this audit.
- `.env` was not opened, read, searched, copied, or printed. Git metadata
  indicates it is not tracked; this does not establish that its credentials
  are valid, safe, unexposed elsewhere, or suitable for production.
- The repo-level assessment skill's supported project types do not include
  this PHP/Laravel application; this report is based on repository evidence.
- The clean dependency audit in
  [t22.4-backend.md](../.github/modernize/rearchitecture/artifacts/t22.4-backend.md)
  is prior evidence, not a fresh scan of this release candidate.

## Findings

| ID | Priority | Finding | Evidence and production impact |
|---|---|---|---|
| PR-01 | **P0 — launch blocker** | Subscription expiry does not automatically make an `active` tenant past due. | `Policy::applicableStates()` only adds `past_due` when `subscription_status` is already `past_due`; it only checks `subscription_ends_at` in that branch. The current scheduler defines no subscription-expiry command. A tenant left `active` after its end date can therefore continue to pass lifecycle policy checks. Add an explicit, idempotent expiry transition and entitlement enforcement. See [Policy.php](../app/Services/Policy/Policy.php) and [console.php](../routes/console.php). |
| PR-02 | **P0 — launch blocker** | Subscription billing is implemented in the current worktree but has no fresh integrated release evidence. | The worktree includes changes to subscription services, routes, controllers, UI, migrations, tests, and MFA. Prior targeted artifacts may cover earlier revisions; this audit did not run the current full suite or PostgreSQL integration tier. Treat the current changes as unreleased until a clean PR/release candidate passes the complete gates in this plan. |
| PR-03 | **P0 — launch blocker** | Subscription gateway operation is not proven against a provider sandbox or settlement account. | `ActiveVoucherSubscriptionGateway` reuses configured voucher collection providers and requires authenticated lookups with matching reference, amount, and UGX status. The billing documentation says tests use mocked responses and explicitly distinguishes application accounting from provider settlement separation. Checkout defaults off. Obtain authorized sandbox evidence for the selected provider/account, callback behavior, status vocabulary, amount/currency/reference binding, retries, duplicate submissions, reversals, and settlement reconciliation before enabling live charges. See [subscription-billing-local.md](./subscription-billing-local.md) and [subscriptions.php](../config/subscriptions.php). |
| PR-04 | **P0 — launch blocker** | Failed, ambiguous, or out-of-policy subscription payments can require manual resolution, but no complete platform-admin invoice resolution workflow was found. | The service intentionally blocks another invoice while an invoice is `open` or `review`, and the billing notes list the administrator review process and safe retry recovery as remaining work. An unresolved invoice can therefore strand a tenant in billing. Build an authorized, audited review/resolve workflow with provider evidence, immutable decisions, and safe retry/refund handling before a broad launch. See [SubscriptionBilling.php](../app/Services/Subscriptions/SubscriptionBilling.php) and [subscription-billing-local.md](./subscription-billing-local.md). |
| PR-05 | **P0 — launch blocker** | Subscription commercial behavior is intentionally manual and has no automatic renewal. | The current implementation sets `auto_renew=false`; the billing notes say renewals are manual and no recurring charge is part of the flow. Confirm this matches the offer, customer consent, notice, cancellation, grace-period, and suspension policy. If automatic renewal is required, it is a separate payment-token/mandate, consent, retry, cancellation, and dispute workstream—not a configuration toggle. |
| PR-06 | **P0 for RADIUS rollout; otherwise scoped rollout blocker** | Router/RADIUS production acceptance is not established, and the access-mode contract discrepancy remains in earlier gate evidence. | The Phase 1 gate reports `access_mode=radius` versus implementation `access_backend=radius`, a capacity race, a router-mode transition/revocation risk, and absent target-engine concurrency evidence. Current source still uses `access_backend`. Later readiness docs explicitly say physical RouterOS/RADIUS acceptance and the production network design remain pending. Do not enable a RADIUS path or claim general router support until the canonical contract, concurrency/revocation behavior, target firmware, and isolated hardware acceptance are signed off. See [phase2-6-checklist.md](../.github/modernize/rearchitecture/phase2-6-checklist.md), [PRODUCTION-READINESS.md](./PRODUCTION-READINESS.md), and [HARDWARE-MATRIX.md](./HARDWARE-MATRIX.md). |
| PR-07 | **P0 — launch blocker** | Production database migration, concurrency, backup, and restore readiness is unproven for the current release candidate. | Render is configured for PostgreSQL, and CI defines a PostgreSQL 16 job, but the prior release gate says target-engine locking/concurrency evidence was missing. The deployment blueprint itself requires backup-retention review and a restore rehearsal before taking payments. Run the current migrations and relevant locking/idempotency tests on an isolated PostgreSQL instance; prove backup restoration and a safe rollback/forward-fix procedure. Never use a production database for this validation. See [render.yaml](../render.yaml) and [ci.yml](../.github/workflows/ci.yml). |
| PR-08 | **P1 — required before public launch** | Readiness monitoring is configured in the app but not fully established at the deployment boundary. | The app exposes `/health/ready`, while Render's web health check is `/up`; the blueprint comments call for an external monitor to poll readiness and detect scheduler outages. No external monitor configuration or live scheduler heartbeat proof is present in the repository. Verify the deployed readiness route, scheduler heartbeat, alert delivery, and independent outage detection. |
| PR-09 | **P1 — required before public launch** | Email and network trust are deployment prerequisites, not proven production capabilities. | The readiness docs say registration/password-reset email delivery and Render proxy behavior remain unverified. `preflight.sh` warns (rather than fails) when mail is a log/array mailer. Confirm working SMTP delivery for registration verification, password recovery, MFA, and operational notifications; validate exact trusted proxy CIDRs and forwarded-header behavior on the deployed edge. |
| PR-10 | **P1 — required before public launch** | Financial operations beyond collection are incomplete or not evidenced. | The app has collection, payouts/withdrawals, and reconciliation code, but the subscription notes call out refunds/reversals/disputes and ledger audit immutability as remaining work. Define who handles refunds, provider disputes, charge reversals, settlement mismatches, failed fulfillment, and accounting exports; prove ledger reconciliation and administrator audit trails. Do not equate an application invoice with a separately settled merchant balance. |
| PR-11 | **P1 — required before public launch** | The release-control boundary and actual production configuration are unverified. | A Render blueprint and production preflight exist, but this audit cannot verify GitHub branch protection, required CI checks, human approval, production secret provisioning, custom wildcard DNS/TLS, or a rollback drill. Confirm these externally and make production preflight reject missing critical settings (including email and any required tenant-domain mode) rather than merely warn. |
| PR-12 | **P1 — required before broad router rollout** | Physical device compatibility and customer-facing router behavior are untested. | [HARDWARE-MATRIX.md](./HARDWARE-MATRIX.md) lists intended models/versions but says physical tests are not recorded. Mocked tests do not establish captive portal behavior, RouterOS API effects, RADIUS attributes, accounting, revocation, recovery, or rollback on hardware. Restrict initial claims and rollout to exact versions/models that pass isolated acceptance. |
| PR-13 | **P2 — product/scale gate** | No current evidence establishes agreed availability, load, privacy/retention, or disaster-recovery targets. | Existing docs explicitly defer SLOs until measured baselines exist and state production backup/restore remains unverified. Measure before committing targets; define data retention, incident response, key rotation, support escalation, and recovery objectives with the product owner. |
| PR-14 | **P0 — current release blocker** | CI has not yet been rerun after remediation; no production release until all required checks pass together on one candidate SHA. | Current [GitHub Actions run 45982911074](https://github.com/VU-Kevin-coder/wifi-billing-saas/actions/runs/45982911074) was for PR #76, head `580ec5a17bac1148122c69120a7a2e52db37c8b4` and merge SHA `100c81074bfe69fb6bcbd1ec142aaf92f605d67a`. Pint reported seven style violations, which were formatted and pass local `vendor/bin/pint --test`. SQLite/MySQL/PostgreSQL jobs failed on stale test expectations: an unassigned tenant expected the self-serve change-plan CTA; the SweetAlert test treated the nested MFA partial as a standalone layout; and the public-assets test expected a portal stylesheet on the standalone landing page. Those expectations are corrected; the local SQLite suite passes (766 tests, 6,150 assertions, 2 skipped). Composer reported five Symfony advisories (CVE-2026-133743, CVE-2026-133746, CVE-2026-133751, CVE-2026-133754, CVE-2026-133755); patched Symfony 7.4.24 packages are locked and Composer audit passes. The npm audit now runs even if Composer audit fails and reports zero vulnerabilities locally. Local MySQL execution remains unverified because Docker Desktop became unavailable during disposable service startup. Require a full green CI rerun on the candidate SHA; prior [run 45774107231](https://github.com/VU-Kevin-coder/wifi-billing-saas/actions/runs/45774107231) is older and does not clear this blocker. |

## Prioritized implementation plan

### Gate 0 — Establish a reviewable release candidate

1. Inventory the current local modifications and identify the intended owners;
   preserve unrelated user work and do not overwrite or reset it.
2. Reconcile the current subscription/MFA changes with their focused tests,
   migration history, docs, and route authorization. Resolve conflicts through
   reviewed commits/PRs; do not treat a dirty developer worktree as a deployable
   artifact.
3. Generate one fresh evidence set against that exact candidate:
   PHP lint/style, PHPUnit full suite, Playwright/E2E, Vite production build,
   dependency audits, and CI's SQLite/MySQL/PostgreSQL jobs. Include exact
   commands and counts in the release evidence.
4. Re-run the current security/dependency review after integration. The
   previously recorded dependency scan is not sufficient for a later lockfile
   or source change.

**Exit:** a reviewed, reproducible release candidate with all repository CI
checks passing and no unexplained test skips/failures.

### Gate 1 — Correct tenant subscription lifecycle

1. Add an idempotent scheduled lifecycle action for expired trials and paid
   terms. Decide whether expiry should move immediately to `past_due`, whether
   there is a grace period, and when access is denied; implement one canonical
   rule shared by policy checks, billing UI, and scheduled reconciliation.
2. Add regression tests for active-but-expired, trial expiration, past-due
   grace boundaries, suspended tenants, paid renewal before/after expiry,
   repeated scheduler runs, time zone boundaries, and concurrent renewals.
3. Implement a platform-admin review queue for `open`/`review` invoices:
   tenant/reference context, provider verification evidence, restricted
   resolution actions, reason capture, actor/time audit entries, and safe
   idempotent state transitions.
4. Implement and test a recovery path for unknown submissions without creating
   a second charge. Include provider status checks, explicit operator review,
   and safe resolution for missing/late provider results.
5. Confirm the commercial contract: manual renewal versus recurring renewal,
   plans/prices/currency, trial duration, grace/suspension, cancellation,
   refunds, and notices. Keep automatic renewal disabled unless its consent and
   provider mandate design is separately approved and tested.

**Exit:** tenant access matches billing entitlement at every lifecycle
boundary; unresolved invoices have a documented owner and resolution path.

### Gate 2 — Prove payment collection and financial reconciliation

1. Select the initial live provider and merchant account with product/finance.
   Record the exact sandbox/live mode, collection API contract, settlement
   account, webhook authentication, reference format, amount units, currency,
   status mapping, idempotency behavior, and reversal semantics.
2. Against the authorized provider sandbox, exercise new tenant checkout,
   successful/pending/failed/reversed payments, duplicate callbacks, invalid
   signatures, delayed status, ambiguous timeout, reference/amount/currency
   mismatch, and callback replay. Fulfillment must follow authoritative
   authenticated verification, not the browser return or an unverified
   callback.
3. Verify that provider account/config changes do not cause an old attempt to
   be checked against a different merchant context; prove the stored
   gateway-context guard with rotation/config-change tests.
4. Add daily settlement reconciliation and an operator report comparing
   provider settlement records, subscription invoices, voucher transactions,
   refunds, and withdrawals. Document the fact that application-ledger
   separation does not itself separate bank/provider balances.
5. Define refund, dispute, chargeback/reversal, manual-review, and customer
   support procedures, with append-only audit evidence and authorized roles.
6. Keep `SUBSCRIPTION_CHECKOUT_ENABLED=false` in all environments until Gates
   1–3 pass and a separately approved live enablement checklist is signed.

**Exit:** provider sandbox evidence and internal ledger/settlement
reconciliation pass; finance can explain and resolve every payment state.

### Gate 3 — Validate persistence and recovery on the production engine

1. Use a disposable PostgreSQL instance matching the declared production
   version. Apply migrations from a clean database and from the prior schema;
   verify constraints, indexes, repeated deploy behavior, and forward recovery.
2. Exercise concurrent checkout/idempotency, invoice locks, subscription
   settlement, tenant plan changes, duplicate callbacks, and migration behavior
   using PostgreSQL—not SQLite alone.
3. Prove encrypted backups can be restored into an isolated environment, with
   integrity checks and measured recovery steps. Confirm backup retention,
   access restrictions, encryption/key dependencies, and who can initiate a
   restore.
4. Document deployment migration ordering and rollback boundaries. Do not rely
   on destructive migration rollback where paid invoices/history or existing
   tenants would be lost.

**Exit:** PostgreSQL CI and isolated production-engine rehearsal pass; a
restoration and safe release recovery have been witnessed and recorded.

### Gate 4 — Close deployment and operations prerequisites

1. Validate the Render blueprint and final image; confirm production
   `APP_ENV`, debug-off, stable shared `APP_KEY`, PostgreSQL, Redis-backed
   sessions/cache, secure cookies, rate limiting, exact trusted proxies, tenant
   routing mode, wildcard DNS/TLS, and provider credentials via secret
   management. Never place secret values in this report or source control.
2. Make preflight fail on missing production-critical delivery settings;
   validate real SMTP delivery for tenant registration, password reset, MFA,
   and operational alerts.
3. Configure and exercise independent probes for `/health/ready`, web
   liveness, scheduler heartbeat, database/cache health, and payment
   reconciliation lag. Test alert delivery and stopped-scheduler detection.
4. Verify production logs redact payment/customer secrets and include enough
   correlation IDs for support; define retention and access controls.
5. Confirm branch protection, required CI checks, production approval,
   deployment ownership, change window, rollback trigger, and a tested rollback
   or forward-fix procedure.

**Exit:** production configuration is reviewed without exposing credentials;
monitoring, notifications, recovery ownership, and release approvals work.

### Gate 5 — Approve the router/network service scope

1. Resolve the canonical access-mode contract with the product owner; align
   code, schema, API, tests, and documentation without silently aliasing
   incompatible values.
2. Close and prove the capacity reservation/concurrency and backend-switch
   revocation risks documented by the prior release gate.
3. Complete isolated CHR and physical-router acceptance for each claimed
   RouterOS model/version. Verify captive login, payment-to-access, limits,
   accounting, session expiry/revocation, disconnect, restart/retry behavior,
   and rollback.
4. Verify private RADIUS/policy reachability, source allowlists, TLS/proxy
   topology, credentials/rotation, and failure behavior in the intended
   deployment boundary. Keep RADIUS opt-in until this evidence passes.

**Exit:** only model/firmware combinations with recorded successful evidence
are enabled or marketed as supported.

### Gate 6 — Final go/no-go review

- Re-run all gates on the exact release commit, not a different dirty tree.
- Record open items with an owner, severity, mitigation, and explicit
  launch-impact decision. No P0 may be waived implicitly.
- Review legal/product requirements for terms, privacy, retention, support,
  and customer notices with the responsible owner.
- Obtain explicit product, engineering, security, and operations approval for
  the initial rollout cohort, live provider, monitoring, support coverage, and
  rollback.

**Go-live decision:** only after every P0 and required P1 gate has verifiable
evidence. Roll out to a limited cohort first, monitor payment/access
reconciliation, and expand only after the agreed observation period and
rollback criteria are met.

## Recommended immediate next work

Start with **Gate 0** (reconcile and validate the current worktree), then
implement **PR-01 subscription expiry enforcement** and the **PR-04 admin
invoice review/recovery workflow** before enabling subscription checkout. In
parallel, product/finance should choose the provider and confirm the manual
renewal/refund policy; operations should schedule PostgreSQL and restore
rehearsals. Keep router/RADIUS enablement a separate, evidence-gated rollout.
