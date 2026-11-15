# RADIUS Credential Rotation and Rollout Runbook

This runbook describes a controlled, per-router rollout and credential recovery
process. It does not authorize production database access, infrastructure
changes, router changes, deployment, or live cutover. Confirm the supported
database engine and obtain the required change approval before using it.

## Credential boundaries

- Each router/NAS uses a distinct, high-entropy RADIUS shared secret generated
  by the Laravel operator workflow. The secret is encrypted at rest by the
  `Router` model's encrypted cast, is hidden from normal serialization, and is
  shown only once in the authorized no-store response. Do not copy it to logs,
  tickets, chat, shell history, screenshots, or ordinary configuration exports.
- The RADIUS adapter authenticates to Laravel with the separate
  `RADIUS_LARAVEL_SERVICE_SECRET`. It must not reuse a router secret,
  site-agent token, payment credential, or Laravel encryption key.
- Laravel encryption uses `APP_KEY`; `APP_PREVIOUS_KEYS` is available for the
  framework's key-transition support. Never disclose or reuse either value.
  Protect them independently: losing or changing the active encryption key
  without a tested key-transition plan can make stored router credentials
  unreadable.

## Source-backed configuration names

Configure values only through the approved secret/configuration manager for
the target environment. The repository defines these variable names; this
runbook intentionally supplies no values:

- `RADIUS_LARAVEL_SERVICE_SECRET`
- `RADIUS_POLICY_ALLOWED_SOURCE_CIDRS`
- `RADIUS_POLICY_NONCE_CACHE_STORE`
- `RADIUS_POLICY_CLOCK_SKEW_SECONDS`
- `RADIUS_POLICY_LEASE_SECONDS`
- `RADIUS_POLICY_RETRY_SECONDS`
- `APP_KEY`
- `APP_PREVIOUS_KEYS`
- `SITE_AGENT_REQUIRED`

The policy channel requires HTTPS, request HMAC/timestamp/nonce validation, a
shared replay cache, and a production direct-peer CIDR allowlist. Verify the
actual private routing, TLS termination, direct peer, and cache behavior in the
approved environment; these settings alone do not prove network isolation.
Keep `SITE_AGENT_REQUIRED` and its existing fail-safe behavior unchanged.

## Initial per-router enablement

1. Confirm tenant ownership, router identity, approved maintenance window,
   supported database engine, current site-agent connectivity, and a verified
   rollback contact. Do not infer the database engine from local test settings.
2. Verify the private adapter-to-Laravel channel and policy service identity
   independently of router/NAS credentials. Do not expose the policy API on a
   public listener.
3. Keep the router on its existing `access_mode=site_agent` default while
   preflight checks run. Do not modify package prices, access duration, rate,
   data, or device limits.
4. An authorized tenant administrator provisions one router through the
   protected one-time credential workflow. Verify the NAS identifier and
   deliver the generated router secret directly to the approved operator. The
   page is not a retrieval endpoint: once left, the secret cannot be shown
   again. Store it only in the approved secret manager and transfer it to the
   NAS/adapter through the organization's protected change channel.
5. Validate both ends use the same per-router secret and canonical NAS
   identifier. Do not reuse a secret across routers. Never put the secret in
   `APP_KEY`, the service secret, agent settings, source control, or support
   logs.
6. Enable only the approved router with the per-router RADIUS opt-in workflow.
   Preserve the `site_agent` default for every other router. Confirm the
   resulting router selector is `access_mode=radius` before any access test.
7. Test a non-production entitlement and verify: signed authorization
   succeeds; a rejected or unavailable policy call fails closed; Start binds
   the reservation; Stop releases it; duplicate authorization/accounting is
   idempotent; device limits include pending reservations; and disconnect
   acknowledgement alone does not mark a session disconnected.
8. Observe accounting, pending revocations, and adapter errors for the
   approved observation window. Do not claim a session is active before Start
   evidence or disconnected before a validated Stop.

## Rotation, revocation, and recovery

1. **Never retrieve the existing router secret.** The application deliberately
   rejects repeat issuance and the normal model representation hides it.
2. If the secret is suspected lost or exposed, treat it as compromised.
   Coordinate an approved maintenance window and prevent new RADIUS
   authorization for that router using the approved per-router change process.
   Preserve outstanding RADIUS-session accounting and revocation work; do not
   erase pending reservations or session identity history.
3. Create and approve a rotation/recovery change that replaces the router
   credential on both the NAS and the encrypted Laravel record, then verifies
   a new signed/authenticated policy exchange and accounting lifecycle. Do not
   hand-edit stored ciphertext or bypass the one-time authorization workflow.
4. The current operator workflow implements initial one-time issuance, not a
   repeatable rotation endpoint. If that approved rotation path is unavailable,
   stop and escalate for a reviewed rotation implementation; do not attempt
   recovery by reading the database, reusing the old secret, deleting the
   encrypted value, or issuing a replacement through an unapproved path.
5. Revoke the old credential at both ends only after the new credential has
   been verified and the rollback window has closed. If compromise cannot
   wait, disable the router's RADIUS opt-in through the approved controlled
   process, preserve existing session snapshots, and reconcile or keep
   revocation pending until authoritative Stop evidence arrives.
6. For loss of `RADIUS_LARAVEL_SERVICE_SECRET`, rotate that service identity
   independently of all router secrets. Coordinate the protected caller and
   Laravel setting, validate nonce/replay behavior and private reachability,
   and retain no shared credential in logs. Do not use Laravel's `APP_KEY` as
   a substitute.
7. For loss or planned change of `APP_KEY`, use a separately tested Laravel
   encryption-key transition and verify every encrypted credential remains
   decryptable before removing any previous key. This is not a RADIUS secret
   rotation and must not be combined with router credential replacement
   without a dedicated recovery plan.

## Rollback and staged return to site-agent

1. Stop new RADIUS authorization for the one affected router using the approved
   per-router change. Do not disable the global site-agent requirement or
   silently fall back between adapters.
2. Existing sessions retain the `access_mode` snapshot used for access.
   Continue routing site-agent-created sessions through the agent and
   RADIUS-created sessions through RADIUS accounting/disconnect, even if the
   router's current mode changes. Keep unresolved reservations and
   disconnect commands until a validated Stop or safe reconciliation.
3. For a RADIUS session still only provisioning, perform an explicit approved
   rollback to `site_agent` and verify that the existing session is resumed
   without creating another voucher/session or duplicate command. Do not
   rewrite the backend snapshot of an established RADIUS session.
4. Verify the agent command is delivered and confirmed; verify RADIUS sessions
   remain revocable and that retries reuse their existing command identity.
   A delivery acknowledgement is not proof of disconnection.
5. Reconcile all active/pending sessions before any credential or schema
   removal. Do not run a destructive migration rollback while router/session
   configuration, reservations, accounting evidence, or disconnect work is
   populated. Database rollback requires a reviewed backup/recovery plan and
   validation against the confirmed engine.

## Rollout blockers and verification boundary

- Application migrations and row-lock behavior still require validation on the
  confirmed supported database engine. Automated SQLite `:memory:` tests do
  not prove target-engine locking or concurrent authorization safety.
- The current source provides protected initial router-secret issuance, but no
  repeatable router-secret rotation endpoint or dedicated operator mode-switch
  UI. Do not represent those operations as automated until that capability is
  implemented and tested.
- Verify deployment private-network reachability, TLS/proxy behavior, shared
  nonce-cache operation, and production credential provisioning separately.
- Never use a production/default database for focused tests. The approved
  automated test target is SQLite `:memory:` only.
