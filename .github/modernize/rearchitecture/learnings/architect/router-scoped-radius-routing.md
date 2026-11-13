# Router-Scoped RADIUS Routing

When adding an optional RADIUS path, keep existing routers on the site-agent path by default and select the alternate backend explicitly per router.

## What Happened
In wifi-billing-saas task t1, source inspection found that `SITE_AGENT_REQUIRED` defaults on in production and `VoucherActivationService` rejects routers without a paired agent. It also found that voucher codes are tenant-scoped and that payment fulfillment already combines settlement, wallet ledger credit, voucher creation, and activation in one transaction. Disabling the global agent gate would weaken the existing fail-safe and could change unrelated routers.

## Takeaway
Use an explicit router-scoped backend selector with a safe legacy default. For RADIUS, derive tenant identity from authenticated NAS/router identity before looking up a voucher, and keep RADIUS activation/accounting state distinct from provider payment completion. Never silently fall back between access backends.

## History
- 2026-11-13 (wifi-billing-saas/t1): initial
