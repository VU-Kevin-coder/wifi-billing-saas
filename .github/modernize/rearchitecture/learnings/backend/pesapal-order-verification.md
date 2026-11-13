# Pesapal Order Verification

For Pesapal callbacks, resolve a voucher sale by both local merchant reference and provider tracking ID, then compare the verified status response's merchant reference, amount, and currency with the local transaction and package before fulfillment.

## What Happened
For wifi-billing-saas task t4.1, the callback and IPN paths independently selected a transaction by either callback identifier and trusted the provider's completed status without matching the local sale value. A shared `PesapalOrderVerifier` now owns exact transaction matching and completion checks so both paths use the same fail-closed rules, including a consistency check that the transaction amount still equals the package price. Pesapal API 3.0 documents the status lookup's tracking-ID query and the response's merchant reference, amount, and currency.

## Takeaway
Do not treat callback fields as proof of the provider order. Require both callback identifiers to select one local sale and bind the provider status response's merchant reference plus exact amount and currency to the transaction/package before invoking the existing idempotent fulfillment service. Keep the provider call keyed by tracking ID; the documented response need not echo the tracking ID.

## History
- 2026-11-13 (wifi-billing-saas/t4.1): initial
