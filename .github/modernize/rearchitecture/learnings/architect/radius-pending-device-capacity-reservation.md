# RADIUS Pending Device-Capacity Reservation

A RADIUS authorization check must reserve device capacity atomically while a paid session is still provisioning; returning the package's device-limit value alone does not prevent concurrent pre-Start accepts.

## What Happened
In wifi-billing-saas task t17, `RadiusAccountingService::sessionMayAuthenticate()` returned true for each eligible `provisioning` session before checking open accounting generations. The authorization test confirmed the policy response included `device_limit`, but no pre-Accept pending reservation or concurrency assertion proved the cap under simultaneous logins.

## Takeaway
Treat `device_limit` as a behavioral contract, not just an attribute in a policy response. Before lab acceptance, either atomically reserve pending device slots in Laravel with cleanup/reconciliation semantics or provide verified adapter behavior that enforces capacity before Access-Accept. Add concurrent attempts while the session is provisioning and prove the package limit cannot be exceeded.

## History
- 2026-11-14 (wifi-billing-saas/t17): initial
