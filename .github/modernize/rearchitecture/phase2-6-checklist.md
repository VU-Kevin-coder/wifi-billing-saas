# Wi-Fi SaaS Architecture Checklist — Phases 2–6

**Status:** Phase 1 release gate failed; Phases 2–6 have not started.  
**Purpose:** Track the remaining work and evidence required at each phase gate.  
**Last updated:** 2026-11-14

## Guardrails for every phase

- [ ] Keep the existing site-agent path enabled and the default for existing routers.
- [ ] Preserve API contracts, tenant isolation, sold access terms, and financial records.
- [ ] Keep RADIUS opt-in per router; resolve the approved `access_mode=radius` versus implementation `access_backend=radius` naming mismatch before relying on the selector.
- [ ] Use only isolated lab/test systems. Never connect to or migrate an unknown/default or production database.
- [ ] Do not inspect or expose `.env`, `APP_KEY`, router keys, RADIUS secrets, payment credentials, or other secrets.
- [ ] Do not deploy, configure a production router, or cut over live users without separate explicit approval and a tested per-router rollback plan.
- [ ] Record exact commands, results, limitations, and evidence for each gate. A passing SQLite test is not evidence of target-database parity.
- [ ] Measure existing service baselines before setting SLOs; do not invent performance or availability targets.

## Entry gate — before Phase 2

- [ ] Reconcile the selector contract across clarification, code, migration, tests, and docs; retain `site_agent` as the default.
- [ ] Close the MEDIUM device-capacity race with atomic pending reservations or proven pre-Accept enforcement; test concurrent admissions.
- [ ] Close the MEDIUM mode-switch/revocation gap so active agent sessions remain revocable after a router changes backend; test switch, rollback, and retry cases.
- [ ] Identify the supported database engine/version from an authorized source. Do not infer it from secrets or connect to a production/default database.
- [ ] Provision or identify a separate disposable instance of that engine and validate additive migrations, constraints, locking, concurrency, and guarded rollback.
- [ ] Restore and verify the local Docker daemon. The last recorded probe failed because the Docker Desktop Linux engine was unavailable.
- [ ] Confirm the isolated Docker Compose + MikroTik CHR 7.24.6 lab image, licensing, host capabilities, and non-overlapping lab network.
- [ ] Obtain authoritative Airtel callback-field guarantees; preserve fail-closed payment verification.
- [ ] Define credential-loss rotation/revocation without adding a secret retrieval path.
- [ ] Verify the private Laravel policy-channel deployment design: trusted peer CIDRs, TLS/proxy topology, shared nonce cache, and actual service reachability.
- [ ] Re-run relevant focused tests, the full suite, build/smoke checks, browser tests, and target-engine migration/concurrency validation after remediations.
- [ ] Reassess and pass the binary Phase 1 release gate before beginning Phase 2.

**Current entry-gate evidence:** [Phase 1 gate report](./artifacts/t21-teamlead.md). Its verdict is FAIL. The local browser journey passed, but that does not clear the open architecture and environment blockers.

## Phase 2 — Hub and secure onboarding

**Status:** BLOCKED until the entry gate passes. Lab-only work; no production or live-router changes.

- [ ] Verify the isolated WireGuard hub and FreeRADIUS host configuration, supported patched versions, and private listener bindings.
- [ ] Design and test per-router identity binding: WireGuard peer address -> registered router -> site -> tenant; reject unknown, disabled, spoofed, and cross-tenant identities.
- [ ] Implement race-safe, unique tunnel address allocation and per-router key/secret lifecycle; encrypt secrets at rest and allow one-time delivery only.
- [ ] Restrict peer `AllowedIPs`; prove router-to-router traffic is denied, including within a tenant.
- [ ] Limit router-to-hub and hub-to-router ports to the specifically required RADIUS and management flows; prove RADIUS, database, and RouterOS API are not public.
- [ ] Implement audited peer add, revoke, rotation, stale-peer reconciliation, and conflict-safe rollback.
- [ ] Build read-only router discovery/preview that makes zero changes and reports conflicts before any approved operation.
- [ ] Test approved minimal configuration against the isolated CHR target; preserve unrelated router configuration and detect changed fingerprints.
- [ ] Test restart/reconciliation behavior for active, revoked, stale, and partially applied peer state.
- [ ] **Phase 2 gate:** demonstrate isolated CHR tunnel connectivity, private reachability, peer/router isolation, revocation persistence after restart, and safe rollback. Do not treat a WireGuard handshake as proof of RADIUS, management, or customer connectivity.

## Phase 3 — RADIUS authentication and access policy

**Status:** NOT STARTED; requires the Phase 2 gate.

- [ ] Implement the private authenticated Laravel policy API with a separate service credential; fail closed when authentication, policy, or backing data is unavailable.
- [ ] Bind each RADIUS request to the registered router using trusted source/peer identity, not customer-supplied tenant, NAS name, or MAC values.
- [ ] Verify request authentication and Message-Authenticator behavior on the supported FreeRADIUS and RouterOS versions.
- [ ] Prove the customer-to-router HTTPS login and RouterOS PAP-to-RADIUS flow on the isolated target; do not enable HTTP-PAP fallback.
- [ ] Document and test credential handling so voucher secrets, router keys, RADIUS shared secrets, and APP_KEY never appear in logs or diagnostics.
- [ ] Enforce voucher tenant/router/site scope, validity, revocation, package policy, rate limits, and idempotent retry behavior.
- [ ] Implement atomic session/device-slot reservation before Access-Accept; test concurrent requests, lost Accept/Start, duplicate retries, and uncertain reservations.
- [ ] Validate session timeout, rate limit direction, interim accounting interval, and byte-limit semantics on actual tested RouterOS versions.
- [ ] Preserve purchased entitlement terms using immutable snapshots; resolve activation/expiry semantics before changing any product behavior.
- [ ] Test invalid, expired, revoked, exhausted, disabled-router, wrong-secret, and cross-tenant credentials.
- [ ] Measure policy latency under representative load against the observed baseline.
- [ ] **Phase 3 gate:** authorized users browse through the isolated CHR path; rejected cases fail closed; tenant isolation, concurrency, attributes, retry behavior, and latency are evidenced. No production enablement.

