# t21 — Phase 1 Binary Release Gate

**Generated:** 2026-11-14 (UTC)  
**Target:** `D:\projects\wifi-billing-saas`  
**Verdict:** **FAIL — Phase 1 release criteria are not met.** Stop here; no Phase 2 work was started, and this gate makes no Phase 2 readiness claim.

## Current status update — 2026-11-15

The original review below is retained as historical evidence. Subsequent
implementation has addressed the selector mismatch, durable device-capacity
reservations, and session-snapshot revocation routing, but the Phase 1 gate
remains **FAIL** until target-engine and lab evidence is complete.

- PostgreSQL engine family: user confirmed Render-managed PostgreSQL; the
  production major version has not been independently confirmed from authorized
  service configuration.
- PostgreSQL 16 attempt: the first disposable-suite attempt did not provide
  valid PostgreSQL evidence. PHPUnit normalized the XML `true` flag to `1`,
  while the test bootstrap required the literal string `"true"` and therefore
  selected SQLite. The bootstrap and concurrency guard now use
  `FILTER_VALIDATE_BOOLEAN`. A host diagnostic confirms the PostgreSQL driver
  path is selected, but host PHP has no `pdo_pgsql`.
- Docker/lab: Docker 29.9.3 responds; the pre-existing local CHR 7.24.6
  container is running and its API/SSH ports answer on loopback. The existing
  Compose network is `172.18.0.0/16` and does not overlap the host routes
  observed during this session. Docker returned `read-only file system` when
  asked to create a disposable PostgreSQL container/network, so target-engine
  tests could not be rerun. No existing container was stopped, modified, or
  pruned. CHR licensing and actual RADIUS/WireGuard behavior remain unverified.
- Local regression: `php artisan test` exited 0 with 2,805 assertions and 515
  test-environment warnings; `vendor/bin/pint --test` and `git diff --check`
  passed. `npm run e2e` passed 1 browser test and built Vite assets.
- Private policy-channel documentation describes the required allowlist, TLS,
  HMAC, nonce, and shared-cache controls, but deployment topology and actual
  private reachability are still unverified.
- The old Airtel-status-contract prerequisite is obsolete after replacing the
  payment providers. Verify ObPay, PawaPay, and Xente provider response
  contracts during the Phase 5 payment-flow gate; no live payment was made.

The unresolved Phase 1 release blockers are a green disposable PostgreSQL run
including the two-process concurrency test, confirmation of the target
PostgreSQL major version, writable Docker storage for isolated lab/database
validation, and private-channel deployment evidence. The credential recovery
boundary is documented in `docs/ROTATION-RUNBOOK.md`; automated repeatable
credential rotation is not implemented.

## Current decision

Phase 1 remains **FAIL**. The selector, reservation, and session-revocation
implementation remediations are present and local regression checks pass.
However, a valid PostgreSQL run including the concurrency test, the target
PostgreSQL major-version confirmation, and isolated lab/private-channel evidence
are still missing. Do not start Phase 2 until these gates pass.

## Gate Criteria

| Criterion | Result | Evidence / disposition |
|---|---|---|
| Laravel 12 / PHP 8.2 and repository dependency/build compatibility | PASS at repository scope | t19 reports PHP 8.2.12, Laravel 12.72.0, Composer locked platform check/install and Vite production build passed. No target-version downgrade or ignored platform requirement. |
| Existing site-agent default, public API/payment contract, tenant and access-term preservation | PASS for reviewed paths; transition fix has local regression evidence | t17 and t20 report existing routes/contracts and default `site_agent` path preserved. The backend-transition concern from the original review has been addressed with per-session access-mode snapshots; target-engine concurrency evidence remains pending. |
| Explicit opt-in selector matches the user-approved contract | Historical FAIL; implementation remediation present | The canonical application selector is now `access_mode`; the follow-up migration copies legacy `access_backend` values. Phase 1 target-engine migration validation remains outstanding. |
| Focused and complete repository regression tests | PASS | t20: focused 93 passed, 0 failed, 0 skipped; full suite 564 passed, 0 failed, 0 skipped (3,062 assertions), SQLite `:memory:`. |
| Browser and local startup validation | PASS | t20 Playwright 1 passed, 0 failed; local PHP server/readiness passed; CSS/JS requests returned 200. t19 `/up` returned HTTP 200. Browser scope is pending-payment/status/token transport, not successful live-provider fulfillment or router browsing. |
| Build and PHP syntax smoke | PASS at isolated repository scope | t19 `## Smoke Test Verdict`: returncode 0, `covers_all_modules: yes`, startup HTTP 200; locked Composer install and isolated Vite build passed; 338 PHP syntax checks passed. The smoke used `node tests/e2e/build-assets.mjs` with an isolated env directory rather than the canonical `npm run build`; it reports all configured Vite entrypoints built. No secret-bearing environment was read. |
| Disposable migration/domain safety | PASS for SQLite test target only | t14/t20 report isolated SQLite coverage and migration/rollback tests. No default/production DB was connected or migrated. |
| Required target-engine constraints/FK/locking/concurrency verification | FAIL — required evidence missing | Render-managed PostgreSQL is confirmed, but the exact production major version and a valid target-engine run are not. The first test attempt selected SQLite because PHPUnit normalized XML `true` to `1`; the flag parsing is fixed, but Docker now reports a read-only metadata store when starting a disposable PostgreSQL container. SQLite results do not prove engine parity. |
| Device-capacity enforcement under concurrent/pending authorization | Implementation remediation present; PostgreSQL concurrency proof pending | Durable pending device reservations and serialized/current capacity reads are implemented and covered by SQLite tests. The two-process test is included in `phpunit.ci-db.xml`, but a passing PostgreSQL execution has not yet been obtained. |
| Voucher revocation after router switches from active site-agent sessions to RADIUS | Implementation remediation present; target-engine regression pending | Per-session `access_mode` snapshots now route revocation and retries for established sessions. Focused SQLite regressions pass; PostgreSQL validation remains pending. The t21 static finding was not a runtime reproduction. |
| Security severity threshold | Historical review met the threshold; remediations not re-reviewed here | t18.1 reported zero confirmed exploitable findings and zero HIGH/CRITICAL. The t21 review reported MEDIUM findings for capacity and backend-transition behavior; the implementation remediations are described above. No new post-remediation security review was performed for this update. |
| No prohibited operation | PASS | No `.env`/secret access, production/default DB connection/migration, deployment, live payment, or configuration-changing router operation occurred. Only local Docker state, CHR loopback port reachability, and disposable tests were inspected; existing containers were not changed or pruned. |

