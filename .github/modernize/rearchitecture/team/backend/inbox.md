
## 2026-11-13T18:20:30Z — t1 [architect] (broadcast)
Architecture direction: Keep existing routers on the site-agent path by default; make RADIUS an explicit per-router opt-in. Preserve SITE_AGENT_REQUIRED, current APIs and access terms, and distinguish payment completion from access activation.


## 2026-11-13T18:22:12Z — coordinator
Remediation is required before advancing Phase 1: the security audit reported a HIGH Pesapal callback order-binding finding. Read .github/modernize/rearchitecture/artifacts/t4-security.md and correct only the reported HIGH issue with focused regression coverage; preserve API/payment contracts and do not run migrations against the default DB, deploy, or touch infrastructure/routers. The separate tester task will add/execute regression coverage. 	4 must be re-dispatched for a clean security PASS after remediation.

