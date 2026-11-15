# Wi-Fi SaaS Architecture Checklist — Phases 2–6

**Status:** Phase 1 release gate remains failed. Implementation fixes and local regressions are in place, but PostgreSQL concurrency proof and isolated lab validation remain blocked. Phases 2–6 have not started.
**Purpose:** Track the remaining work and evidence required at each phase gate.  
**Last updated:** 2026-11-15

## Phase 1 blocker-removal steps

Execute these steps in order. Use only a disposable local/CI database and an
isolated lab. Do not point commands at Render production, a developer's default
database, or a live router. Save command output and CI run links as gate evidence;
never include credentials, `.env` contents, or secret values.

1. **Keep the scope current.** The former Airtel transaction-status contract
   check is obsolete: direct Airtel/MTN integrations have been removed in favor
   of ObPay, PawaPay, and Xente. Do not add a callback or relax fail-closed
   verification to satisfy the old item. Verify provider status contracts with
   authorized sandbox/merchant information in the Phase 5 payment-flow gate,
   without making live payments.
2. **Confirm test target isolation.** Use the CI-defined PostgreSQL 16 service
   (`testing` database) or a newly created disposable PostgreSQL 16 instance.
   The test harness requires `phpunit.ci-db.xml`, a local DB host, and a database
   named exactly `testing`; do not run it against a shared or production host.
   The CI workflow already provisions this service and invokes
   `vendor/bin/phpunit -c phpunit.ci-db.xml`. Confirm the supported Render
   PostgreSQL major version from authorized service configuration; do not query
   production data or assume it matches CI.
3. **Run and inspect PostgreSQL CI.** Run the `Tests (PostgreSQL 16)` workflow on
   the working branch. Require a green result for the full suite, including
   `tests/Concurrency/RadiusDeviceCapacityConcurrencyTest.php`. Confirm the
   two simultaneous authorizations cannot exceed the device limit and that the
   migration/constraint and rollback-guard tests pass. A SQLite-only pass is
   insufficient. If CI fails, fix the root cause and rerun; do not mark the gate
   complete from a queued or partial job.
4. **Verify the rollback protections.** Confirm the PostgreSQL run includes
   `tests/Feature/Phase1DomainMigrationTest.php`, especially selector data
   round-trip and refusal to roll back when RADIUS sessions or reservations
   exist. Any manual migration/rollback experiment must use a separate,
   disposable `testing` database; do not run destructive migration commands
   against any other database.
5. **Restore and verify the lab host.** Start/repair Docker Desktop, then check
   `docker version`, `docker info`, and `docker compose version`. Stop here if
   the daemon is unavailable or reports an unexpected context/engine. Do not
   use Docker Compose's default MySQL volume as the PostgreSQL test database.
6. **Preflight the isolated CHR lab.** Confirm the `evilfreelancer/docker-routeros:7.24.6`
   image is obtainable and permitted for the intended test, CHR licensing and
   host virtualization/TUN capabilities are available, and the lab subnet does
   not overlap with VPN, LAN, or other test networks. Verify the Compose
   network and ensure management ports remain loopback-only. Do not connect
   CHR to a customer or production network.
7. **Close the private policy-channel design.** Document and review the trusted
   source CIDRs, TLS termination/proxy trust, private routing, shared nonce
   cache, service identity, and expected reachability. This is a design/preflight
   review only; do not expose the policy endpoint or change production firewall
   rules.
8. **Record the credential recovery boundary.** The
   [rotation runbook](../../../docs/ROTATION-RUNBOOK.md) defines initial
   one-time issuance and a safe stop/escalation path for lost or exposed
   credentials; it does not provide an automated repeatable rotation endpoint.
   Keep that limitation explicit and do not create a secret-retrieval path.
   Deployment-specific rotation verification remains a later operational gate.
9. **Run final regression checks after any remediation.** Require the relevant
   focused tests, `php artisan test`, `vendor/bin/pint --test`,
   `git diff --check`, the CI front-end build (`npm ci` then `npm run build`),
   and the documented browser journey to pass. Record warnings separately from
   failures and investigate any new failure; do not treat warning-only SQLite
   output as PostgreSQL evidence.
10. **Reassess the binary gate.** Update the checklist and
    [Phase 1 gate report](./artifacts/t21-teamlead.md) with exact evidence,
    unresolved risks, and the CI run. The coordinator must explicitly record
    PASS before Phase 2 starts. Until then, Phases 2–6 remain blocked.

## Guardrails for every phase

- [ ] Keep the existing site-agent path enabled and the default for existing routers.
- [ ] Preserve API contracts, tenant isolation, sold access terms, and financial records.
- [ ] Keep RADIUS opt-in per router using canonical `access_mode`; preserve `site_agent` as the default and legacy `access_backend` only for migration compatibility.
- [ ] Use only isolated lab/test systems. Never connect to or migrate an unknown/default or production database.
- [ ] Do not inspect or expose `.env`, `APP_KEY`, router keys, RADIUS secrets, payment credentials, or other secrets.
- [ ] Do not deploy, configure a production router, or cut over live users without separate explicit approval and a tested per-router rollback plan.
- [ ] Record exact commands, results, limitations, and evidence for each gate. A passing SQLite test is not evidence of target-database parity.
- [ ] Measure existing service baselines before setting SLOs; do not invent performance or availability targets.

