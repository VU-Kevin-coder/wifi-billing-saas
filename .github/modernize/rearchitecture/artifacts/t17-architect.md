# t17 — Architecture Conformance Review (Remediation Follow-up)

## Verdict

**Conditional conformance; Phase 1 is not release-ready.** The previous F-01 (PHP 8.2 lock mismatch) and F-02 (operator secret delivery missing) are resolved in repository evidence. F-03 is resolved at the application-code level, but production channel configuration/reachability remains an operational prerequisite. F-04 (device-limit race while a session is still `provisioning`) remains open. This review is not the final Phase 1 release gate.

No application source was changed. No `.env` or other secrets were inspected or processed. No database connection/migration, deployment, infrastructure change, router operation, or live cutover was performed.

## Review Scope and Basis

Reviewed the approved clarification and architecture decisions, the original t17 findings, the supplied t12–t16 and t22.1–t22.4 artifacts, and focused current source for router dispatch, payment fulfillment, operator credential delivery, the internal RADIUS policy guard, migrations, and accounting authorization. Review was read-only. User constraints remain Laravel 12/PHP 8.2, Blade/Tailwind, preserved public API and site-agent behavior, tenant isolation, SQLite-only test DB, no production environment or Phase 2 activity.

## Conformance Assessment

| Contract | Current evidence | Assessment |
|---|---|---|
| Explicit router selection; legacy/default routers stay on site-agent | `routers.access_backend` migration default is `site_agent`; `VoucherActivationService` selects RADIUS only for explicit `radius`, rejects unsupported values, and does not enqueue a site-agent command for RADIUS. Existing site-agent path remains separate. | Conforms in reviewed code; t13/t14 regressions cover default behavior and per-router rollback. |
| Preserve API, payment, sales, access terms, and tenant boundaries | New internal routes are separate from public API routes; t13 reports no public route edits. Payment fulfillment continues through the transaction lock, `WalletService`, typed sale link, and idempotent existing voucher path. Router-derived tenant binding remains in the policy service. | Conforms in reviewed code and supplied tests. |
| Secret storage and authorized single-use operator issuance | `Router` encrypts/hides `radius_shared_secret`. New operator POST is inside authenticated `operator_admin` routes, requires recent MFA and throttling, compares tenant slug with resolved tenant, queries router within tenant, and returns a no-store response. Credential service refuses a second issuance and fails if persistence is cancelled. | F-02 CLOSED for initial issuance/disclosure; route-level tests are reported in t22.2. Rotation after loss is still a separately authorized operational procedure, not a retrieval path. |
| Restricted private policy service channel | Middleware requires production CIDR allowlist and directly checks `REMOTE_ADDR`; it rejects missing/invalid/catch-all CIDRs, requires HTTPS, HMAC, timestamp, unique nonce, and an eligible shared cache. Forwarded headers do not satisfy peer allowlisting. | F-03 CLOSED for code controls only. Production CIDR/cache/TLS configuration and actual reachability are not verified; no deployment/enablement claim is made. |
| Accounting lifecycle and device-limit policy | `RadiusAccountingService::sessionMayAuthenticate()` permits eligible `provisioning` sessions before checking open RADIUS generations/device capacity; the existing test verifies the returned `device_limit` value and provisioning state, not concurrent reservation/enforcement before Access-Accept. | F-04 remains open (MEDIUM). Concurrent authorization attempts can be accepted before the first accounting Start makes a session active; an atomic pending reservation or verified adapter-side pre-Accept enforcement plus concurrency tests is required before lab acceptance. |
| Additive/preserving schema and PHP target | Migrations are additive and rollback-guarded; tests use SQLite `:memory:` only. `composer.json` remains PHP `^8.2`/Laravel `^12.0`; t22.1/t22.4 report successful lock platform checks on PHP 8.2.12 and clean dependency audits. | Conforms at repository/SQLite evidence scope. Production database engine and migration behavior remain unverified. |

## Findings and Dispositions

### F-01 — CLOSED: PHP 8.2 locked dependency compatibility

`t22.1-backend.md` records compatible `coding-libs/laravel-mfa` 1.1.6 and `spatie/laravel-permission` 6.25.0 locks; it reports Composer validation, `check-platform-reqs --lock`, and install dry-run passing on PHP 8.2.12 with scripts/plugins disabled. `t22.4-backend.md` reports a clean re-scan. The requested PHP/Laravel target was not changed and platform requirements were not ignored. The package-major change still relies on the reported application regression evidence; it does not establish production runtime compatibility by itself.

### F-02 — CLOSED for initial issuance: protected one-time router credential delivery

The operator route is role-gated, recent-MFA protected, throttled, tenant-constrained, and explicitly confirmed. The secret is returned only in the response with no-store/no-cache/referrer protections; repeat issuance is denied and the model hides/encrypts it. `t22.2-backend.md` reports four isolated HTTP-kernel regressions passing (authorized issuance, repeat denial, role/tenant scope, and persistence rollback). Secret rotation/revocation after loss remains an operational prerequisite; the workflow deliberately does not retrieve an existing secret.

### F-03 — CLOSED in code; operational prerequisite remains

`t22.3-backend.md` reports fail-closed production CIDR enforcement and 13 isolated policy-boundary tests passing, including spoofed-forwarded-address denial, invalid/missing production allowlist denial, HTTPS/HMAC, and replay rejection. The source check supplements, but cannot prove, private deployment reachability. Production `RADIUS_POLICY_ALLOWED_SOURCE_CIDRS`, TLS/proxy configuration, supported shared nonce cache, and direct-peer reachability must be set and verified before enabling the adapter.

