# t11 — Secret-Isolation and Least-Privilege Boundary Decision

## Decision

**APPROVED for Phase 1 repository/domain implementation, subject to the controls and verification gates below.** The proposed private authenticated Laravel policy API is the correct boundary: Laravel remains the only policy, tenant, payment, entitlement, and accounting authority; FreeRADIUS is a narrowly scoped protocol adapter with no direct database access.

This is an architectural security approval only. It does not approve production or external network exposure, database/migration execution, infrastructure setup, deployment, router configuration, real-router operations, cutover, or Phase 2 onboarding. Existing site-agent behavior, public APIs, package/sales/access terms, and `SITE_AGENT_REQUIRED` remain unchanged.

## Approved credential boundaries

| Credential/material | Owner and purpose | Required isolation |
|---|---|---|
| Per-router RADIUS shared secret | One router/NAS and its FreeRADIUS client authentication | Generate independently with a cryptographically secure generator and sufficient entropy. Encrypt recoverable material at rest in Laravel. Reveal only once to an authorized operator through a protected, no-store workflow. Do not return it from ordinary router/API responses, the policy API, or accounting records. If lost, rotate it; do not provide a retrieval path. Rotation/revocation must affect only that router. |
| RADIUS-to-Laravel service credential | FreeRADIUS service authenticates to the private policy API | Use an independent high-entropy secret, not a router secret, site-agent token, payment token, or `APP_KEY`. Use TLS on a restricted private service channel. Authenticate each request with a keyed integrity profile binding at least HTTP method, canonical path, body digest, timestamp, and unique nonce; reject stale timestamps and reused nonces. Mutual TLS may add defense in depth. Define revocation/rotation before enabling the service. |
| Laravel `APP_KEY` / encryption key material | Laravel application cryptography | Never transmit, mount, or disclose it to FreeRADIUS, a router, an API client, or the service credential configuration. It may be used only inside Laravel's approved at-rest encryption mechanism. Key backup/rotation and encrypted-secret recoverability must be handled within Laravel's existing key-management process; do not log key material. |
| WireGuard peer keys | Existing/new per-router tunnel identity | Keep keys router-specific; never reuse RADIUS or service credentials as WireGuard keys. Preserve existing encrypted-key handling in Phase 1. Do not expose private peer material through the RADIUS policy API or logs. Any ownership/generation change must be reviewed by architecture before implementation. |

The shared service identity may serve more than one tenant only because the adapter is the trusted boundary. It must not be a general-purpose Laravel account: authorize only the internal authorize, normalized accounting-event, and disconnect-command operations; deny all other routes and methods by default. It must not have database credentials or permission to read/write Laravel tables directly.

## Identity, authorization, and data rules

1. FreeRADIUS may submit a canonical NAS identity to Laravel only after it has authenticated the NAS using that router's RADIUS secret. Laravel maps that identity to exactly one explicitly opted-in router and derives the tenant from the stored router relationship. The API must reject caller-supplied tenant identity and must never derive tenancy from voucher credentials, username, client IP/MAC, or accounting attributes.
2. Missing, invalid, ambiguous, revoked, or unrecognized router identity fails closed. An unavailable or invalid policy response is not an authorization result; it must not become an `Access-Accept` or silently fall back to the site-agent path.
3. Keep the API private and authenticated, with default-deny middleware and route-level service authorization. Return only the minimum policy needed to enforce a decision. Do not return the router's shared secret, `APP_KEY`, payment tokens, wallet/payment data, or unrelated tenant/customer data.
4. Persist normalized accounting evidence only. Do not log or persist raw RADIUS packets, voucher passwords, shared secrets, service credentials, or unrestricted attributes. Redact secrets from application, access, tracing, exception, and debug logs. Retain no new raw-event data or cleanup schedule unless the existing platform retention rule is verified and separately applied.
5. Authentication, accounting, and disconnect operations remain idempotent and router/session scoped. A disconnect delivery acknowledgement is not proof of disconnection. Existing payment verification and the transaction-locked fulfillment path remain authoritative; RADIUS cannot change payment, wallet, entitlement, or package terms.
6. Preserve current site-agent identity, credentials, endpoints, and behavior. RADIUS opt-in is explicitly per router; do not disable the global agent requirement, repurpose site-agent credentials, or introduce cross-adapter fallback.

## Required implementation/review checks

Before any later enablement, implementation and tests must demonstrate:

- The router secret is high-entropy, separately encrypted at rest, absent from database plaintext, serialization/API responses, normal logs, exception traces, and accounting data; authorized one-time delivery succeeds once and subsequent retrieval is denied.
- Unauthorized operators cannot generate/reveal/rotate/revoke secrets. Rotation or revocation invalidates only the selected router's prior credential and does not change neighboring routers or agent-managed routers.
- The service credential is different from all router/site-agent/payment credentials and `APP_KEY`. Missing/bad signatures, wrong method/path/body, expired timestamps, replayed nonces, and wrong service identity are denied; valid requests can reach only the approved internal operations.
- Tenant spoofing, unknown/ambiguous NAS identity, a non-opted-in router, revoked credentials, cross-tenant/session binding, and policy API failure all fail closed. No direct FreeRADIUS database access or public policy route exists.
- Logs and telemetry redact credential-bearing inputs and responses. API response schemas contain only minimal authorization/accounting/disconnect data.
- Existing site-agent, payment, API, and access-term regression tests still pass. New tests use only the already-approved automated SQLite `:memory:` test database. Do not execute migrations or connect to a production/unknown database as part of this decision.

The per-router/operator workflow, exact Laravel middleware implementation, key-management integration, and WireGuard key ownership remain implementation/design details to verify; none authorizes infrastructure or router operations. The independent Phase 1 security review remains required after implementation.

## Findings and disposition

- **Confirmed HIGH/CRITICAL findings in this design review: 0.** The review concerns the proposed boundary and its artifacts; it is not a source-code audit of a new implementation.
- **Decision condition:** t10 leaves the request-integrity profile and replay controls to security approval. This artifact resolves that design gap by requiring protected TLS transport plus a dedicated service credential that authenticates integrity-bound requests with timestamps and unique nonces. Backend/architect must reflect and test this contract before an adapter is considered implementation-complete.
- **Residual verification:** the actual private-network restrictions, TLS/mTLS configuration, encryption/key-management integration, one-time delivery control, log redaction, and operational rotation procedure are not implemented or verified here. These remain release-gate checks, not permission to configure production.
- **WireGuard key ownership:** preserve the existing handling; architect must confirm how any new router-initiated path obtains/rotates per-router peer keys without exposing them to the RADIUS policy API.

## Test Results

- Command: n/a — documentation-only decision review; no application code or configuration changed.
- Passed: n/a
- Failed: 0
- Skipped: Automated tests not run; none apply to this design-only task. Implementation controls and regression suites remain required downstream.

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/artifacts/t10.1-dba.md` — generation/outbox schema boundaries, router-derived tenancy, normalized evidence, no direct database access, and operational limits.
- `.github/modernize/rearchitecture/artifacts/t10-architect.md` — approved API boundary, identity mapping, per-router secret lifecycle, API operations, default-deny behavior, and Phase 1/Phase 2 restrictions.
- `.github/modernize/rearchitecture/artifacts/t6-dba.md` — existing router credential patterns, additive schema expectations, and no secret access for a RADIUS database principal.
- `.github/modernize/rearchitecture/artifacts/t4.5-security.md` — existing payment/tenant protections and pre-existing encrypted WireGuard-key handling.
- `.github/modernize/rearchitecture/clarification.md` — user-approved private API, credential separation, no `APP_KEY` exposure, SQLite-only test posture, and prohibited operational actions.
- `.github/modernize/rearchitecture/board.md` — read-only task and phase status.

## Evidence Mapping

- `t10-architect.md#Adapter and identity boundary` and `t10.1-dba.md#Decisions` → separate router and service credentials, encrypted router secret, no `APP_KEY` exposure, authenticated-router identity, router-derived tenancy, and no direct FreeRADIUS database access.
- `t10-architect.md#Internal API contract` → route/method-limited service identity, request integrity and replay protection, minimal policy response, no public API changes, and fail-closed authorization.
- `t10.1-dba.md#radius_accounting_events — append-only normalized evidence` and `#Generation and Event Processing Rules` → normalized evidence only, no secrets/raw packets, and no cross-tenant/session mutation.
- `t6-dba.md#Tenant-safe database access` and `#Rollback and irreversible effects` → no broad/direct RADIUS database principal, no entitlement/payment mutation, and protected per-router rollback boundaries.
- `t4.5-security.md#Review evidence` → existing encrypted WireGuard peer-key precedent; this decision preserves that handling and prohibits disclosure through the new RADIUS API.
- `clarification.md#Backend` and `#Generic` → local/private API preference, independent credentials, no `APP_KEY` exposure, SQLite-only test database, unchanged existing behavior, and no Phase 2/production/router actions.
