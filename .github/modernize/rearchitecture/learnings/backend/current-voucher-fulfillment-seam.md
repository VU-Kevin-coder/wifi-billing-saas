# Current Voucher Fulfillment Seam

The existing online sale path centrally verifies payment, credits the wallet, issues a voucher, and starts access through `PaymentService` and `VoucherActivationService`.

## What Happened
For wifi-billing-saas task t2, the backend map found that the current entitlement path is voucher/session based. `PaymentService::handleSuccessfulPackagePayment()` owns locked successful-sale fulfillment, while `VoucherActivationService` resolves router/site and selects immediate active access versus site-agent provisioning using a global setting. Existing RouterOS provisioning sets `use-radius=no`; the package's `radius_profile_name` field is not the active RouterOS profile selector.

## Takeaway
When adding an optional per-router access backend, extend the shared verified-sale boundary and make the new selection explicit per router. Do not change the global site-agent requirement, remove voucher semantics, or claim RADIUS accounting exists because usage counters are only modeled/read today.

## History
- 2026-11-13 (wifi-billing-saas/t2): initial
