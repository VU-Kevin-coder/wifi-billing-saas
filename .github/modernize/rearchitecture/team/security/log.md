## [t4] Document tenant identity and payment-safety constraints
- Codebase/domain discoveries: Laravel uses a fail-closed global tenant scope, host-derived tenant context, a separate hashed-token site-agent control plane, and transaction-locked payment fulfillment. Both Pesapal completion paths select local transactions with an OR of callback merchant reference/tracking ID and do not bind the provider result's identity and amount/currency to the selected order. Airtel success reconciliation permits absent match fields.
- Wrong assumptions and corrections: `learnings/security/` exists but is empty (not absent); the optional-path audit is read-only and the t5 task owns the full baseline suite.
- Debugging dead-ends and what actually worked: `Get-Date -AsUTC` is unavailable in this PowerShell version; `[DateTime]::UtcNow` returned the task start timestamp. The generic API-contracts skill does not support PHP/Laravel, so targeted `rg`/file inspection was used.
- Techniques/patterns worth reusing: For payment checks, trace the public route → callback-selected local transaction → provider verification response → locked fulfillment, then check identity, amount, currency, tenant, and idempotence as one binding.
- Learnings consumed: []

## [t4] Re-review Pesapal callback remediation
- Codebase/domain discoveries: The delegated re-review reported no vulnerability in the remediated Pesapal callback/IPN scope and no HIGH/CRITICAL remaining there. The separate Airtel response-verification issue from the initial audit remains outside this narrow re-review.
- Wrong assumptions and corrections: Passing Pesapal regression and full-suite evidence does not establish live Pesapal provider behavior or clear the separate Airtel finding.
- Debugging dead-ends and what actually worked: The `security-review` skill name was unavailable; the mandatory specialized security-review agent performed the read-only review.
- Techniques/patterns worth reusing: Keep the initial finding as history and add an explicit dated/disposition section that points to remediation and tester artifacts rather than silently erasing original evidence.
- Learnings consumed: []

## [t4.5] Re-review payment verification changes
- Codebase/domain discoveries: Shared Pesapal verification binds both callback identifiers to a local order and checks provider merchant reference, amount, and currency. Airtel now requires the status response ID to equal the stored provider ID and checks local UGX purchase terms; amount/currency echoes are checked when supplied. A fresh composer audit surfaced a new low Laravel advisory at v12.70.2.
- Wrong assumptions and corrections: The prior clean composer audit is no longer current; `composer audit` now exits 1 because CVE-2026-128083 is present. Airtel's omitted amount/currency remains contract uncertainty, not a confirmed bypass, given exact queried transaction ID binding.
- Debugging dead-ends and what actually worked: The specialized security-review agent could not start due to a routing network error; completing the focused direct review and running the payment tests provided evidence without changing source. PHPUnit's root configuration pins SQLite in-memory.
- Techniques/patterns worth reusing: For payment verification, trace provider callback/status identity through the same local order, tenant/package price, currency, and the locked fulfillment path; distinguish provider-schema uncertainty from an exploitable mismatch bypass.
- Learnings consumed: []

## [t11] Approve secret-isolation and least-privilege boundary
- Codebase/domain discoveries: The approved adapter boundary keeps Laravel authoritative and FreeRADIUS database-less; separate the per-router RADIUS secret from the dedicated service credential and never disclose `APP_KEY`.
- Wrong assumptions and corrections: A protected channel plus an opaque service bearer alone does not provide the request-level integrity and replay guarantees required by the accepted API design; bind method, canonical path, body digest, timestamp, and unique nonce.
- Debugging dead-ends and what actually worked: No implementation was present to audit in this decision task; reviewed t10/t10.1 plus clarification and prior security evidence directly, with no tests or database operations.
- Techniques/patterns worth reusing: Approve boundary decisions conditionally on explicit testable controls; distinguish architecture approval from implementation/release approval and keep production, router, and Phase 2 permissions separate.
- Learnings consumed: []

## [t18] Audit payment, tenant, credential, and replay protections
- Codebase/domain discoveries: Policy request authentication binds method/path/body/timestamp/nonce; the router credential is encrypted/hidden and separate from the service credential. Operator one-time secret delivery is absent, production private ingress/TLS/shared-cache remain unverified, and locked dependencies requiring PHP ^8.3 conflict with the required PHP ^8.2 target.
- Wrong assumptions and corrections: A code-level HTTPS/cache guard does not prove private production exposure or shared-cache readiness; the one-time-returning credential service is not an authorized operator delivery workflow.
- Debugging dead-ends and what actually worked: `context.md` and `decisions.md` were unavailable; the clarification, board, task dependencies, and security learning provided the controlling evidence. The review remained read-only; no tests, DB operations, or `.env` processing were performed.
- Techniques/patterns worth reusing: Separate confirmed vulnerabilities from incomplete design controls and deployment evidence gaps; keep PHP target compatibility risks distinct from CVE findings.
- Learnings consumed: [security/secret-isolation-radius-boundary]

## [t18.1] Re-audit post-remediation payment, tenant, credential, and replay protections
- Codebase/domain discoveries: The specialized read-only review confirmed the t22.2 one-time secret delivery and t22.3 direct-peer allowlist/HMAC/timestamp/nonce controls are present in code; no exploitable issue confirmed. Airtel provider omission semantics and production private-ingress/TLS/shared-cache behavior remain unverified. User task says `access_mode=radius`, while project artifacts name the selector `access_backend`; do not assume these are equivalent.
- Wrong assumptions and corrections: Focused SQLite tests validate code paths but cannot prove production network/cache configuration or provider guarantees. A clean dependency scan is evidence from t22.4, not a new scan run in this audit.
- Debugging dead-ends and what actually worked: `context.md` and `decisions.md` were absent at their expected paths; use clarification, board, security history/inbox, and dependency artifacts. Security review was delegated to the specialized read-only reviewer; no source inspection was repeated locally.
- Techniques/patterns worth reusing: Track code vulnerabilities separately from deployment prerequisites and external provider-contract uncertainty; treat field-name drift as a release clarification until a canonical contract is confirmed.
- Learnings consumed: [security/secret-isolation-radius-boundary]
