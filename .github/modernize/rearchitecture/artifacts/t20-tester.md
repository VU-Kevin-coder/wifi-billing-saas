# t20 - Phase 1 Runtime and Compatibility Validation

**Generated:** 2026-11-14T04:26:56Z
**Target:** `D:\projects\wifi-billing-saas`
**Result:** Repository/SQLite runtime and browser checks passed. Target-database parity and device-capacity concurrency remain unverified; this is not the final Phase 1 release gate.

## Summary

| Step | Status | Exit code | Evidence |
|---|---|---:|---|
| Startup / local runtime | PASS | 0 | Playwright-managed PHP 8.2 server passed its readiness check; page and assets served. The separate t19 smoke also observed `/up` HTTP 200 in 1.71 s. |
| Focused Phase 1 regressions | PASS | 0 | 93 tests, 590 assertions, 0 failed / 0 skipped across migration, site-agent, policy, credential, payment-token, mobile-money, and Pesapal suites. |
| Full PHPUnit suite | PASS | 0 | 564 tests, 3,062 assertions, 0 failed / 0 skipped using the existing `phpunit.xml` SQLite `:memory:` test target. |
| Browser E2E | PASS | 0 | Playwright 1.65.1: 1 passed, 0 failed; pending portal purchase, header-only token transport, no voucher/active-access claim. |
| Frontend asset build | PASS | 0 | `npm run e2e` built with Vite 6.4.5 (69 modules transformed); CSS/JS assets returned HTTP 200. t19 separately verified all Vite entrypoints. |
| PHP 8.2 dependency compatibility | PASS | 0 | `composer check-platform-reqs --lock --no-interaction --no-plugins`; all locked PHP 8.2.12 platform requirements passed. Installed Laravel: 12.72.0. |
| Docker-based target DB validation | UNAVAILABLE | 1 | `docker info` could not connect to `npipe:////./pipe/dockerDesktopLinuxEngine`; supported production DB engine is also still unknown. No engine-specific migrations/locking tests were attempted. |

## Runtime and Compatibility Evidence

### Environment and safety

- PHP CLI 8.2.12; Laravel 12.72.0; Composer 2.10.2; Node.js 24.15.0; npm 11.12.1.
- `composer check-platform-reqs --lock --no-interaction --no-plugins` - passed; no target-version downgrade or ignored platform requirement.
- `npx --no-install playwright --version` - 1.65.1. `npm run e2e` proved the local browser binary and browser tier are operational.
- Docker CLI 29.9.3 exists, but daemon check failed: `failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine; check if the path is correct and if the daemon is running: open //./pipe/dockerDesktopLinuxEngine: The system cannot find the file specified.`
- Infra and browser capabilities were evaluated independently. Docker unavailability did not prevent Playwright execution.
- No `.env` or other secret file/value was opened, printed, searched, copied, or processed. No live provider, production DB, deployment, router, Docker Compose lab, CHR, FreeRADIUS, or WireGuard operation occurred.
- PHPUnit uses SQLite `:memory:`. The Playwright setup's `migrate:fresh` was constrained to its guarded disposable SQLite file under `storage/framework/testing/`; no default or production database was accessed.

### Isolated PHPUnit bootstrap

To prevent Laravel's normal PHPUnit application bootstrap from implicitly looking for repository environment files, `tests/TestCase.php` now sets a random, nonexistent temporary environment/config-cache path before kernel bootstrap, disables environment-file loading, supplies a synthetic test-only `APP_KEY`, clears `DB_URL`, and forces SQLite `:memory:` plus local array/sync test drivers. This is test harness code only; no application source, API contract, migration, production configuration, or parallel configuration file was changed.

The canonical `php artisan test --do-not-cache-result` / `composer test` command was not used because Artisan boots Laravel before PHPUnit and could load a repository environment file. Its PHPUnit suite was run directly with the same `phpunit.xml`, and the Playwright suite was run separately. This was the safe equivalent under the no-environment-file constraint.

## Test Results