## Historical Validation Evidence

### Code and security review

- t17 architecture conformance review: default site-agent and reviewed contracts conform; F-04 device-capacity race remains open; production database and private-channel configuration remain unverified.
- t18.1 post-remediation security audit: no confirmed exploitable finding; 0 HIGH / 0 CRITICAL; Airtel response-field guarantees, selector name, deployment boundary, and credential-loss rotation remain unresolved prerequisites.
- t21 specialized read-only security review: one MEDIUM router backend-transition/revocation finding described above. Review did not access secrets, run tests, connect to databases/routers, or change files.

### Smoke and runtime results

- t19 smoke: locked Composer install and PHP 8.2 platform check passed; isolated production Vite build passed; 338 PHP syntax checks passed; local `/up` returned HTTP 200 in 1.71 seconds; smoke verdict returncode 0.
- t20 focused PHPUnit command: `vendor/bin/phpunit --configuration=phpunit.xml --no-coverage --do-not-cache-result tests/Feature/Phase1DomainMigrationTest.php tests/Feature/SiteAgentControlPlaneTest.php tests/Feature/RadiusPolicyBoundaryTest.php tests/Feature/RouterRadiusCredentialDeliveryTest.php tests/Feature/PaymentTokenTransportTest.php tests/Feature/MobileMoneyPaymentFlowTest.php tests/Feature/PesapalPaymentTest.php` — 93 passed, 0 failed, 0 skipped; exit 0.
- t20 full PHPUnit command: `vendor/bin/phpunit --configuration=phpunit.xml --no-coverage --do-not-cache-result` — 564 passed, 0 failed, 0 skipped; 3,062 assertions; exit 0.
- t20 browser command: `npm run e2e` — 1 passed, 0 failed, 0 skipped; exit 0. It verified pending portal state, status response and header-only payment-token transport, not a live payment-provider or router flow.
- t20 Docker probe: `docker info` — exit 1; daemon did not respond at `npipe:////./pipe/dockerDesktopLinuxEngine`. This is a documented infrastructure-axis blocker, not a reason to drop browser validation (Playwright was run independently).
- This gate task executed no additional application tests/build and changed no application source. Evidence above is attributed to the named upstream tasks; the target-engine test tier remains unverified.

## Historical Prerequisites From the 2026-11-14 Review

The list below records what the original gate requested. Use the current status
update at the top and the Phases 2–6 checklist for the current blockers.

1. **Resolve the approved opt-in selector mismatch.** Confirm `access_mode=radius` as specified by the user or obtain an explicit contract decision; align code/schema/tests/docs without changing the `site_agent` default or silently treating the two names as aliases.
2. **Close device-capacity concurrency gap.** Implement an atomic pending-device reservation or verify adapter-side pre-Accept enforcement; test concurrent and pending-session authorizations against package limits.
3. **Close router-mode transition/revocation gap.** Prevent RADIUS switching while site-agent sessions remain active/pending, or preserve the backend of each existing session through revocation; verify the revocation retry path and rollback behavior with regressions.
4. **Identify and approve the supported database engine/version.** Do not infer it from repository defaults or secrets. Obtain a separate disposable, non-production instance and verify additive migration up/repeat/guarded rollback, FKs/constraints, locks, and concurrency. Keep production/default DB access prohibited.
5. **Restore local lab capability only when Phase 2 work is authorized.** Make the local Docker daemon functional and confirm the isolated Compose/CHR 7.24.6 image, licensing and network layout. No Compose lab, FreeRADIUS, CHR, router, or customer traffic was started here.
6. **Verify the private policy boundary in the approved isolated environment.** Prove direct-peer CIDRs, TLS/proxy topology, shared nonce-cache behavior and service reachability; code-level guards alone do not prove deployment behavior. Do not alter production infrastructure.
7. **Historical payment prerequisite — superseded:** Airtel response evidence is no longer applicable after the gateway replacement. Verify ObPay, PawaPay, and Xente provider response contracts in the Phase 5 payment-flow gate.
8. **Define authorized credential recovery/rotation/revocation** for lost one-time router credentials without adding a secret retrieval path.
9. **Measure existing performance/availability/recovery baselines** before defining SLOs; do not invent numeric targets.
10. **After remediation, rerun the relevant focused tests, full suite, isolated migration matrix, smoke/build, browser tier and target-engine concurrency/locking validation.** Preserve all existing contracts and report exact commands, return codes, counts and per-axis gaps.
11. **Respect the Phase 2 boundary.** The chosen local Docker Compose + CHR 7.24.6 target is only a later lab target; this task did not start Phase 2. No live router change or production/deployed environment operation is permitted by this gate.

