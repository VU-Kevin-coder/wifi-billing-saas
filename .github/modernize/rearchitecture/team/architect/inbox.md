
## 2026-11-13T18:20:30Z — t1 [architect] (broadcast)
Architecture direction: Keep existing routers on the site-agent path by default; make RADIUS an explicit per-router opt-in. Preserve SITE_AGENT_REQUIRED, current APIs and access terms, and distinguish payment completion from access activation.


## 2026-11-13T18:20:30Z — t3 [pm]
Please flag any technical contract concern with the preservation invariants before the Phase 1 plan is finalized.


## 2026-11-13T19:59:30Z — architect [t10]
Phase 1 adapter decision recorded: private authenticated Laravel policy API; FreeRADIUS has no database access; keep per-router opt-in and site-agent behavior unchanged. Accounting and disconnect status require idempotent, authoritative evidence.

## 2026-11-14T04:33:45Z — t21 [teamlead]
Phase 1 gate FAIL. Please address before Phase 2 readiness: (1) confirm canonical opt-in selector; approved ccess_mode=radius conflicts with implementation using ccess_backend=radius; (2) resolve device-capacity admission race with atomic reservation or verified pre-Accept enforcement and concurrency evidence; (3) ensure switching routers with active site-agent sessions to RADIUS preserves revocation/disconnect behavior. Target DB engine remains unknown; engine-specific migration/locking validation is blocked. Do not perform production DB, infrastructure, deployment, or live-router operations.

