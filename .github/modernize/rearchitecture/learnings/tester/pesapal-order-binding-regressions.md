# Pesapal Order-Binding Regression Tests

The Pesapal callback and IPN routes should share negative regression cases for exact local-order identity and verified amount/currency.

## What Happened
For wifi-billing-saas task t4.2, one PHPUnit data provider exercised the same rejection cases through the browser callback and IPN. Tests asserted no sale completion, voucher creation, or wallet credit, while preserving the IPN response contract.

## Takeaway
When testing a payment-verification rule used by multiple entry points, run a common malformed/mismatched provider-response matrix through each route. Also assert durable side effects stay absent, not just the returned HTTP status.

## History
- 2026-11-13 (wifi-billing-saas/t4.2): initial
