# t1 — Additive Access and Payment-Safety Architecture

## Architecture decisions

| Concern | Phase 1 contract |
|---|---|
| Application authority | Retain Laravel 12 / PHP 8.2 as the source of truth for tenant, router, package, payment, voucher, and customer-access state. FreeRADIUS 3.2 is an optional protocol adapter, not a second entitlement authority. |
| Access selection | Select the access backend per router. Existing routers remain on the current site-agent path by default; only an explicitly configured router may use RADIUS. Fail closed if that selected backend is unavailable or misconfigured; never silently fall back to a different backend. |
| Existing agent requirement | Preserve `SITE_AGENT_REQUIRED` and its production default for the existing path. Do not globally disable it or reinterpret it as permission for routers without an explicit alternate backend. |
| Payment boundary | Provider verification, payment settlement, wallet ledger credit, voucher fulfillment, and the durable access grant must remain idempotent and tenant-bound. A payment is not an access session, and access must not be reported active until the selected backend confirms activation. |
| Public compatibility | Keep current web/API routes, request/response shapes, payment-token handling, sales behavior, package terms, and voucher/access semantics. Add a separate RADIUS protocol/data boundary; do not repurpose the site-agent endpoints. |
| Rollout boundary | This document covers repository/domain and payment-safety design only. Docker Compose changes, FreeRADIUS deployment/configuration, CHR work, router changes, production deployment, and hub onboarding are deferred to later approved phases. |

## Existing seams (source observations)

| Seam | Existing behavior and evidence | Architecture implication |
|---|---|---|
| Purchase and payment intent | `PortalController::purchase` validates package and router against the resolved tenant, deduplicates pending purchases using a tenant/package/normalized-phone fingerprint, creates a pending `voucher_sale`, and stores a hash of a random payment token (`app/Http/Controllers/PortalController.php:94-200`). | The optional access path must use the persisted purchase intent and its validated tenant/router binding; it must not trust a later client-supplied tenant or create a separate payment path. |
| Provider settlement | Mobile-money initiation/reconciliation live in `PaymentService`; MTN/Airtel reconciliation validates provider details (`app/Services/PaymentService.php:30-177,184-291`). Flutterwave verifies its callback with the provider before fulfillment (`app/Http/Controllers/Api/FlutterwaveWebhookController.php:18-158`), and Pesapal checks status with its provider API (`app/Http/Controllers/Api/PesapalWebhookController.php:18-108`). Webhook routes are in `routes/api.php:19-28`. | Keep provider callbacks as the only settlement authority. Do not issue access from a callback payload before server-side verification. Keep provider outcomes and retry behavior compatible. |
| Successful fulfillment | `handleSuccessfulPackagePayment` locks the transaction and runs settlement, `WalletService::apply`, voucher creation, and activation inside a database transaction; an already processed transaction returns the linked voucher rather than issuing another (`app/Services/PaymentService.php:333-422`). | Extend this atomic/idempotent seam for any durable RADIUS entitlement or provisioning record. Never credit `tenants.wallet_balance` outside `WalletService`; durable access work must be retryable without a second ledger credit or voucher. |
| Wallet accounting | `WalletService::apply` requires a caller transaction, locks the tenant row, updates the balance, and appends a ledger entry (`app/Services/WalletService.php:13-78`). | Reuse the service and its transaction boundary; add no parallel balance mutation. |
| Voucher/session lifecycle | `VoucherActivationService::activate` locks the tenant-scoped voucher/session, preserves an existing active/provisioning session, resolves router/site, and queues `provision_user` through `SiteAgentCommandService` when an agent is present (`app/Services/VoucherActivationService.php:26-120`, `app/Services/SiteAgentCommandService.php:110-143`). Router resolution is tenant-scoped (`VoucherActivationService.php:121-208`). | Preserve this path for existing routers. Introduce an explicit alternate adapter at the router-selection/activation boundary rather than changing the site-agent command contract. |
| Global agent gate | `site_agent.required` defaults to true in production (`config/site_agent.php:3-6`); activation rejects a router without a paired agent when the flag is true (`VoucherActivationService.php:70-72,167-169`). | A RADIUS router needs an explicit per-router branch before the legacy agent prerequisite is evaluated. The legacy branch and global fail-safe must retain their current semantics. |
| Tenant ownership | Tenant-scoped models apply `TenantScope` and auto-fill tenant IDs from `TenantContext` (`app/Models/Concerns/BelongsToTenant.php:15-27`, `app/Models/Scopes/TenantScope.php:20-33`). Router, voucher, transaction, and session models use that tenant boundary. | RADIUS lookups bypass Laravel model scopes, so every SQL authorization/accounting operation must explicitly bind authenticated router identity, router ID, and tenant ID. |
| Router identity and agent commands | Router records are tenant-scoped and currently encrypt `api_password` (`app/Models/Router.php:10-45`). Site-agent polling/completion are separately authenticated routes (`routes/api.php:53-60`) with durable command state (`app/Http/Controllers/Api/SiteAgentController.php:19-224`). | RADIUS shared secrets and WireGuard keys are distinct router/network credentials—not user credentials or site-agent bearer tokens. Keep the existing command queue and its completion semantics intact. |
| Voucher identity | Voucher codes are tenant-scoped by the active schema migration (`database/migrations/2026_11_08_000002_scope_voucher_codes_to_tenant.php:19-24`). | A voucher code alone is not a globally unique tenant identity. Resolve the authenticated NAS/router first, derive its tenant, then look up the voucher within that tenant and router's eligibility scope. |
| Payment-facing API | `GET /api/payments/status` and `POST /api/payments/activate` retain bearer-token-in-header handling and throttling/idempotency middleware (`routes/api.php:30-41`). Responses include `pending`, `failed`, `fulfillment_pending`, `provisioning`, `activation_pending`, and `completed` (`app/Http/Controllers/PortalController.php:372-480`). | Preserve endpoint paths, token rules, status names, and meanings. Reuse existing pending/provisioning responses for delayed activation rather than claiming success or changing API shape. |
| Current RADIUS state | The production-readiness record calls RADIUS deferred/not implemented (`docs/PRODUCTION-READINESS.md`, “Deferred work”). The existing WireGuard documentation explicitly describes it as optional management connectivity, not RADIUS or customer traffic (`README.md:538-560`). | Treat RADIUS as a new, opt-in domain/protocol seam; do not infer it exists from the current WireGuard or site-agent implementation. |