### F-04 — OPEN, MEDIUM: no atomic capacity reservation while provisioning

`RadiusAccountingService::sessionMayAuthenticate()` returns `true` for a `provisioning` session before checking current open accounting sessions. The existing authorization regression asserts `device_limit` is returned and the session stays `provisioning`; it does not establish that simultaneous logins cannot exceed the package limit before a Start event. Add durable/atomic pending-device reservations or provide verified adapter policy that enforces capacity before Access-Accept, and add a concurrent/pending-session test. Do not claim device-limit parity until verified.

## Operational and Release Prerequisites (Not Repository Findings)

- Production database engine/version is still unknown. No production/default DB connection or migration is authorized; SQLite evidence does not establish production DDL/FK/locking behavior.
- Production private-channel CIDR, TLS/proxy posture, shared nonce cache, and actual service reachability remain unverified.
- The approved local Docker Compose + CHR 7.24.6 lab is later-phase only; no lab, FreeRADIUS deployment/configuration, or router setup occurred in this review.
- Per-router secret recovery requires a separate authorized rotation process; no credential retrieval mechanism is present.
- Device-limit behavior above remains unverified/open for lab acceptance.
- Based on the board, security re-review and smoke/startup tasks remain in progress; runtime validation and the binary release gate remain pending. This task does not complete or substitute for them.

## Test Results

- Command executed: None — read-only architecture review; no app/runtime command was run, avoiding any chance of environment-file processing.
- Passed in this task: 0
- Failed in this task: 0
- Skipped in this task: application test/build execution (not run).
- Upstream evidence only: t22.1 reports Composer PHP 8.2 lock validation and dry-run passed; t22.2 reports 4/4 focused credential delivery tests passed; t22.3 reports 13/13 focused policy-boundary tests passed; t22.4 reports Composer/npm audits and lock platform checks clear; t14 reports 555 PHPUnit tests passed and t15 reports one Playwright journey passed before the t22 remediation changes. These are not test runs performed by this review.

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/clarification.md` — approved stack, contract, test DB, and environment/phase constraints.
- `.github/modernize/rearchitecture/board.md` — read-only progress and remaining Phase 1 gate sequencing.
- `.github/modernize/rearchitecture/artifacts/t1-architect.md` — per-router routing, payment/access separation, contract preservation, and phase boundary.
- `.github/modernize/rearchitecture/artifacts/t10-architect.md` — private policy API, identity, secret lifecycle, accounting truthfulness, and device policy.
- `.github/modernize/rearchitecture/artifacts/t12-dba.md` — additive schema and SQLite-only evidence.
- `.github/modernize/rearchitecture/artifacts/t13-backend.md` — current implementation boundaries and preserved behavior.
- `.github/modernize/rearchitecture/artifacts/t14-tester.md` — migration/domain regression evidence.
- `.github/modernize/rearchitecture/artifacts/t15-tester.md` — browser journey evidence and compatibility boundary.
- `.github/modernize/rearchitecture/artifacts/t16-backend.md` — previous dependency findings and scan baseline.
- `.github/modernize/rearchitecture/artifacts/t22.1-backend.md` — PHP 8.2 dependency compatibility remediation.
- `.github/modernize/rearchitecture/artifacts/t22.2-backend.md` — one-time operator credential issuance implementation and isolated tests.
- `.github/modernize/rearchitecture/artifacts/t22.3-backend.md` — fail-closed source CIDR guard and isolated tests.
- `.github/modernize/rearchitecture/artifacts/t22.4-backend.md` — post-remediation dependency scans/platform verification.
- `.github/modernize/rearchitecture/artifacts/t17-architect.md` — initial findings used as the review baseline; this follow-up supersedes their current dispositions.

## Evidence Mapping

- `clarification.md#Backend` / `#Generic` → immutable Laravel 12/PHP 8.2 target, preserved contracts/access terms, SQLite-only test DB, and no deployment/Phase 2 actions.
- `t1-architect.md#Backend selection and identity` / `#Frozen compatibility surface` → default site-agent and explicit router selection assessed against `VoucherActivationService` and the t13/t14 regression evidence.
- `t10-architect.md#Router secret lifecycle` → one-time issue/tenant authorization/no-retrieval assessed against `RadiusRouterCredentialController`, `RadiusRouterCredentialService`, route middleware, and t22.2 tests.
- `t10-architect.md#Network boundary` / `#Internal API contract` → CIDR/HTTPS/HMAC/replay code controls assessed against `AuthenticateRadiusPolicyService` and t22.3 tests; deployment reachability remains operationally unverified.
- `t10-architect.md#Accounting, lifecycle, and recovery policy` → `sessionMayAuthenticate()` and `RadiusPolicyBoundaryTest` show the pending-capacity reservation gap (F-04).
- `t12-dba.md#Schema notes` / `#Residual Risks` → additive schema and rollback guards retained; no production-engine conclusion is drawn from SQLite.
- `t22.1-backend.md#Validation` / `t22.4-backend.md#Target and Lock Validation` → F-01 disposition supported by lock-level PHP 8.2 validation and clean audits.
- `t22.2-backend.md#Test Results` / `t22.3-backend.md#Test Results` → F-02 and F-03 repository implementation/control evidence and test counts.
- `t14-tester.md#Test Results` / `t15-tester.md#Test Results` → historical upstream regression/browser evidence only; not rerun as part of this review.
