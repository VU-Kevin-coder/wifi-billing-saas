# t2 — Current Domain and Payment-Fulfillment Extension Map

## Scope and baseline

This is a repository/domain discovery artifact for Phase 1. No application source, API contract, database schema, deployment setting, or router configuration was changed.

- Checkout baseline captured at `2026-11-13T18:14:37Z`: branch `integration/merge-both`, commit `f9643fc759623e58596720f6609f872fe9bef6d9`.
- `git status --short` showed the pre-existing untracked `.github/modernize/` tree and no tracked source changes. The tree contained the rearchitecture materials; no application files were dirty.
- No migration or test command was run. Fresh test/build baselining is assigned to t5.
- `.github/modernize/rearchitecture/context.md`, `decisions.md`, `team/backend/log.md`, and `team/backend/inbox.md` were absent. The task dependency `.github/modernize/rearchitecture/clarification.md` was present at the rearchitecture root and was read there.

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/clarification.md` — approved boundaries: Laravel 12/PHP 8.2, preserve current contracts and site-agent behavior, additive data changes, optional per-router access, no production cutover or infrastructure work.
- `.github/modernize/rearchitecture/artifacts/project-profile.yaml` — confirmed this is a single Laravel modular monolith and Phase 1 is limited to repository/domain and payment-safety work.

## Evidence Mapping

- `clarification.md#Backend` and `clarification.md#Generic` → this artifact's proposed-path boundaries, current payment/access map, and compatibility constraints.
- `project-profile.yaml#project` and `project-profile.yaml#notes` → this artifact's scope and no-infrastructure/no-router-change status.
- No architect design artifact was present in the task inputs; this report records current-state evidence and does not decide the target architecture.

## Current domain and ownership seams

| Domain concept | Current model / storage | Current role and relationship |
|---|---|---|
| Tenant | `Tenant` | Owner of routers, sites, packages, vouchers, transactions, and VPN peers. Tenant context scopes ordinary business queries. |
| Site and router | `Site`, `Router` | Router is tenant-owned and optionally assigned to a site. Router credentials are encrypted. Current router model includes existing MikroTik/API settings and optional `VpnPeer`; there is not yet a per-router access-mode field. |
| Agent control plane | `SiteAgent`, `SiteAgentCommand` | One paired site agent per tenant/site; the agent has a hashed bearer token and polls for commands associated with a router/session. Provisioning, expiry, telemetry, and device actions use this queue. |
| Package | `Package` | Tenant-owned sale/access terms: price/currency, duration, speed, device limit, data cap, publish state. `expiresAt()` computes access expiry; `routerProfileSettings()` generates a package-specific RouterOS HotSpot profile. |
| Voucher and customer | `Voucher`, `VoucherBatch`, `Customer` | Existing sale/entitlement path. A completed online sale creates one voucher and associates the phone with a customer during activation. |
| Access session | `Session` (`sessions_log`) | Tenant/site/customer/voucher/package/router-linked lifecycle record; statuses include `active`, `provisioning`, `disconnecting`, `expired`, and `disconnected`. The schema has byte/rate counters and client IP/MAC fields. |
| Sale and wallet accounting | `Transaction`, `WalletLedgerEntry` | `Transaction` records sale/provider state and fulfillment metadata. `WalletService::apply()` is the only supported tenant wallet balance writer and appends the corresponding ledger entry in the caller's transaction. |
| Refund | `Refund` | Refund request/approval records are tied to the sale. Customer payout is performed outside the application and represented by a platform-entered payout reference. Full approved refunds may revoke access; partial refunds retain it. |

`BelongsToTenant` applies a fail-closed tenant global scope; platform/provider flows that cross tenant context explicitly remove the scope and then need to bind the query back to the transaction's tenant. Payment flow metadata currently carries package, contact, and optional router/site hints rather than a separate entitlement aggregate.

## Current access fulfillment path

1. Portal voucher redemption enters `PortalController::redeem`; online purchase enters `PortalController::purchase`.
2. `VoucherActivationService::activate()` locks the tenant-scoped voucher, returns the extant active/provisioning session on repeat activation, checks voucher/package eligibility, resolves router/site from the provided router/site hints and existing voucher assignment, then marks the voucher redeemed and creates a session with package expiry.
3. If `config('site_agent.required')` is true, the resolved router must have a non-revoked site agent paired to its site. Activation creates the session as `provisioning` and queues `provision_user` through `SiteAgentCommandService`; otherwise the session starts `active` without an agent command.
4. `SiteAgentController::poll()` claims pending/stale commands and `complete()` records completion. The provision completion path calls `ProvisioningWatchdog::markProvisioned()`. Expiry/disconnect uses the analogous `expire_user` command and only clears pending revocation when acknowledged.
5. `ProvisioningWatchdog` retries or recreates failed provisioning commands, alerts operators, and may create a refund request when access cannot be provisioned. `RefundService` creates/approves refund accounting, then calls `AccessRevoker` for fully refunded sales when configured.

The global agent-required setting is currently the activation/expiry branch selector; its default is production-sensitive. It is not a router-level optional-path selector. Changing it globally would alter behavior for existing agent-managed routers and is explicitly outside this task.

## Payment and fulfillment path

