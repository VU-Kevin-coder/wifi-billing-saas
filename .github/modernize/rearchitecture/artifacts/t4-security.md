# t4 — Tenant Identity and Payment-Safety Constraints

## Scope and baseline

Audit-only security findings and constraints for the optional per-router access path. No application source/configuration changes, database migrations, deployment, or router operations were performed.

- Current checkout: `f9643fc759623e58596720f6609f872fe9bef6d9` (`feat(tests): enhance platform control settings and data retention tests`, 2026-11-13T20:29:00+03:00).
- At task start, `git status --short` showed only `?? .github/modernize/` (the rearchitecture materials); tracked source diff was clean. Rechecked after investigation: application/source diff remains clean.
- Project profile describes a Laravel 12 / PHP 8.2 modular monolith, with a proposed optional WireGuard + FreeRADIUS path.
- `context.md`, `decisions.md`, `team/security/log.md`, `team/security/inbox.md`, and a separate architecture plan were not present when this audit began. The supplied clarification and project profile were available and consumed.

## Security findings

### HIGH — Pesapal completion is not bound to the exact local order

- **Evidence:** `app/Http/Controllers/Api/PesapalWebhookController.php:58-80` reads the provider status for the callback-supplied tracking ID, then selects a local transaction using callback-supplied merchant reference **or** tracking ID (`:60-68`); a `COMPLETED` status on that response is enough to fulfill a pending transaction (`:80-88`). `app/Http/Controllers/PortalController.php:523-544` repeats the same selection and completion pattern for the browser callback.
- **Risk:** The selected transaction is not required to have the exact submitted `provider_reference`, and the returned provider order identity, merchant reference, amount, and currency are not compared to the selected transaction/package before fulfillment. A valid completed Pesapal order could therefore be paired with another pending transaction if its merchant reference is known, potentially crediting wallet funds and issuing access without payment for that transaction.
- **Required disposition:** Backend should bind provider-confirmed tracking ID and merchant reference to the same pending transaction, and compare verified amount/currency against the transaction/package before invoking the existing idempotent fulfillment service, in both webhook and browser callback paths. Preserve transaction locking and duplicate-callback behavior.
- **Fix owner:** backend. **Gate:** unresolved; this blocks a security pass for Phase 1.

### MEDIUM — Airtel success verification accepts absent transaction-match fields

- **Evidence:** `app/Services/PaymentService.php:287-290` treats a missing Airtel transaction ID, amount, or currency as a match (`null || ...`), once the provider reports success.
- **Risk:** If the Airtel status response omits any of these fields, the corresponding expected-value check is skipped. A status-only success may proceed to fulfillment without proving all local payment details.
- **Required disposition:** Confirm the supported Airtel API contract and captured response shape. If these fields are expected for successful collections, require exact ID, amount, and currency and fail closed on omissions; add coverage for missing as well as mismatched values.
- **Fix owner:** backend.

## Tenant and optional-path constraints

