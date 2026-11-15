# RADIUS Session Snapshots and Capacity

Reserve entitlement capacity durably before Access-Accept, and route lifecycle operations using the access mode recorded on each session.

## What Happened
For wifi-billing-saas Phase1-blockers, the approved selector was aligned to `routers.access_mode` while a forward migration copied legacy `access_backend` values. Admission now serializes on the shared session row and counts open accounting generations plus pending reservations. Repeated NAS-session authorization reuses its reservation; Start binds it; only a validated Stop releases it. Session snapshots preserve site-agent revocation after router opt-in and RADIUS revocation after a later router-mode change. An explicitly rolled-back, still-provisioning RADIUS session changes its snapshot only when the agent command is queued.

## Takeaway
- Keep the router selector for new authorization, but use `sessions_log.access_mode` for revocation, retries, expiry, accounting, and disconnect delivery.
- Never infer access completion from Access-Accept or command acknowledgement. Keep unconfirmed reservations occupied until validated accounting Stop.
- Lock the same entitlement row while counting active generations and pending reservations, and use locking/current reads for the matching rows to avoid stale REPEATABLE READ snapshots after lock waits. SQLite `:memory:` tests do not prove row-lock concurrency; verify concurrency on the confirmed supported database engine before release.
- Keep true multiprocess concurrency tests only in the dedicated CI DB suite. Require committed fixtures, a synchronization barrier, a strictly local `testing` database target, and assertions for one accept, one reject, and one reservation.
- Keep the legacy selector column only as migration compatibility data; application logic and current tests should use `access_mode`.

## History
- 2026-11-15 (wifi-billing-saas/Phase1-blockers): initial
- 2026-11-15 (wifi-billing-saas/Phase1-blockers-followup): documented locking current reads and CI-only multiprocess verification