- Existing purchase entry points are `POST /portal/{tenant?}/purchase` and tenant-subdomain `POST /portal/purchase`. The input includes a tenant package, phone, optional email, and optional router ID/name. The handler enforces the tenant selling policy, validates package/router ownership, normalizes phone identity for the purchase intent, serializes concurrent submissions with a cache lock, rejects an already-pending matching sale, and stores the pending `voucher_sale`.
- The sale stores `package_id`, phone, and optional router/IP/MAC hints in `meta`; `purchase_intent_hash` and a hash of the bearer `payment_token` are stored separately. The purchase-intent index is non-unique; request serialization/current duplicate protection is in application/cache logic.
- `PaymentService::initiatePackagePayment()` checks pending state, tenant/package/transaction relationship, amount equality, positive whole-number mobile-money prices, payment provider configuration and currency. Simulation is gated to explicit non-production local/testing configuration. The current initiation path selects Pesapal when configured, otherwise MTN or Airtel based on the normalized Uganda phone.
- Provider status can be processed through the provider callbacks or polling (`GET /api/payments/status`, plus the portal's transaction-bound check page). MTN/Airtel reconcile through `PaymentService::reconcilePendingPayment()` and the scheduled `payments:reconcile` command. Flutterwave has a signed webhook and provider-transaction verification flow. Pesapal has callback and webhook status lookup flows.
- The central successful-sale seam is `PaymentService::handleSuccessfulPackagePayment()`: it runs under tenant context and a DB transaction, locks the transaction, returns the already-recorded voucher if already completed, marks a pending sale completed, calculates the existing commission/net amount, credits wallet via `WalletService`, creates a voucher, records voucher metadata, and activates the voucher/session. Receipt SMS is attempted after the database transaction.
- Payment routes/contracts to preserve include `/api/webhooks/{pesapal,flutterwave,mtn,airtel}`, `GET /api/payments/status` and `POST /api/payments/activate`. The payment bearer token is accepted only through `X-Payment-Token`; persisted token values are hashes, not plaintext. Purchase and activate operations use idempotency middleware as currently routed.

### Extension points for the approved optional access path

1. **Keep one verified-sale fulfillment boundary.** Any additional entitlement/session behavior should be attached after the provider-specific success verification and use the existing transaction lock / wallet ledger transaction boundary; avoid duplicating provider callback logic.
2. **Make access selection explicitly per router and opt-in.** The current place that resolves a router and decides `active` versus `provisioning` is `VoucherActivationService::resolveRouter()` / `activate()`. Additive design should not globally bypass `site_agent.required`, change existing voucher sales, or re-route current agent-managed routers.
3. **Keep package sales and access entitlement terms distinct.** `Package` defines customer price, duration, speed, device, and data-limit terms; current RouterOS profile/user commands derive enforcement from those package terms. New backend policy should map to these terms without silently changing them.
4. **Use session identity for usage/disconnect reconciliation.** `Session` already carries tenant, site, router, package, client identity, lifecycle, and usage-counter slots. These slots are currently consumed by UI/reporting (`PortalPlanController`, operator dashboards/live-session views); no FreeRADIUS accounting ingestion/`radacct` path was found in application, routes, or migrations.
5. **Keep control-plane ownership separate.** Current site-agent authentication is a hashed token; commands are tenant/router/session-associated and acknowledged idempotently. New router credentials/accounting identities should not reuse the site-agent credential or mutate its command contract.

## Payment-safety observations for planning

These are current-state mapping observations, not a security review or release verdict:

- Flutterwave performs webhook hash validation, provider-side transaction verification, transaction-reference/provider-ID matching, and verified amount/currency checks before fulfillment.
- MTN/Airtel completion is based on provider-side verification, not the webhook's submitted status. The shared reconciler validates expected amount/currency/identity details to the extent required/present in each provider response.
- In the inspected Pesapal callback and IPN paths, the application queries Pesapal for transaction status and only reads the status description before calling shared fulfillment. Those code paths do not currently compare verified amount/currency to the local transaction. The lookup also accepts either merchant reference or provider tracking ID; downstream planning should preserve tenant binding and require a complete local/provider match before a sale can be completed.
- The wallet/ledger write and voucher/session creation are within the shared successful-sale transaction, but external provider verification occurs before that transaction. Future changes should retain the final locked pending-state check and make any newly persisted entitlement/accounting effects atomic and replay-safe.
- `sessions_log.bytes_in/bytes_out` and `rate_in/rate_out` are modeled and read for usage display, but no runtime writer/accounting feed was found in the inspected PHP application.
- `Package.radius_profile_name` exists, but current router profile provisioning uses `package-{id}` profiles; the generated hotspot profile explicitly sets `use-radius=no` (also asserted by `RouterProvisioningTest`). There is no current FreeRADIUS authentication or accounting integration to preserve as an existing live flow.

## Existing tests that anchor the extension boundary

Relevant suites present in the repository include:

- `MobileMoneyPaymentFlowTest` — provider initiation/reconciliation, verified fulfillment, repeat delivery, purchase deduplication, and token/session-bound status.
- `PesapalPaymentTest` — checkout, callback, and IPN fulfillment.
- `SiteAgentControlPlaneTest` and `ProvisioningWatchdogTest` — agent provisioning/expiry acknowledgement, retries, and recovery behavior.
- `WalletLedgerTest`, `RefundFlowTest`, and `ReconcilePendingPaymentsTest` — ledger invariants, refund accounting/revocation, and pending-payment recovery.
- `TenantIsolationTest` — tenant-scoped model/query behavior.

These suites were inspected as evidence of current invariants but not executed by t2; the assigned fresh baseline task is t5.

## Test Results

- Command: None (mapping-only task; application tests/build are not needed to validate a read-only artifact).
- Passed: n/a
- Failed: 0
- Skipped: Existing suite execution delegated to t5 for the fresh baseline.
- Migrations/default database: Not run.
