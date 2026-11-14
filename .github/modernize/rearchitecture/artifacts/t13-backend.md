# t13 — Optional RADIUS Backend and Payment-Safe Domain Work

## Status

**Task complete; the Phase 1 release gate remains blocked on later independent evidence.** The explicitly approved opt-in activation path now recognizes only routers whose `access_backend` is `radius`, validates the router's NAS identity and encrypted shared secret, and creates a `provisioning` session without sending a site-agent command. Site-agent-required behavior remains unchanged for `site_agent` routers. An explicit per-router rollback to `site_agent` can resume an existing pending session by queueing one missing provisioning command; it does not create another voucher/session or re-run an existing command.

## Summary

- Added Laravel-side router credential encryption/serialization protection and a credential-generation service. The secret is returned by that service call only; no authorized operator-facing one-time delivery workflow was added.
- Added four private internal RADIUS policy operations with a separate request-integrity credential, signed method/path/body/timestamp/nonce, replay rejection, no-store responses, and production HTTPS/shared-cache checks. Production network restrictions, TLS configuration, and cache operation remain unverified.
- Added tenant/router-scoped authorization and normalized accounting ingestion, generation-aware NAS session evidence, monotonic cumulative counter reconciliation, quarantine behavior, and a durable disconnect outbox. Delivery acknowledgements are not treated as disconnect confirmation; unconfirmed commands are retried until terminal accounting evidence closes the session.
- Added a typed sale-to-voucher relation and tenant/package/amount checks inside the existing locked payment fulfillment path. Legacy transaction metadata remains a fallback; no provider, wallet, API, or sales-term contract was changed.
- Added narrow `VoucherActivationService` dispatch: default/agent routers keep the paired-agent requirement and command lifecycle; explicitly configured RADIUS routers require a complete configuration and remain pending until accounting confirms activity. Unsupported backend values and incomplete RADIUS setup fail closed.
- Added explicit rollback recovery for an existing pending session after that router is switched back to `site_agent`: enqueue only when a paired agent exists and no `provision_user` command already exists. Pending revocation/device-ban sessions are not resumed.
- Kept the site-agent requirement and existing site-agent flow unchanged. Existing public API route files were not edited.
- No production database connection, standalone migration, infrastructure setup, deployment, or router operation was performed. The full PHPUnit suite used the approved SQLite `:memory:` test configuration.

## Deliverables

- [t13-tasks-to-impl.yaml](./checkpoints/t13-tasks-to-impl.yaml) — task-to-implementation traceability and gate blockers.

## Release Blockers and Residual Risks

1. **Secret delivery is incomplete.** The credential service can generate and return a secret once, but no authorized protected operator workflow currently invokes it.
2. **Production boundary is unverified.** TLS/private-network restrictions and production shared-cache configuration have not been established. The production database engine is unknown; migration execution and engine-specific locking/DDL validation remain blocked.
3. **Phase 1 release evidence remains incomplete.** This task does not prove lab end-to-end access, production operator authorization, or production rollback readiness. Stop at the Phase 1 release gate; no Phase 2, infrastructure, deployment, or router work is authorized.
4. The full-suite `git diff --check` was scoped to the two implementation files and passed; pre-existing whitespace warnings in concurrently modified `project-profile.yaml` and `board.md` remain outside this task.

## Test Results