## Test Results

- Gate-local test/build command: None — read-only evidence review; no application source changes.
- Gate-local passed / failed / skipped: 0 / 0 / 0 (no tests executed directly by t21).
- Upstream current full PHPUnit: 564 / 0 / 0; exit 0 (`t20-tester.md`).
- Upstream focused PHPUnit: 93 / 0 / 0; exit 0 (`t20-tester.md`).
- Upstream Playwright: 1 / 0 / 0; exit 0 (`t20-tester.md`).
- Upstream smoke: exit 0; 338 PHP syntax checks passed; isolated build and local startup passed (`t19-architect.md`).
- Target-engine migration/locking/concurrency: not run; supported DB engine unknown and Docker daemon unavailable. This is a required evidence gap and contributes to the FAIL verdict.

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/artifacts/t20-tester.md` — current SQLite/runtime/browser results and remaining blockers.
- `.github/modernize/rearchitecture/clarification.md` — frozen stack, contracts, selector wording, approved test DB and prohibited operations.
- `.github/modernize/rearchitecture/board.md` — read-only task sequencing and Phase 1 stop boundary.
- `.github/modernize/rearchitecture/artifacts/t7-teamlead-plan.md` — Phase 1 requirements, testing strategy, and explicit binary release criteria.
- `.github/modernize/rearchitecture/artifacts/t3-pm.md` — REQ-001–REQ-012 and user-visible/API/site-agent invariants.
- `.github/modernize/rearchitecture/artifacts/t17-architect.md` — architecture conformance and open F-04 capacity race.
- `.github/modernize/rearchitecture/artifacts/t18.1-security.md` — post-remediation security audit, selector discrepancy and operational prerequisites.
- `.github/modernize/rearchitecture/artifacts/t19-architect.md` — independent build/startup smoke evidence.
- `.github/modernize/rearchitecture/team/teamlead/log.md` — teamlead planning and prior gate context.
- `.github/modernize/rearchitecture/team/teamlead/inbox.md` — routed architecture/security boundary decisions.
- `.github/modernize/rearchitecture/learnings/teamlead/phase1-planning-boundaries.md` — preserve default site-agent behavior; do not infer DB/adapter decisions or use default DB.

`context.md` and `decisions.md` were not found at their expected base paths; the supplied clarification, read-only board, plan, and task artifacts were used as the controlling record.

## Evidence Mapping

- `clarification.md#Backend` and `#Generic` → immutable Laravel/PHP/API/site-agent/tenant boundaries and safe-use limits; validated against the reviewed artifacts and local SQLite-only test reports.
- `t7-teamlead-plan.md#Step 4.1` and `#Step 4.2` → test/build evidence, required target-engine validation, and binary FAIL when required engine evidence is absent.
- `t7-teamlead-plan.md#Release Gate / Validation Review Expectations` → selector/default/tenant/security, no prohibited activity, and explicit stop at Phase 1 reflected in the criteria table and verdict.
- `t3-pm.md#REQ-001–REQ-012` and `#Success Criteria` → compatibility, payment/access-term, replay, agent-state, and regression criteria summarized in the gate matrix.
- `t17-architect.md#F-04` / `#Operational and Release Prerequisites` → open capacity race and DB/private-boundary evidence gaps retained, not inferred as passing.
- `t18.1-security.md#Findings and Residual Risks` / `#Phase 1 Gate Implications` → no HIGH/CRITICAL confirmed; Airtel, selector, deployment and credential-loss uncertainties remain visible.
- `t19-architect.md#Smoke Test Verdict` / `#Build and Startup Evidence` → Composer/Vite/syntax/startup evidence mapped to the smoke criterion.
- `t20-tester.md#Per-Capability Verdict` / `#Open Issues and Exact Phase 2 Prerequisites` → full test/browser pass evidence and Docker/target-engine limitation mapped to the runtime and DB criteria.
- t21 specialized security review result → active site-agent session revocation risk after backend switch is added as an open MEDIUM issue and an explicit remediation prerequisite.