## Entry gate — before Phase 2

- [x] Reconcile the selector contract across clarification, code, migration, tests, and docs; retain `site_agent` as the default. Canonical application field is now `access_mode`; the follow-up migration copies legacy `access_backend` values.
- [x] Add durable pending device reservations before Access-Accept; same-NAS retries are idempotent, Start binds, and only validated Stop releases. Focused SQLite tests cover capacity and rejected-policy reservation behavior.
- [x] Snapshot `access_mode` on sessions and route revocation/retries from that snapshot so a router mode change does not reroute old sessions.
- [x] Fix configured-database test flag parsing: PHPUnit normalizes XML `true` to `1`; both test bootstraps now use `FILTER_VALIDATE_BOOLEAN`. Target-engine execution is still required.
- [ ] Prove actual simultaneous admission behavior on PostgreSQL CI; a two-process concurrency test is now included in `phpunit.ci-db.xml`, but has not yet run against PostgreSQL. Local SQLite locking is not evidence of row-lock concurrency.
- [x] Confirm database engine family: the user confirmed Render-managed PostgreSQL; no production database was accessed.
- [ ] Confirm the supported PostgreSQL major version from authorized service configuration and align the CI target; do not connect to production data.
- [ ] Run additive migration, constraint, locking, concurrency, and guarded rollback validation against the disposable PostgreSQL 16 service defined in CI; its job has not yet run for these changes.
- [ ] Restore Docker's writable storage. Docker version/info responded and the existing app/CHR stack remains running, but Docker returned `read-only file system` when creating a disposable PostgreSQL test container/network. Do not restart or prune the existing stack without approval.
- [ ] Confirm isolated CHR 7.24.6 lab licensing and host capabilities. The image/container are present and loopback ports respond; licensing and RADIUS/WireGuard behavior remain unverified. The current CHR network is `172.18.0.0/16`, with no overlap among currently observed host routes.
- [x] Replace the obsolete Airtel status-contract entry with provider verification for ObPay, PawaPay, and Xente in the Phase 5 payment-flow gate; keep verification fail-closed and do not make live payments.
- [x] Document the credential-loss stop/escalation and no-secret-retrieval boundary in the [rotation runbook](../../../docs/ROTATION-RUNBOOK.md). There is no automated repeatable rotation endpoint; deployment-specific verification remains outstanding.
- [ ] Verify the private Laravel policy-channel deployment design: trusted peer CIDRs, TLS/proxy topology, shared nonce cache, and actual service reachability.
- [x] Rerun local regressions: `php artisan test` exits 0 (2,805 assertions; 515 warnings from test-environment file reads), `vendor/bin/pint --test` passes, `git diff --check` passes, and `npm run e2e` passes (1 browser test; Vite build succeeded).
- [ ] Rerun the configured PostgreSQL suite after Docker storage is writable; current attempted run did not reach a valid target-engine result.
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
| 2026-11-15 | Airtel verification path | Reclassified | Repository already polls Airtel transaction status; remaining evidence is authoritative status-endpoint response schema, not a collection-callback payload. |
| 2026-11-15 | Phase 1 blocker code remediation | Implemented; SQLite validation passed, PostgreSQL validation pending | `access_mode`, session-scoped revocation routing, durable device reservations, and locking/current capacity reads added. The full isolated SQLite suite passed (576 tests, 3,127 assertions); Pint and PHP syntax checks passed. A two-process test is included only in `phpunit.ci-db.xml`; it has not yet run against PostgreSQL 16. The user confirmed Render-managed PostgreSQL; no production database was accessed. Airtel portal file supplied is for v2 collection initiation, not the v1 status response consumed by the application. |
| 2026-11-15 | Docker restore and Phase 1 revalidation | Still blocked | Docker 29.9.3 responds; existing CHR 7.24.6 container is running and its API/SSH ports answer on loopback. `172.18.0.0/16` does not overlap the host routes observed in this session. A disposable PostgreSQL 16 image was pulled, but the first run exposed PHPUnit's XML `true`→`1` normalization; both database test bootstraps now use `FILTER_VALIDATE_BOOLEAN`. A host diagnostic confirms the PostgreSQL configuration is now selected, but host PHP lacks `pdo_pgsql`. Docker then returned `read-only file system` creating a disposable container/network; existing services were not changed. SQLite: `php artisan test` exit 0, 2,805 assertions, 515 warnings; `npm run e2e`: 1 passed; Pint and `git diff --check`: passed. PostgreSQL concurrency, exact Render PostgreSQL major version, CHR licensing/behavior, and private policy-channel reachability remain unverified. |