1. **Keep tenant authority server-derived.** Tenant-scoped Eloquent models fail closed when no `TenantContext` is set (`app/Models/Scopes/TenantScope.php:21-23`). Tenant host resolution and authenticated-user matching are enforced in `app/Http/Middleware/SetTenantContext.php:19-28`; platform-admin impersonation is a separate explicit branch (`:32-39`). A new network credential must resolve to one stored router and then to that router's server-side `tenant_id` and site. Never trust a request/packet `tenant_id`, router name, site name, or client-supplied IP/MAC as tenant authentication.
2. **Bind each credential to one router.** Preserve tenant/router referential consistency and enforce a distinct, revocable per-router identity. A RADIUS/WireGuard identity must not be a shared tenant-wide secret or a repurposed site-agent token. Authenticate/authorize accounting and disconnect operations against the same tenant/router/session tuple; reject cross-tenant or stale mappings.
3. **Do not bypass the site-agent control plane.** Site-agent credentials are Bearer tokens stored as SHA-256 hashes, and the authenticated agent identity scopes its commands (`app/Http/Middleware/AuthenticateSiteAgent.php:14-25`; `app/Http/Controllers/Api/SiteAgentController.php`). `SITE_AGENT_REQUIRED` defaults to true in production unless explicitly overridden (`config/site_agent.php:4-6`). Keep the existing endpoints, token behavior, command lifecycle, provisioning/revocation semantics, and global requirement intact; make any alternate access path explicit and per-router rather than disabling the requirement globally.
4. **Do not silently reassign purchased access.** Portal purchase constrains package and optional router IDs to the resolved tenant (`app/Http/Controllers/PortalController.php:106-124`); activation resolves package/router/site using tenant-qualified queries (`app/Services/VoucherActivationService.php:28-70, 124-167`). Persist the selected router binding with the payment/entitlement, validate it again at activation, and fail closed if it is missing, stale, or ambiguous. Do not silently fall back to another router for the optional path.
5. **Protect secrets according to use.** The site-agent bearer token is hashed; router API passwords and WireGuard private keys are encrypted at rest (`app/Models/Router.php:41`, `app/Models/VpnPeer.php:22-25`). Continue to keep credentials out of logs/API responses, use one-time/no-store delivery when a secret must be shown, and support revocation/rotation.
6. **Keep payment confirmation distinct from access activation.** The current portal uses a random payment bearer token stored as a hash and transported in `X-Payment-Token`, not in a URL (`app/Http/Controllers/PortalController.php:184, 317-334`; `routes/api.php` payment routes). Preserve the API contract and truthful `pending`/`provisioning`/`activation_pending` states. Only report active access after the appropriate router/RADIUS confirmation.
7. **Preserve exactly-once accounting.** The existing fulfillment path locks the transaction and updates its status, wallet, ledger, voucher, and activation in a database transaction (`app/Services/PaymentService.php:333-367`; `app/Services/WalletService.php:48-64`). New payment/usage fulfillment must have stable idempotency keys, durable reconciliation, and tenant/router/session scoping; it must not mutate historical payment or entitlement facts.

## Conditional readiness risk

`TenantContext` is registered as an application singleton (`app/Providers/AppServiceProvider.php:38`), exposes `forget()` (`app/Services/Tenancy/TenantContext.php:74`), but `SetTenantContext` does not clear context around requests that do not select a tenant. This is not established as exploitable in the current request runtime, and Octane is not listed in the root Composer requirements; however, before enabling Octane or another persistent web worker, make the context request-scoped or explicitly clear/restore it at request boundaries. Otherwise tenant state could survive between requests in a long-lived process.

## Existing safeguards observed

- Tenant scope denies queries without tenant context; `withoutTenancy()` is an explicit opt-out intended for platform-level work.
- Purchase intent deduplication and request idempotency are present; payment tokens are header-only and compared by hash.
- Flutterwave webhook processing checks the configured verification hash and provider-verified transaction ID/reference/status/amount/currency.
- MTN reconciliation checks external ID, amount, and environment-specific currency; all payment fulfillment is guarded by a transaction row lock.
- Site-agent required-mode tests cover fail-closed router ambiguity and missing paired agents. Payment tests cover token transport, normal Pesapal completion/replay, and MTN amount mismatch; no test currently covers Pesapal identity/amount mismatch or missing Airtel verification fields.

## Verification

- Dependency audit: `composer audit --locked --no-interaction --format=json` — completed; `advisories: []`, `abandoned: []`.
- Tests: not run by this audit-only task; fresh full test/build baseline is assigned to t5. No migrations or application DB commands were run.
- No infrastructure, deployment, router, or Phase 2 onboarding actions were taken.

## Security sub-gate