- Focused command: `vendor/bin/phpunit --configuration=phpunit.xml --no-coverage --do-not-cache-result tests/Feature/Phase1DomainMigrationTest.php tests/Feature/SiteAgentControlPlaneTest.php tests/Feature/RadiusPolicyBoundaryTest.php tests/Feature/RouterRadiusCredentialDeliveryTest.php tests/Feature/PaymentTokenTransportTest.php tests/Feature/MobileMoneyPaymentFlowTest.php tests/Feature/PesapalPaymentTest.php`
  - Passed: 93; failed: 0; skipped: 0; assertions: 590; exit code: 0.
- Full suite: `vendor/bin/phpunit --configuration=phpunit.xml --no-coverage --do-not-cache-result`
  - Passed: 564; failed: 0; skipped: 0; assertions: 3,062; exit code: 0.
- Browser: `npm run e2e`
  - Passed: 1; failed: 0; skipped: 0; exit code: 0. The portal remained pending; `GET /api/payments/status` returned 202 with `pending`; the payment token was in `X-Payment-Token`, not the URL; no voucher or active access was claimed. CSS/JS requests returned 200.
- Post-format test-bootstrap recheck: `vendor/bin/phpunit --configuration=phpunit.xml --no-coverage --do-not-cache-result tests/Feature/Phase1DomainMigrationTest.php tests/Feature/RouterRadiusCredentialDeliveryTest.php` - 9 passed, 58 assertions, exit code 0.
- `php -l tests/TestCase.php` passed. Pint's first check reported a line-ending-only issue; formatting was applied, then `vendor/bin/pint --test tests/TestCase.php` and `git diff --check -- tests/TestCase.php` passed.
- The full integration suite and Playwright journey exercise repository-local test doubles/fixtures. No live payment-provider or live RADIUS/router behavior is claimed.

## Per-Capability Verdict

```text
environment:
  docker: UNAVAILABLE - docker info exit 1; Docker Desktop Linux engine named pipe is missing/not running.
  node: AVAILABLE - Node.js v24.15.0.
  playwright: AVAILABLE - @playwright/test 1.65.1; npm run e2e passed using the installed browser.
  infra-tier: FALLBACK(embedded/in-memory) - PHPUnit SQLite :memory: and guarded disposable E2E SQLite file; Docker daemon unavailable and production DB engine unknown.
  browser-tier: PRIMARY(Playwright) - independently exercised; not downgraded because Docker is unavailable.
startup: PASS - `php -S 127.0.0.1:8123 -t public tests/e2e/router.php` under isolated test environment; Playwright readiness and portal/API requests succeeded. t19 separately recorded `/up` HTTP 200 in 1.71 s.
integration: PASS - exit_code: 0, passed: 564, failed: 0, skipped: 0; full Laravel test suite on SQLite :memory:. Target-engine DDL, FK, locking, and concurrency fidelity are unverified.
e2e: PASS - exit_code: 0, passed: 1, failed: 0; pending-payment portal, status response, token-header transport, and absence of voucher/active-access claims. Successful live-provider fulfillment and router browsing are outside this run.
overall: NEEDS_SIGNOFF - repository/SQLite and browser evidence pass, but supported target DB parity, F-04 concurrency capacity enforcement, and selector naming need resolution before operational/lab readiness claims.
```

## Open Issues and Exact Phase 2 Prerequisites

1. **Target DB compatibility:** identify and approve the supported production DB engine/version, then run migrations, constraints/FKs, locking, and concurrency checks against a separate disposable instance of that engine. Production/default DB access remains prohibited.
2. **Device-limit race (t17 F-04, MEDIUM):** implement an atomic pending-device reservation or verified adapter-side pre-Accept capacity enforcement, then add concurrent authorization tests. Current passing SQLite tests do not establish this property.
3. **Canonical access selector:** reconcile user/task wording `access_mode=radius` with the reviewed implementation/artifacts' `access_backend=radius` and default `site_agent`; update the release contract/test wording only after architect confirmation. This report does not assume equivalence.
4. **Private service deployment boundary:** before enabling any service, verify private ingress, trusted direct-peer CIDRs, TLS/proxy configuration, shared nonce cache, and actual reachability in the approved environment. t18.1 confirms code guards but not deployment behavior.
5. **Payment provider contract:** obtain authoritative Airtel response-field guarantees, especially whether amount/currency echoes may be absent; no live provider call was made.
6. **Credential recovery:** define a separately authorized secret rotation/revocation process for a lost one-time router credential; do not introduce a retrieval path.
7. **Approved later lab:** the approved local Docker Compose + CHR 7.24.6 target requires a functional local Docker daemon and a separately isolated lab configuration. No lab, RADIUS, WireGuard, router, or customer traffic was started in Phase 1.
8. **Operational baseline:** measure existing latency, throughput, availability, and recovery before rollout; define SLOs only from those measurements. No numeric SLO is invented here.

