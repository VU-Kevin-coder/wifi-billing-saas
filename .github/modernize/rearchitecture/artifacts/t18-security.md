# t18 — Payment, Tenant, Credential, and Replay Security Audit

## Verdict

No confirmed payment bypass, tenant-isolation bypass, or replay exploit was identified in the reviewed Phase 1 changes. Two required security controls remain incomplete or unverified, and the locked-package/PHP mismatch is a separate compatibility risk. These are not a production or release approval.

The audit was read-only. No source was changed, no tests or database operations were run, and no `.env` file was accessed or processed.

## Findings

### F-01 — MEDIUM: Authorized one-time router-secret delivery is missing

- **Evidence:** `app/Services/RadiusRouterCredentialService.php:16-36` generates and returns the plaintext secret. The t13 implementation report explicitly states that no authorized operator-facing one-time delivery workflow was added.
- **Risk:** The approved secret lifecycle is incomplete. A protected, authorized, single-reveal delivery and handling path has not been demonstrated; this does not establish that the current service return is exposed to an unauthorized caller.
- **Required action / owner:** Backend (with security review) must provide an authorized, one-time delivery flow and verify that the secret is not persisted in logs, ordinary serialization, or repeatable responses.

### F-02 — MEDIUM: Private production API boundary is not deployment-verified

- **Evidence:** Policy routes are registered with the normal API middleware in `bootstrap/app.php:32-35`. The t13 report says production network restrictions, TLS configuration, and shared-cache operation remain unverified. The reviewed middleware, `app/Http/Middleware/AuthenticateRadiusPolicyService.php:20-65`, contains service authentication and production HTTPS/shared-cache checks.
- **Risk:** Application-level guards do not prove that the deployed policy API is private or that production nonce storage is correctly shared and available. No deployed exposure or bypass was established in this audit.
- **Required action / owner:** DevOps/platform must document and verify private ingress, protected TLS, and the production shared nonce-cache configuration before enabling the integration.

### R-01 — MEDIUM: PHP 8.2 compatibility risk in locked dependencies

- **Evidence:** t16 reports `composer.json:9` targets PHP `^8.2`, while `composer.lock:219` and `composer.lock:3829` contain locked packages requiring PHP `^8.3`. t16 also records that runtime verification of the updated lockfile was not performed.
- **Risk:** The required PHP 8.2 runtime may be unable to install or execute the locked dependency set. This is a compatibility/release risk, not a confirmed vulnerability.
- **Required action / owner:** Backend/dependency owner must resolve or explicitly validate the locked-package requirements on PHP 8.2 without changing the user-required target version.

## Controls Reviewed

- **Payment binding:** `app/Services/PesapalOrderVerifier.php:11-59` checks the stored order references, tenant, amount, and currency. `app/Http/Controllers/PortalController.php:184-185, 377-381` stores payment tokens as hashes and transports the token in a request header. t13 additionally records typed sale linkage and tenant/package/amount checks in the locked fulfillment path. No payment contract or access-term change was reported.
- **Payment-provider uncertainty:** The existing security log's t4.5 entry records that Airtel status verification matches the exact stored provider transaction ID and checks amount/currency when echoed, while the provider's omission behavior remains unverified. This is contract uncertainty, not a confirmed bypass, and was not revalidated by t18.
- **Tenant isolation:** t13 records router-derived tenant authorization for policy requests and normalized, router-scoped accounting ingestion; the policy boundary and tenant/entitlement cases are included in its focused `RadiusPolicyBoundaryTest` report. The reviewed reports did not establish a cross-tenant access bypass.
- **Credential separation:** `app/Models/Router.php:40-57` uses Laravel encryption for the per-router secret and hides it from ordinary serialization. The policy middleware uses a separate RADIUS-to-Laravel service credential. No `APP_KEY` disclosure was identified in the reviewed changes.
- **Replay protection:** `app/Http/Middleware/AuthenticateRadiusPolicyService.php:20-65` authenticates request method, path, body digest, timestamp, and nonce and rejects replayed nonces. Deployment-side TLS/cache readiness remains the F-02 gap.
- **Event retention:** t12/t13 report normalized accounting evidence and no added raw-event retention policy. Keep retention unchanged until the existing platform policy is verified; do not infer a new retention period.
- **Compatibility:** the RADIUS backend is explicitly opt-in per router; the site-agent default and existing flow remain in place per t13.

## Test Evidence

This audit ran no tests. Earlier evidence in the consumed artifacts reports:

- t13 focused boundary tests: 8 passed; activation regression tests: 18 passed; full PHPUnit suite: 550 passed.
- t14 full PHPUnit suite: 555 passed, zero failures or skips, on SQLite `:memory:`.
- t15 Playwright portal journey: 1 passed; this covers pending payment status and header-only token transport, not successful payment fulfillment.
- t16 did not rerun application tests after its lockfile update and records PHP 8.2 incompatibility in locked packages.

These are upstream-reported results, not a t18 rerun or production-engine/deployment evidence. No database outside the permitted automated SQLite target was used.

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/artifacts/t12-dba.md` — schema/credential storage, normalized accounting evidence, and no raw-event retention addition.
- `.github/modernize/rearchitecture/artifacts/t13-backend.md` — implementation behavior, security boundary, known one-time-delivery and deployment gaps, and focused/full test evidence.
- `.github/modernize/rearchitecture/artifacts/t14-tester.md` — SQLite-only migration and domain regression evidence.
- `.github/modernize/rearchitecture/artifacts/t15-tester.md` — isolated browser payment-status/token-transport evidence.
- `.github/modernize/rearchitecture/artifacts/t16-backend.md` — dependency scan and PHP 8.2 locked-package mismatch.
- `.github/modernize/rearchitecture/clarification.md` — approved private authenticated API, separate credentials, encrypted one-time router secret, no `APP_KEY` exposure, preserved contracts/site-agent path, and phase limits.
- `.github/modernize/rearchitecture/board.md` — read-only task and phase status.
- `.github/modernize/rearchitecture/team/security/log.md` — prior payment-review record; Airtel response amount/currency echo uncertainty.
- `.github/modernize/rearchitecture/learnings/security/secret-isolation-radius-boundary.md` — required credential separation, request integrity/replay, and one-time delivery guardrails.

## Evidence Mapping

- `clarification.md#Backend` and `#Generic` → F-01/F-02 and credential/replay controls; require a private authenticated policy API, encrypted per-router credentials with one-time delivery, separate service secret, no `APP_KEY` exposure, and preservation of existing behavior.
- `t13-backend.md#Summary` and `#Release Blockers and Residual Risks` → F-01/F-02; reported workflow and deployment-verification gaps.
- `t13-backend.md#Test Results` and `t14-tester.md#Test Results` → tenant, replay, payment-fulfillment, and SQLite regression evidence summarized above.
- `t15-tester.md#Test Results` → payment-token header transport and pending-state browser evidence; does not establish successful fulfillment.
- `t16-backend.md#Findings and Risks` and `#Test Results` → R-01; required PHP 8.2 compatibility risk and absence of post-lockfile application-test evidence.
- `team/security/log.md#[t4.5]` → Airtel response amount/currency echo uncertainty is preserved as an unverified provider-contract question, not escalated to a confirmed exploit.
- `t12-dba.md#Schema notes` → no raw packets/credentials in normalized accounting storage and no new retention policy.
