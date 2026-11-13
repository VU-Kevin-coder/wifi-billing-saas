# Airtel Optional Status Fields

Test Airtel success responses with the transaction ID required and amount/currency optional, validating those fields only when the provider echoes them.

## What Happened
For wifi-billing-saas task t4.4, the regression matrix confirmed that missing or mismatched IDs, mismatched/non-numeric amounts, and mismatched currency must not fulfill a payment. Missing amount/currency remain acceptable under the t4.3 contract interpretation; correct echoed values are accepted. Failure assertions check pending transaction state, wallet balance, and voucher count.

## Takeaway
When a provider response has version-dependent optional fields, keep both positive and negative boundary tests: omitted fields, valid echoes, and invalid echoes. Assert fulfillment side effects, not only the webhook status. Use PHPUnit `#[DataProvider]` for related refusal cases when the repository already follows that pattern.

## History
- 2026-11-13 (wifi-billing-saas/t4.4): initial