## Issues Found

| # | Severity | Description | Owner / status |
|---|---|---|---|
| 1 | MEDIUM | t17 F-04 pending-session capacity race remains open; this task did not claim device-limit parity. | Backend/architect; unresolved. |
| 2 | BLOCKER | Docker daemon unavailable and production DB engine unknown; target-engine migration/locking evidence absent. | DBA/platform; unresolved without environment/engine confirmation. |
| 3 | WARNING | `access_mode` vs `access_backend` naming mismatch remains unconfirmed. | Architect/coordinator; clarification needed. |
| 4 | WARNING | Provider omitted-field guarantee, production reachability/cache/TLS, and credential-loss rotation procedure remain unverified. | Payment/platform/security owners; prerequisites listed above. |

No new runtime test failure was found. Security review t18.1 reported zero confirmed exploitable findings and zero HIGH/CRITICAL vulnerabilities; that review is upstream evidence, not a new security audit by t20.

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/clarification.md` - immutable stack, test DB, preserved contracts/site-agent behavior, and no production/Phase 2 actions.
- `.github/modernize/rearchitecture/board.md` - read-only task sequencing and Phase 1 gate boundary.
- `.github/modernize/rearchitecture/artifacts/t7-teamlead-plan.md` - approved runtime strategy, critical journeys, test tiers, and capability fallback matrix.
- `.github/modernize/rearchitecture/artifacts/t17-architect.md` - architecture conformance findings, especially F-04 and target DB/private-channel limits.
- `.github/modernize/rearchitecture/artifacts/t18.1-security.md` - security re-audit, selector discrepancy, provider-field uncertainty, and operational prerequisites.
- `.github/modernize/rearchitecture/artifacts/t19-architect.md` - independent build/startup evidence and exact local `/up` smoke result.
- `.github/modernize/rearchitecture/artifacts/t14-tester.md` - earlier isolated migration/domain tests and safety constraints.
- `.github/modernize/rearchitecture/artifacts/t15-tester.md` - isolated Playwright setup, browser scope, and environment-file isolation pattern.

`context.md` and `decisions.md` were not present at the expected base paths. The clarification, board, and supplied dependency artifacts were used as controlling context.

## Evidence Mapping

- `clarification.md#Backend` and `#Generic` - immutable Laravel 12/PHP 8.2 compatibility, SQLite-only test permission, no production DB/router/deployment/Phase 2 actions; verified by Composer platform checks and isolated test results above.
- `t7-teamlead-plan.md#Testing Strategy/Primary Validation Stack` and `#Capability Probe and Fallback Matrix` - full PHPUnit, Playwright, build/startup, and independent Docker/browser evidence recorded in this report.
- `t17-architect.md#F-04` / `#Operational and Release Prerequisites` - passing test scope is bounded; device-capacity race and production DB/private reachability remain explicitly open.
- `t18.1-security.md#F-03` and `#F-04` / `#Phase 1 Gate Implications` - Airtel response uncertainty, `access_mode`/`access_backend` mismatch, and deployment prerequisites are retained without inferring equivalence or production readiness.
- `t19-architect.md#Build and Startup Evidence` - independent Laravel `/up` 200 and local build evidence; combined with current t20 Playwright server startup and page/API runtime results.
- `t14-tester.md#Test Results` / `t15-tester.md#Test Results` - prior migration and browser validation baselines; t20 reran the current full PHPUnit suite and Playwright journey after later changes.
