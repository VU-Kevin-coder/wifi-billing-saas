# t3 — Preserved Phase 1 Product and Contract Invariants

**Role:** PM  
**Classification:** Brownfield upgrade  
**Status:** Ready for Phase 1 planning  
**Scope:** Existing API/site-agent behavior, customer sales, access terms, and payment fulfillment safety. This is a behavioral contract, not an implementation design.

## Scope Baseline

- **Discovery basis:** Resolved user decisions in `clarification.md`; route declarations in `routes/api.php` and `routes/web.php`; the existing site-agent, portal/payment, package, and tenant-isolation feature tests.
- **Project profile:** Single Laravel 12 / PHP 8.2 application. The selected Docker Compose and MikroTik CHR targets are for later isolated lab work only.
- **In scope:** Define what Phase 1 changes must preserve and what observable payment/access behaviors must remain true.
- **Out of scope:** Production deployment or cutover; production router changes/configuration; Phase 2 infrastructure or hub onboarding; broad UI redesign; changes to existing sale/access terms; running migrations against the default database.

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/clarification.md` — resolved scope, immutable API and existing-agent preservation, additive/reversible data policy, payment/access success definition, test requirement, and production/lab exclusions.
- `.github/modernize/rearchitecture/artifacts/project-profile.yaml` — confirms Laravel 12/PHP project context, single modular monolith, and Phase 1 scope boundary.

## Evidence Mapping

- `clarification.md#Backend/API contract preservation` → REQ-001 through REQ-004.
- `clarification.md#Generic/Success definition and Out of scope` → REQ-003, REQ-004, REQ-011, and SC-002 through SC-007.
- `routes/api.php` and `SiteAgentControlPlaneTest.php` → REQ-001 through REQ-004; API inventory and command lifecycle evidence below.
- `PortalController.php`, `PaymentService.php`, webhook controllers, and `MobileMoneyPaymentFlowTest.php` / `PaymentTokenTransportTest.php` → REQ-006 through REQ-010.
- `Package.php`, `PortalPlanTest.php`, `PackageIsolationTest.php`, and `SitesAndHotspotsTest.php` → REQ-005 and REQ-010.

## Existing Behavioral Baseline

### API surface that must remain compatible

The existing API contract includes, at minimum:

- `GET /api/site-agent/commands` and `POST /api/site-agent/commands/{command}/complete`, authenticated by the site's existing Bearer token. Poll responses contain a `commands` array; completion reports `status` and accepts the established success/message/telemetry/result fields.
- `GET /api/payments/status` and `POST /api/payments/activate`; payment bearer tokens are accepted in `X-Payment-Token`, not in a URL. Activation also uses the existing `X-Idempotency-Key` contract.
- Existing provider callbacks: Pesapal `GET|POST /api/webhooks/pesapal`, Flutterwave `POST /api/webhooks/flutterwave`, MTN `POST /api/webhooks/mtn`, and Airtel `POST /api/webhooks/airtel`, including their established provider-specific input fields and result/status behavior.
- Existing authenticated notification routes under `/api/notifications`.

No Phase 1 work may change an existing route path, HTTP method, route name, authentication requirement, request field, response shape/status meaning, throttling/idempotency behavior, or tenant visibility. A genuinely new, additive contract must not make an existing caller change how it operates.

### Site-agent and access behavior

- The existing site-agent remains an independent, supported way to manage agent-backed routers. Its credentials, enrollment, polling, command operations, completion semantics, health reporting, retry/failure behavior, and router ownership remain compatible.
- When site-agent access is required, a session is not represented as active until the paired agent confirms provisioning. Missing agent pairing or ambiguous router selection fails closed; it does not redeem a voucher or create an active session.
- Expiry, manual disconnect, and device-ban/revocation state must not be reported as router-confirmed before the existing agent confirms the operation. Agent unavailability leaves an operation pending rather than fabricating success.
- Existing tenant boundaries apply to routers, agents, sites, packages, vouchers, transactions, and sessions. Neither a router credential nor a customer/payment token grants another tenant's access.

### Sales and access terms