## Additive Phase 1 architecture

```text
Customer portal
  -> existing purchase / payment-token API
  -> provider verification and existing PaymentService settlement
  -> atomic transaction: transaction + WalletService ledger + voucher/access grant
  -> router-selected access adapter
       -> existing site-agent command path (default; unchanged)
       -> optional RADIUS authorization/accounting path (explicit router opt-in)

MikroTik NAS -> FreeRADIUS
  -> authenticate the NAS as one configured router
  -> resolve router -> tenant from server-side router identity
  -> tenant/router-scoped voucher, package, and active entitlement check
  -> Access-Accept/Reject with the existing package limits
  -> idempotent Accounting Start/Interim/Stop records
  -> reconcile current session state and counters in Laravel-owned data
```

### Backend selection and identity

1. Make the selection explicit at router scope, with all pre-existing routers retaining the current site-agent mode. The exact column/table layout belongs to t6; the invariant is a deterministic default and an opt-in RADIUS state that cannot be inferred from a missing agent.
2. Authenticate each RADIUS NAS as a specific router using a distinct per-router RADIUS credential. Keep this credential separate from Laravel user authentication, site-agent bearer tokens, RouterOS API credentials, and WireGuard peer keys. Store secrets encrypted at rest when they must be recoverable by the RADIUS integration; never log secret material.
3. Derive `tenant_id` from the authenticated router record. Do not accept a tenant claim from `User-Name`, request attributes, an accounting packet, or the portal client. Reject unmapped, disabled, wrong-mode, or cross-tenant router/voucher/session combinations.
4. Treat the router's selected backend as authoritative for activation and expiry. The RADIUS branch must not dispatch site-agent commands; the existing site-agent branch must continue to use its current command queue.

### Payment and fulfillment

1. A voucher or entitlement remains unavailable until the provider is verified by the existing server-side provider flow and its transaction is completed. Match the local transaction reference, tenant, package, amount, and currency before fulfillment.
2. Extend the existing transaction-locked database operation so the payment transition, wallet ledger entry, voucher, tenant/router-bound entitlement or session, and any durable RADIUS provisioning marker are committed together. Preserve `WalletService` as the sole wallet-balance writer.
3. Enforce a unique, stable relationship between the local sale transaction and its fulfillment so concurrent or replayed callbacks cannot create another voucher, session, entitlement, or wallet credit. Retries must return/continue the original fulfillment.
4. Do not hold a database transaction open over RADIUS, router, SMS, or other network calls. Any required asynchronous side effect must have durable state that a retry/reconciliation worker can resume; completion of payment must remain distinguishable from activation.
5. Record an activation as pending/provisioning until the selected access path confirms it. For RADIUS, an Access-Accept response alone is not proof of an established customer session; use authenticated accounting evidence to reconcile the session before reporting it active.

### RADIUS authorization and accounting

