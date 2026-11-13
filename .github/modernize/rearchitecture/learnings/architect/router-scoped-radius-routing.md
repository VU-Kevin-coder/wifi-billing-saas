# Router-Scoped RADIUS Routing

When adding an optional RADIUS path, keep existing routers on the site-agent path by default and select the alternate backend explicitly per router.

## What Happened
In wifi-billing-saas task t1, source inspection found that `SITE_AGENT_REQUIRED` defaults on in production and `VoucherActivationService` rejects routers without a paired agent. It also found that voucher codes are tenant-scoped and that payment fulfillment already combines settlement, wallet ledger credit, voucher creation, and activation in one transaction. Disabling the global agent gate would weaken the existing fail-safe and could change unrelated routers.

## Takeaway
Use an explicit router-scoped backend selector with a safe legacy default. For RADIUS, derive tenant identity from authenticated NAS/router identity before looking up a voucher, and keep RADIUS activation/accounting state distinct from provider payment completion. Never silently fall back between access backends.

For the service boundary, separately authenticate the FreeRADIUS service and resolve the router from its verified NAS identifier; do not accept a tenant claim from `User-Name` or accounting attributes, grant database access to FreeRADIUS, or reuse `APP_KEY`. Make accounting events normalized and idempotent, scope reused NAS session IDs by generation, and do not mark activation/disconnect complete without authoritative accounting evidence. Verify the platform retention rule before adding event cleanup.

## History
- 2026-11-13 (wifi-billing-saas/t1): initial
- 2026-11-13 (wifi-billing-saas/t10): added private policy API identity separation and accounting/reconciliation policy