## Phase 4 — Accounting, disconnects, and dashboard

**Status:** NOT STARTED; requires the Phase 3 gate.

- [ ] Define the sanitized accounting event schema and deduplication key using observed RouterOS attributes, including session-ID reuse/reboot behavior.
- [ ] Verify the existing retention and privacy policy before adding raw-event retention; do not invent a retention period.
- [ ] Implement durable acceptance/buffering of Start, Interim-Update, and Stop events with observable backlog and storage-pressure alerts.
- [ ] Test crash, restart, replay, disk-full, duplicate, reordered, delayed, missing, and malformed events.
- [ ] Calculate cumulative usage from counter deltas, octets and gigawords without double-counting or silently reopening finished sessions.
- [ ] Reconcile stale or ambiguous sessions with the router; represent unknown/stale observations as such rather than claiming disconnected.
- [ ] Implement targeted disconnect requests, retries, and verification of actual session disappearance; an ACK alone is not proof of disconnection.
- [ ] Verify RouterOS incoming CoA/Disconnect configuration and restrict it to the private RADIUS source.
- [ ] Show timestamps and truthful states in dashboards: router-confirmed active, last reported active, stale/unknown, disconnect pending, and usage-as-of.
- [ ] Keep site-agent revocation working for sessions created before any backend transition.
- [ ] **Phase 4 gate:** duplicates/reordering/restarts do not corrupt usage, durable-ingestion guarantees are demonstrated, disconnect is verified, and stale states are visible with timestamps.

## Phase 5 — Lab, canary, and migration

**Status:** NOT STARTED; requires the Phase 4 gate. The approved CHR target is lab-only and does not authorize production operations.

- [ ] Complete compatibility tests on CHR 7.24.6 and each additionally supported RouterOS version before claiming support.
- [ ] Prove a non-colliding test voucher authenticates, receives expected policy, accounts correctly, and can be revoked while browsing through the isolated lab.
- [ ] Test local-user precedence, existing RADIUS configuration, shared profiles, and object ownership; leave unknown/non-app-owned objects untouched.
- [ ] Verify backup creation and restoration on the lab target; protect backups and confirm recovery.
- [ ] Test partial-apply and operator-edit conflicts; rollback only app-owned values that remain unchanged from the approved fingerprint.
- [ ] Exercise outages and recovery for hub, RADIUS, policy API, database, router, accounting buffer, and management API.
- [ ] Verify queued/confirmed/ready/needs-attention portal states against injected payment and fulfillment failures; no live payment-provider or customer transaction.
- [ ] Reconcile payment, entitlement, accounting, and agent-session records after simulated cutover and rollback.
- [ ] Produce operator-reviewed, per-router change and rollback runbooks plus evidence from the isolated lab.
- [ ] Measure baseline and test-rollout latency, throughput, availability, and recovery; define SLOs only after evidence exists.
- [ ] **Lab gate:** all acceptance evidence and rollback checks pass before proposing any production canary.
- [ ] **Production authorization gate:** obtain separate explicit approval naming the exact router(s), change window, operator, rollback trigger, and verified rollback plan. No approval is implied by this checklist or by the earlier lab selection.

## Phase 6 — Production hardening and expansion

**Status:** NOT STARTED; requires Phase 5 lab gate and separate approvals for production actions.

- [ ] Define service objectives from measured baselines and agreed business requirements.
- [ ] Load-test policy, RADIUS, accounting, and management paths at expected and burst traffic levels.
- [ ] Establish monitoring for tunnel/service health, authentication latency and rejects, stale accounting, backlog, storage, scheduler/workers, failed disconnects, certificates, and backup status.
- [ ] Practice encrypted backup restore, disaster recovery, incident response, and credential/key rotation.
- [ ] Define and test an independently recoverable hub/RADIUS/database topology if availability requirements justify it; do not call same-host redundancy high availability.
- [ ] Verify patched dependency and image versions, security advisories, access controls, retention/privacy, audit coverage, and secret isolation before each release.
- [ ] Expand only to hardware, RouterOS versions, and package types that passed the compatibility matrix.
- [ ] Keep non-migrated routers and legacy vouchers on the existing agent path until explicitly approved and safely reconciled.
- [ ] Review each expansion cohort with payment/accounting reconciliation and tested rollback.
- [ ] **Phase 6 gate:** operational objectives, recovery evidence, monitoring, rotation, security posture, and supported-device evidence are approved before any expansion.

## Progress log

| Date | Phase / gate | Result | Evidence / follow-up |
|---|---|---|---|
| 2026-11-14 | Phase 1 release gate | FAIL | See [t21 report](./artifacts/t21-teamlead.md); Phase 2–6 remain not started. |
