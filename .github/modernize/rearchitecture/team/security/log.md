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