- Focused command: `php artisan test tests/Feature/RadiusPolicyBoundaryTest.php --do-not-cache-result`
- Focused result: **8 passed, 0 failed, 0 skipped; 63 assertions.** Coverage includes all four internal endpoints through the Laravel runtime, credential encryption/serialization, HMAC tampering/replay, tenant/entitlement authorization, legacy active-session rejection without RADIUS evidence, accounting replay/order/counters/Stop, disconnect delivery retry and truthful status, and payment fulfillment replay.
- Activation regression command: `php artisan test tests/Feature/SiteAgentControlPlaneTest.php --do-not-cache-result`
- Activation regression result: **18 passed, 0 failed, 0 skipped; 159 assertions.** Covers default `site_agent`, opt-in RADIUS portal redemption remaining `provisioning` with no agent command, explicit same-router rollback of the existing pending session to one agent command without duplicating voucher/session, and incomplete RADIUS configuration failing closed without entitlement mutation.
- Full command: `php artisan test --do-not-cache-result`
- Fresh pre-change baseline: **548 passed, 0 failed, 0 skipped; 2,973 assertions; exit code 0.**
- Final full result: **550 passed, 0 failed, 0 skipped; 2,991 assertions; exit code 0; 84.38 seconds.**
- PHP syntax checks: `php -l app/Services/VoucherActivationService.php` and `php -l tests/Feature/SiteAgentControlPlaneTest.php` passed.
- Formatting and whitespace: Pint `--test` passed on both changed files; `git diff --check` passed for both changed files.
- The existing portal redeem route was exercised by the RADIUS regression test; no public API route or request/response contract was changed. No standalone migration or non-test database operation was run.

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/artifacts/t10-architect.md` — approved API boundary, router-derived tenancy, accounting identity, disconnect truthfulness, and phase limits.
- `.github/modernize/rearchitecture/artifacts/t11-security.md` — credential separation, request integrity/replay controls, least privilege, secret handling, and security verification limits.
- `.github/modernize/rearchitecture/artifacts/t12-dba.md` — additive router/sale/accounting schema assumptions and SQLite-only test posture.
- `.github/modernize/rearchitecture/clarification.md` — preserved contracts/terms, private API, approval boundaries, and stop-at-Phase-1 requirement.
- `.github/modernize/rearchitecture/artifacts/t7-teamlead-plan.md` — task mapping, requirements, existing fulfillment seams, and release-gate requirements.
- `.github/modernize/rearchitecture/artifacts/t1-architect.md` — default site-agent compatibility, fail-closed router selection, and truthful RADIUS pending state.
- `.github/modernize/rearchitecture/artifacts/t2-backend.md` — current activation seam and legacy command lifecycle.
- `.github/modernize/rearchitecture/team/backend/inbox.md` — coordinator/architect/DBA direction; read-only.
- `.github/modernize/rearchitecture/learnings/backend/current-voucher-fulfillment-seam.md` — existing payment/activation seam and legacy site-agent constraints.

## Evidence Mapping

- `t10-architect.md#Adapter and identity boundary` and `#Internal API contract` → router-derived tenant authorization, private signed policy routes, and minimal adapter responses; exercised by `RadiusPolicyBoundaryTest::test_all_private_policy_endpoints_return_expected_runtime_responses` and signature/replay tests.
- `t10-architect.md#Accounting, lifecycle, and recovery policy` → generation-scoped idempotent accounting, cumulative counters, truthful Stop/disconnect evidence, and retryable outbox; exercised by accounting and disconnect tests.
- `t11-security.md#Approved credential boundaries` and `#Required implementation/review checks` → separate service/router credentials, encrypted hidden router secret, request integrity and nonce replay denial; production TLS/private-network/operator workflow remain explicitly unverified.
- `t12-dba.md#Schema notes` and `#Residual Risks / Handoff` → code targets the additive schema and avoids raw credentials/accounting packets; tests are limited to the approved in-memory SQLite environment.
- `t1-architect.md#Backend selection and identity` and `#Frozen compatibility surface` → only explicit valid router mode selects RADIUS; existing site-agent defaults and command semantics remain unchanged.
- `t2-backend.md#Current access fulfillment path` → activation remains inside the existing transaction; the new pending-session rollback dispatch reuses the established idempotent site-agent command service.
- `t7-teamlead-plan.md#Step 2.1` and `#Step 3.1` → per-router activation, explicit rollback, and payment-safe typed linkage are covered by the final regression/full-suite evidence above.
- `clarification.md#Backend` and `#Generic` → existing API/access terms and agent requirement remain unchanged, no production/Phase 2 actions occurred, and work stops at the blocked Phase 1 gate.

