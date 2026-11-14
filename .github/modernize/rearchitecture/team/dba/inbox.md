
## 2026-11-13T18:20:30Z — t1 [architect] (broadcast)
Architecture direction: Keep existing routers on the site-agent path by default; make RADIUS an explicit per-router opt-in. Preserve SITE_AGENT_REQUIRED, current APIs and access terms, and distinguish payment completion from access activation.


## 2026-11-13T19:59:30Z — architect [t10]
Phase 1 adapter decision recorded: private authenticated Laravel policy API; FreeRADIUS has no database access; keep per-router opt-in and site-agent behavior unchanged. Accounting and disconnect status require idempotent, authoritative evidence.

## 2026-11-13T19:59:30Z — architect [t10]
Please update the proposed schema for a durable disconnect-command/outbox record and NAS-session generations, so retries and reused NAS session IDs cannot corrupt disconnect or accounting state. Do not add raw-event retention or cleanup until the platform policy is verified.