- Operators continue to sell the same published packages through the current portal and renewal flow. Existing package identity, price, currency, duration/value/unit, speed limit, device limit, data allowance, publication state, and associated customer-facing terms are unchanged unless a separate, explicitly approved product change says otherwise.
- A purchased entitlement continues to honor the selected package's duration/expiry, speed, device, and configured data-cap terms. A new access path must not silently broaden, shorten, or otherwise reinterpret those terms.
- Voucher purchase/redeem, tenant-specific plan lookup, renewal, and failure messages retain their current customer-visible outcomes and tenant isolation.

## Requirements

### Functional Requirements

- **REQ-001 (Existing API compatibility):** Every existing API and portal caller continues to work without changing its request or response handling. Existing route paths, verbs, authentication, required fields, response schema/status meaning, and idempotency/throttle semantics remain unchanged.
- **REQ-002 (Agent compatibility):** Existing paired site agents continue to authenticate and exchange the current command payloads. An agent's missing, invalid, or revoked token remains unauthorized; a new access path does not repurpose, disable, or weaken site-agent authentication.
- **REQ-003 (Truthful agent-backed state):** A router/session operation that relies on a site agent is shown as pending/provisioning until the agent confirms it. Missing pairing, ambiguous routing, failed commands, and agent unavailability must not be turned into successful access or a false healthy/online state.
- **REQ-004 (Tenant isolation):** Every Phase 1 router/access/payment lookup and outcome is confined to the transaction's or request's tenant. Cross-tenant package, voucher, router, session, credential, or token use fails without disclosing or changing the other tenant's data.
- **REQ-005 (Unchanged commercial offer):** Existing package price, currency, duration, speed, data cap, device count, sales availability, and renewal terms remain as configured and presented before Phase 1. No new access route may bypass or reinterpret those terms.
- **REQ-006 (Payment confirmation before entitlement):** A provider callback or browser/client claim alone cannot mark a sale paid or grant access. Fulfillment requires successful verification against the expected transaction/provider identity and the existing expected payment amount and currency; mismatches and unavailable verification do not create an entitlement or credit a tenant wallet.
- **REQ-007 (Safe payment uncertainty):** Pending, unverified, mismatched, or unavailable payment outcomes remain visibly pending/failed according to the existing contract and never appear as a completed sale. A failure to confirm the provider result is not treated as proof of payment.
- **REQ-008 (Single fulfillment):** Repeated callbacks, polling, activation requests, or retries for one successful transaction produce at most one completed sale, one applicable wallet credit/commission calculation, one voucher, and one corresponding session/access entitlement. Replays return the existing outcome without duplicating money or access.
- **REQ-009 (Payment-token confidentiality):** The customer payment token remains an unguessable bearer secret stored/compared via its existing hash representation and transported only in the `X-Payment-Token` header. Missing/wrong tokens and URL/query token attempts do not reveal a transaction or voucher.
- **REQ-010 (Truthful customer status and package enforcement):** The payment and activation endpoints do not expose a voucher/access success until payment is completed and the entitlement is ready under its existing package terms. Provisioning, fulfillment pending, and activation pending remain distinguishable from active access.
- **REQ-011 (No bypass or premature cutover):** The global site-agent requirement is not disabled as a shortcut. Phase 1 does not deploy, configure or modify routers, run a default-database migration, or begin Phase 2 infrastructure/hub onboarding. No production cutover occurs.
- **REQ-012 (Regression gate):** All existing repository tests pass with no weakened assertions or skipped existing behaviors. The fresh full-suite baseline and post-change comparison belong to the tester/release gate; this PM task does not claim either run passed.

### User Scenarios & Acceptance Checks

