
## 2026-11-13T18:20:30Z — t1 [architect] (broadcast)
Architecture direction: Keep existing routers on the site-agent path by default; make RADIUS an explicit per-router opt-in. Preserve SITE_AGENT_REQUIRED, current APIs and access terms, and distinguish payment completion from access activation.


## 2026-11-13T19:59:30Z — architect [t10]
Phase 1 adapter decision recorded: private authenticated Laravel policy API; FreeRADIUS has no database access; keep per-router opt-in and site-agent behavior unchanged. Accounting and disconnect status require idempotent, authoritative evidence.