**NOT PASS.** Resolve the HIGH Pesapal order-binding issue and obtain the Airtel response-contract disposition before accepting the payment-safety portion of the Phase 1 release gate. The full Phase 1 gate also depends on the independent baseline, implementation, and plan-gate tasks.

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/clarification.md` — source for the approved preserved contracts, tenant isolation, separate per-router credentials, payment durability/idempotency, and out-of-scope restrictions.
- `.github/modernize/rearchitecture/artifacts/project-profile.yaml` — confirmed the Laravel 12/PHP 8.2 monolith and optional per-router scope.
- `.github/modernize/rearchitecture/board.md` — confirmed t4 scope and current Phase 1 task dependencies.

## Evidence Mapping

- `clarification.md#Backend (B2-B4)` → tenant credential separation, API preservation, and additive data constraints above.
- `clarification.md#Generic (G1-G3)` → preserve site-agent behavior, sales/access terms, and test posture; no Phase 2 or production operations.
- `project-profile.yaml#project` → scope limited to this Laravel/PHP repository.
- `app/Http/Controllers/Api/PesapalWebhookController.php:58-88` and `app/Http/Controllers/PortalController.php:523-544` → HIGH Pesapal finding.
- `app/Services/PaymentService.php:265-290` → MEDIUM Airtel verification finding.

## Re-review after remediation (t4.1 / t4.2)

### Verdict

**Pesapal callback/IPN security sub-gate: PASS.** A delegated security re-review of the current callback verifier, both callback paths, and the focused feature tests reported no vulnerabilities in the reviewed changes: **0 HIGH, 0 CRITICAL** remaining in this scope. The previous Pesapal HIGH finding above is historical and its disposition is superseded by this re-review.

The review considered exact local order binding, provider-confirmed merchant reference, transaction/package amount and currency, tenant-constrained portal handling, existing locked fulfillment/idempotency behavior, and refusal-path coverage in both browser callback and IPN. No production source/configuration changes were made by security.

### Verification evidence and limitations

- `.github/modernize/rearchitecture/artifacts/t4.1-backend.md#Test Results` records the post-remediation full suite: `php artisan test --do-not-cache-result` — 515 passed, 0 failed, 0 skipped (2,774 assertions), before t4.2 regression additions.
- `.github/modernize/rearchitecture/artifacts/t4.2-tester.md#Test Results` records the focused Pesapal suite — 24 passed, 0 failed, 0 skipped (134 assertions) — and full suite — 533 passed, 0 failed, 0 skipped (2,875 assertions). Security re-review did not rerun tests.
- These are Laravel HTTP-kernel feature tests with mocked provider responses. Live Pesapal sandbox/production behavior and browser E2E were not independently verified.

### Remaining payment-safety issue

The separate **MEDIUM Airtel** response-verification finding remains open and outside this Pesapal re-review: the initial evidence is `app/Services/PaymentService.php:287-290`, where absent provider transaction ID, amount, or currency is accepted as a match. Backend should confirm the supported Airtel response contract; if those values are required, reject omissions and cover missing as well as mismatched values. Therefore, the Pesapal HIGH/CRITICAL sub-gate passes, but the broader payment-safety gate is **not fully clear** until Airtel is dispositioned.

## Re-review Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/artifacts/t4-security.md` — original Pesapal HIGH finding and separate Airtel MEDIUM finding.
- `.github/modernize/rearchitecture/artifacts/t4.1-backend.md` — remediation behavior, compatibility constraints, and post-change test evidence.
- `.github/modernize/rearchitecture/artifacts/t4.2-tester.md` — negative callback/IPN coverage and final focused/full-suite evidence.
- `.github/modernize/rearchitecture/clarification.md` — preserve API, sales/access terms, and agent behavior; remain within Phase 1 and avoid migrations against the default DB, deployment, router changes, or Phase 2 onboarding.

## Re-review Evidence Mapping

- `t4-security.md#HIGH — Pesapal completion is not bound to the exact local order` → delegated current-code review concludes no HIGH/CRITICAL remains in the remediated Pesapal scope.
- `t4.1-backend.md#Result` and `#Exact changes` → reviewed verifier and matching guard behavior used as the remediation contract.
- `t4.2-tester.md#Exact changes` and `#Test Results` → callback/IPN refusal cases and inherited passing test evidence.
- `clarification.md#Generic` → confirms no Phase 2, production, router, or contract-changing work was performed.
