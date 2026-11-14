
## 2026-11-13T18:20:30Z — t1 [architect] (broadcast)
Architecture direction: Keep existing routers on the site-agent path by default; make RADIUS an explicit per-router opt-in. Preserve SITE_AGENT_REQUIRED, current APIs and access terms, and distinguish payment completion from access activation.


## 2026-11-13T18:22:12Z — coordinator
Remediation is required before advancing Phase 1: the security audit reported a HIGH Pesapal callback order-binding finding. Read .github/modernize/rearchitecture/artifacts/t4-security.md and correct only the reported HIGH issue with focused regression coverage; preserve API/payment contracts and do not run migrations against the default DB, deploy, or touch infrastructure/routers. The separate tester task will add/execute regression coverage. 	4 must be re-dispatched for a clean security PASS after remediation.


## 2026-11-13T19:59:30Z — architect [t10]
Phase 1 adapter decision recorded: private authenticated Laravel policy API; FreeRADIUS has no database access; keep per-router opt-in and site-agent behavior unchanged. Accounting and disconnect status require idempotent, authoritative evidence.

## 2026-11-13T20:13:17Z — dba [t12]
t12 added `routers.access_backend` (default `site_agent`), `radius_nas_identifier`, and `radius_shared_secret`. Add the Laravel encrypted cast and serialization protection before any write path; preserve one-time secret delivery and existing agent behavior.

## 2026-11-13T22:50:09Z — coordinator routing
[t17 architect] MEDIUM: Demonstrate package device-limit enforcement before Access-Accept, including concurrent authorization of a provisioning session.


## 2026-11-14T04:33:45Z — t21 [teamlead]
Phase 1 gate FAIL. Please address before Phase 2 readiness: (1) confirm canonical opt-in selector; approved ccess_mode=radius conflicts with implementation using ccess_backend=radius; (2) resolve device-capacity admission race with atomic reservation or verified pre-Accept enforcement and concurrency evidence; (3) ensure switching routers with active site-agent sessions to RADIUS preserves revocation/disconnect behavior. Target DB engine remains unknown; engine-specific migration/locking validation is blocked. Do not perform production DB, infrastructure, deployment, or live-router operations.

