# t10 — Approved Adapter and Accounting Policy Decisions

## Decision

For the optional router-initiated WireGuard + FreeRADIUS path, FreeRADIUS is a protocol adapter to Laravel-owned policy and state. It has no direct database access and does not become an entitlement, payment, or accounting authority. The boundary is a private, authenticated Laravel policy API reachable only over a protected channel. Existing public APIs and the site-agent protocol remain unchanged.

## Adapter and identity boundary

| Concern | Decision |
|---|---|
| Policy authority | Laravel resolves the router, derives its tenant, validates voucher/session eligibility, applies current package terms, and persists accounting/disconnect state. FreeRADIUS enforces the returned decision; it cannot grant access from an independent entitlement cache. |
| Network boundary | Add a private internal API for the RADIUS adapter, protected by TLS on a restricted service channel and a dedicated RADIUS-to-Laravel service credential. Authenticate requests with request integrity and replay protection; the exact middleware/cryptographic profile is subject to t11 security approval. Never use or expose `APP_KEY`. Do not expose these routes publicly or reuse site-agent/payment credentials. |
| Router authentication | FreeRADIUS authenticates each NAS using its unique router-specific RADIUS shared secret. Laravel separately authenticates the FreeRADIUS service caller. The API resolves the router from a canonical, unique NAS identifier that FreeRADIUS supplies only after validating the NAS; it then derives `tenant_id` from that router record. The API accepts no caller-supplied tenant ID and never derives tenancy from voucher username or accounting attributes. |
| Router secret lifecycle | Generate a distinct high-entropy secret per router; encrypt recoverable material at rest in Laravel; exclude it from logs, ordinary router responses, and accounting data. Present it once to an authorized operator during the separately authorized configuration workflow. If it is lost, rotate it rather than retrieving it again. The operator later configures the matching NAS and FreeRADIUS client; no router or infrastructure action is authorized by this decision. |
| Backend selection | Only an explicitly configured router may use RADIUS. Existing routers remain on `site_agent`; retain `SITE_AGENT_REQUIRED` and its current behavior. Missing identity, invalid credentials, or an unavailable selected backend fails closed; never silently fall back between adapters. |

## Internal API contract

These are new private adapter operations, not changes to existing application routes:

1. **`POST /internal/radius/v1/authorize`** — input contains the verified NAS identifier, voucher login material, and only the client attributes required for policy. Laravel looks up that router and tenant, validates the voucher and existing session/entitlement against current sale/package terms, and returns a minimal `Access-Accept` policy (or `Access-Reject`). Do not accept tenant identity from the request, log voucher credentials, or persist raw credentials. A missing, ambiguous, expired, revoked, exhausted, wrong-router, or otherwise unverifiable grant is rejected. An API timeout/error is not an authorization result and must not be converted to an accept.
2. **`POST /internal/radius/v1/accounting-events`** — input is a normalized Start, Interim-Update, or Stop event with NAS identifier, NAS session identity, event/session times, cumulative input/output octets and gigawords when supplied, termination cause, and only needed client-address evidence. Tenant is derived from the mapped router. The endpoint returns an idempotent receipt or a non-sensitive rejection/quarantine result. It does not accept unrestricted packet attributes or secrets.
3. **`GET /internal/radius/v1/disconnect-commands`** and **`POST /internal/radius/v1/disconnect-commands/{command_id}/ack`** — the adapter claims durable disconnect work and reports command delivery/result. Commands are router/session scoped by Laravel; no router shared secret is returned by this API. A command acknowledgement records delivery/response only, not verified customer disconnect.

All operations use one dedicated service identity, with authorization restricted to these operations. Request authentication must bind method, path, body, and a unique request nonce; repeated valid requests must be safe. Exact key rotation, accepted clock skew, rate limits, and nonce storage details remain for t11 security review and the later deployment design. Existing `/api/site-agent/*`, `/api/payments/*`, provider callbacks, and their request/response semantics are frozen.

## Accounting, lifecycle, and recovery policy

- **Authoritative records:** retain existing Laravel `Voucher`, `Package`, `Session`, `Transaction`, and `WalletService` ownership. A RADIUS accounting projection and event evidence support reconciliation; neither becomes an entitlement source or wallet writer. Persist confirmed payment, voucher/session, and required durable fulfillment state within the existing locked/idempotent fulfillment boundary. Do not hold a DB transaction open over API, RADIUS, or NAS calls.
- **Idempotency:** calculate a server-side fingerprint from canonical normalized event fields and bind it to the authenticated router and accounting-session generation. Repeated delivery of the same event is a no-op returning the original receipt. Update a session projection only from a new, accepted, chronologically applicable event. Counters are cumulative snapshots, not deltas to sum; combine octets/gigawords as applicable and never decrease accepted usage on a stale or malformed update.
- **NAS session identity reuse:** `(router, NAS session ID)` identifies a live session, not a forever-unique session. A later Start after a terminal Stop must create a distinct generation. Later Interim/Stop packets bind only to the matching open generation; ambiguous or out-of-order events are quarantined for reconciliation and cannot reopen or mutate a stopped generation. The schema must represent generations and scope event fingerprints to them.
- **Truthful status:** payment completion and access activation remain separate. An Access-Accept alone does not establish an active session; only accepted accounting evidence (normally Start) can reconcile it to active. Until then retain the existing pending/provisioning/activation-pending semantics. A disconnect request, command delivery, or NAS acknowledgement alone does not prove disconnection; keep the session `disconnecting`/pending until a valid Stop or authoritative reconciliation confirms it.
- **Disconnect and retries:** persist a disconnect intent/outbox record before the adapter attempts delivery. Retries reuse the same command identity and do not duplicate or reverse lifecycle transitions. Reconcile nonterminal sessions and unconfirmed disconnects from later authenticated accounting evidence. On API or NAS failure, preserve pending state, retry safely, and surface the existing truthful pending/failure status; never report success solely because a request was queued.
- **Failure containment:** authorization fails closed when policy cannot be checked. Accounting delivery retries the same normalized event identity; duplicate acknowledgements are safe. Unknown router/session mappings remain unbound and quarantined, with no cross-tenant mutation. Do not fall back to site-agent provisioning or silently rewrite the access backend.
- **Retention/data minimization:** do not retain raw RADIUS packets or add a new raw-event TTL/cleanup policy. Persist only the normalized fields needed for idempotency, usage, lifecycle, audit, and recovery. The existing platform retention rule must be located and verified before applying it to these records; this repository search found no applicable retention duration/policy. Until verified, do not add a new retention job or destructive purge. Preserve populated accounting evidence during rollback.