1. Laravel-owned `Router`, `Voucher`, `Package`, and session/entitlement state remain authoritative. FreeRADIUS may use a narrowly scoped database integration for authorization and accounting; it must not maintain an independently editable shadow entitlement that can outlive or contradict Laravel state. t6 owns the concrete additive schema and database-principal/privilege design.
2. Authorization is allowed only for a valid paid voucher/entitlement bound to the authenticated router and tenant, with an active session/window and package policy still in force. Translate existing duration, rate, byte, and device restrictions without changing the product's sale terms.
3. Accounting records must be replay-safe and attributable to tenant, router, and NAS session. Repeated Start/Interim/Stop packets must update/reconcile the same session rather than duplicate usage or extend access. Keep raw accounting evidence sufficient to recover final counters and disconnect state.
4. Expiry, revoked/voided voucher, exhausted package limits, or unresolved router identity must not grant access. A disconnect not yet confirmed remains pending/reconcilable and must not be displayed as a verified disconnect.

## Frozen compatibility surface

- Preserve the current purchase/redeem routes, payment webhook routes, payment-token-in-header requirement, idempotency/throttle middleware, and all existing response/status contracts. New RADIUS protocol handling is not a replacement for `/api/site-agent/*` or `/api/payments/*`.
- Preserve provider verification and reconciliation semantics; never let a portal poll or unverified callback mark a charge paid.
- Preserve existing package prices and access duration/data/rate/device limits; RADIUS is an alternate enforcement path for those terms, not a product/pricing change.
- Preserve current site-agent pairing, command retry/acknowledgment, provisioning, expiry, and failure behavior for routers not explicitly selected for RADIUS.
- Preserve tenant scoping for every read/write and avoid backfilling legacy tenant/router/payment relationships from unverified assumptions. New schema must be additive, reversible, and must not mutate existing payment/entitlement facts.
- The new branch is disabled by default; no production deployment, router configuration, default-database migration, or cutover is included in this task.

## Phase boundary and handoffs

| Handoff | Required input/output |
|---|---|
| t2 backend | Map exact existing lifecycle call sites and identify the narrowest insertion seam; retain source anchors above and surface any contrary behavior before implementation. |
| t3 PM | Freeze current API, agent, sales, voucher, and access-term invariants; use the compatibility list above as architecture inputs, not as a substitute for product acceptance criteria. |
| t4 security | Validate NAS identity derivation, per-router credential handling, SQL tenant isolation, RADIUS packet exposure, replay resistance, and provider-callback assumptions. |
| t5 tester | Capture a fresh current-checkout baseline with isolated test configuration; this architecture task did not run tests or migrations. |
| t6 DBA | Propose only additive/reversible tenant-scoped schema for router mode/credentials, durable entitlement/fulfillment, and RADIUS accounting; include unique/idempotency constraints and rollback. Do not mutate/backfill legacy records without verified facts. |
| Later phase, after separate approval | Local Docker Compose lab and MikroTik CHR 7.24.6 may be used for isolated runtime acceptance. Those selections authorize no infrastructure changes in Phase 1. |

## Verification status

- Static source inspection only; no source files, configuration, or migrations were changed.
- No build or test suite was run for t1. The fresh test/build baseline is assigned to t5 and remains unverified here.
- No database migration was run; no deployment or router operation was performed.

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/clarification.md` — approved stack, API/data compatibility, tenant, lab, rollout, and no-cutover constraints.
- `.github/modernize/rearchitecture/clarification-answers.md` — user answers specifying Laravel 12/PHP 8.2, FreeRADIUS 3.2, in-place additive data handling, per-router credentials, measured baselines, and preserved site-agent behavior.
- `.github/modernize/rearchitecture/artifacts/project-profile.yaml` — repository scope and modular-monolith description; used because no fanout/topology artifact was present.
- `.github/modernize/rearchitecture/board.md` — task ownership and phase boundaries; read-only.

## Evidence Mapping

- `clarification.md#Backend` and `clarification.md#Generic` → Architecture decisions, Frozen compatibility surface, and Phase boundary.
- `clarification-answers.md#B2`, `#B3`, `#B4`, `#B5`, `#G1`, `#G2`, `#G3` → Public compatibility, payment/fulfillment invariants, backend selection, and rollout handoffs.
- `project-profile.yaml#project` and `#assessment` → Additive Laravel modular-monolith boundary and explicit exclusion of deployment/router work.
- `PortalController.php:94-200,372-480`, `PaymentService.php:184-291,333-422`, `FlutterwaveWebhookController.php:18-158`, `PesapalWebhookController.php:18-108`, `VoucherActivationService.php:26-120`, and `WalletService.php:13-78` → Existing purchase, provider verification/reconciliation, atomic fulfillment, wallet, and activation seams.
- `routes/api.php:19-60`, `SiteAgentController.php:19-224`, and `SiteAgentCommandService.php:110-143` → Frozen webhook and site-agent protocol boundary.
- `BelongsToTenant.php:15-27`, `TenantScope.php:20-33`, `Router.php:10-45`, and `2026_11_08_000002_scope_voucher_codes_to_tenant.php:19-24` → Tenant/router identity and tenant-scoped voucher lookup requirements.