1. **P1 — Existing site agent provisions access.** Given a tenant requires a paired agent, when a valid voucher is activated for an unambiguous router, then the customer session remains provisioning until that agent acknowledges the existing `provision_user` command; only then does the session become active. If the agent is absent or router selection is ambiguous, activation fails closed with no active session and no voucher redemption.
2. **P1 — Customer buys a package safely.** Given a customer selects a currently published package, when they submit the existing portal purchase flow, then the displayed/package price and access terms remain those of that package; a pending or unverifiable payment gives no voucher/access; a verified successful payment fulfills once; duplicate delivery does not duplicate wallet credit, voucher, or session.
3. **P1 — Customer checks or activates payment.** Given a payment token, when the customer checks status or requests activation using the existing headers, then responses distinguish pending/failed/fulfillment-pending/provisioning/activation-pending/ready outcomes; no token in a URL or invalid token reveals a payment or voucher; a voucher is exposed only at the existing ready-to-use success point.
4. **P2 — Operator maintains current offers and access policy.** Given an operator lists, publishes, or retires a package, when customers buy or renew, then tenant isolation and the current package price, currency, duration, speed, device, and data-limit terms remain effective.
5. **P2 — Site-agent outage or replay.** Given a command or provider confirmation is delayed, unavailable, mismatched, or replayed, when the system receives a retry, then it preserves pending/failure truth, does not claim unconfirmed access/health, and does not issue duplicate sale/accounting/access effects.

## Explicit Characterization Evidence

These are existing test cases to retain and run as part of the full-suite regression gate; listing them is not a claim that this task executed them:

- `tests/Feature/SiteAgentControlPlaneTest.php`: `test_activation_waits_for_site_agent_to_provision_router_access`, `test_paired_agent_can_poll_and_acknowledge_provision_and_expiry`, `test_production_activation_fails_closed_when_router_is_ambiguous`, `test_production_activation_fails_closed_without_a_paired_site_agent`, `test_site_agent_api_rejects_missing_or_invalid_tokens`, and the tests for confirmed disconnect/device-ban/health behavior.
- `tests/Feature/MobileMoneyPaymentFlowTest.php`: provider verification, mismatched amount, pending purchase deduplication/retry, and `test_successful_payment_fulfillment_is_idempotent_and_starts_the_session_immediately`.
- `tests/Feature/PaymentTokenTransportTest.php`: header-only token transport, URL/query-token rejection, activation idempotency, and not-found behavior.
- `tests/Feature/PesapalPaymentTest.php`: Pesapal checkout/callback behavior and package-currency handling. `FlutterwaveConfigurationTest.php` covers configuration, but this inventory found no dedicated Flutterwave charge-webhook feature test; if Phase 1 touches that callback, add explicit successful/mismatched/replay verification.
- `tests/Feature/PortalPlanTest.php`, `PackageIsolationTest.php`, and `SitesAndHotspotsTest.php`: customer plan/renewal, package duration/currency/limits, and tenant/site isolation.

## Success Criteria

- **SC-001:** All existing named routes and their request/response contracts above remain compatible; no existing route is removed or repurposed.
- **SC-002:** The complete existing test suite passes after Phase 1 with zero regressions; any new characterization checks for modified behavior also pass.
- **SC-003:** Replaying a verified payment callback/status/activation flow does not increase its count of wallet credits, vouchers, or sessions beyond one each.
- **SC-004:** In tests, invalid/unavailable/mismatched provider verification creates zero voucher/session entitlement and zero wallet credit; only a verified matching success completes the sale.
- **SC-005:** Agent-required activation without a paired agent or unambiguous router creates no redeemed voucher or session; agent-backed provisioning/expiry/disconnect remains pending until confirmation.
- **SC-006:** Existing published package and renewal tests confirm unchanged price/currency/duration/speed/device/data terms and tenant separation.
- **SC-007:** No production deployment or router mutation is performed during Phase 1; Phase 2 does not begin without separate explicit user approval.
- **SC-008:** Formal performance/availability/recovery SLOs are not invented. Existing latency, throughput, availability, and recovery baselines must be measured before any later rollout decision.

## Test Results

- Command executed: None for this product-definition task; no application code was modified.
- Passed / failed / skipped: Not applicable.
- Existing test evidence is enumerated above. Fresh full-suite baseline and post-change verification remain assigned to the tester and are required before the release gate.

## Open Risks for the Release Gate

- This artifact defines preservation criteria; it does not establish that Phase 1 implementation or the full test suite passes.
- Provider-specific currency and callback behavior differs by provider/environment. Implementers must preserve provider-specific verification expectations rather than generalizing them.
- No dedicated Flutterwave charge-webhook feature test was found in the current test inventory; require explicit callback characterization if that handler is changed.
- Any newly introduced access path must demonstrate the same package-term enforcement and truthful activation/revocation behavior before it can be considered optional/ready.