## Payment and compatibility invariants

- Provider-side verification remains the only settlement authority; verified tenant, transaction reference, package, amount, and currency must match before fulfillment.
- Use the current transaction lock, `WalletService::apply()`, voucher creation, and activation seam. Replays return/continue the original fulfillment and cannot issue a second voucher, entitlement, or wallet credit.
- Preserve package prices, currency, duration, rate, byte, and device terms; do not make a RADIUS login or accounting packet change a sale term.
- Preserve current web/API contracts, token handling, agent pairing/command semantics, UI stack, and agent-managed router behavior. Customer status uses existing contract meanings; no new success claim is made before confirmed access.

## Schema and implementation handoff

The t6 proposal's accounting session/event tables remain appropriate only with these refinements before implementation:

1. Add a durable disconnect-command/outbox record (or an existing equivalent proven by source) with stable command identity, router/session binding, claim/retry state, and delivery result. It must not be mistaken for disconnect confirmation.
2. Represent NAS-session generations so a reused NAS session ID after Stop cannot collide with the prior terminal row. Scope uniqueness and event fingerprints to the generation; do not rely on `(router_id, nas_session_id)` as a lifetime key.
3. Store normalized accounting facts only; no raw packet/secret fields or unverified retention cleanup.
4. Keep explicit composite tenant/router/session constraints and fail-closed application checks. The production database engine/version is unconfirmed; only existing automated SQLite `:memory:` tests are approved until identified.
5. Keep migration execution and production-DB-specific constraint/locking validation blocked pending separate database approval. No backfill of historical payment, voucher, router, session, or accounting facts without verified evidence.

## Release and phase boundary

This decision authorizes repository/domain work only. It authorizes no Phase 2 infrastructure or hub onboarding, deployment, production configuration, router operation, live cutover, or global agent-gate change. Lab Docker Compose and MikroTik CHR 7.24.6 remain future isolated-lab choices. Stop at the Phase 1 release gate and await separate approval before Phase 2.

## Verification / Test Results

- Command: None; this is a documentation-only decision record.
- Passed: n/a
- Failed: 0
- Skipped: No tests applicable; no application files, configuration, or migrations were changed or executed.
- Evidence reviewed: clarification decisions; t9 database decision; t1 architecture; t2 domain/payment map; t6 additive schema proposal; repository documentation search for retention.

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/clarification.md` — approved stack, preserved contracts/terms, private authenticated policy API, secret separation, retention constraint, and Phase 1-only boundary.
- `.github/modernize/rearchitecture/artifacts/t9-dba.md` — production DB remains unknown; only existing PHPUnit SQLite `:memory:` tests are approved.
- `.github/modernize/rearchitecture/artifacts/t1-architect.md` — Laravel authority, per-router opt-in, payment/access separation, tenant derivation, and no agent-gate disablement.
- `.github/modernize/rearchitecture/artifacts/t2-backend.md` — current locked fulfillment seam, voucher/session lifecycle, payment contracts, and agent command behavior.
- `.github/modernize/rearchitecture/artifacts/t6-dba.md` — proposed additive accounting schema, replay constraints, rollback rules, and unresolved session-ID reuse/disconnect/retention issues.
- `.github/modernize/rearchitecture/board.md` — read-only task/phase status and user-approved guardrails.
- `docs/` and `.github/modernize/rearchitecture/` retention search — no applicable platform retention duration/policy was found.

## Evidence Mapping

- `clarification.md#Backend` and follow-up decisions → protected private Laravel policy API, separated router/service secrets, no `APP_KEY`, no raw-event retention pending policy verification.
- `clarification.md#Generic` → truthful activation, durable/idempotent fulfillment and accounting, preserving agent routers and all existing contracts, and explicit Phase 1 stop.
- `t9-dba.md#Decision` and `#Downstream Guardrails` → production engine unknown; SQLite in-memory tests only; migrations and engine-specific validation remain blocked.
- `t1-architect.md#Backend selection and identity`, `#Payment and fulfillment`, and `#RADIUS authorization and accounting` → per-router fail-closed routing, authenticated-router tenant resolution, atomic fulfillment, and accounting evidence rules.
- `t2-backend.md#Current access fulfillment path` and `#Payment and fulfillment path` → use existing Voucher/Session/PaymentService/WalletService behavior without changing agent flow or public API contracts.
- `t6-dba.md#RADIUS accounting identity and evidence`, `#Rollback and irreversible effects`, and `#Open risks / decisions required` → normalized replay-safe evidence, protected rollback, and required schema refinements for disconnect durability and NAS-session ID reuse.
