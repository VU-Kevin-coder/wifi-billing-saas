# Pesapal launch-hold audit

## Decision

**Do not configure Pesapal credentials in production or enable customer checkout yet.** The implementation has hosted checkout, status lookup, callback and IPN endpoints, but does not meet the existing payment-safety standard.

This document changes no payment route, credential, IPN registration, transaction, payout, or production setting.

## Verified findings

- `PaymentService::initiatePackagePayment` selects Pesapal whenever its key and secret are saved. There is no explicit provider flag, tenant pilot, or controlled cutover.
- Callback and IPN handlers re-query Pesapal status. That is good, but both complete a local pending transaction from remote `COMPLETED` status without comparing remote merchant reference, amount or currency to the local order.
- `pesapal_ipn_id` can be blank and is presented as optional, so an order can be submitted without a notification channel.
- `payments:reconcile` only selects `MTN` and `AIRTEL`; paid Pesapal orders can remain pending when both IPN delivery and browser return are missed.
- `PesapalPaymentTest` covers redirect, one callback success and one IPN success. It does not cover mismatches, missing IPN ID, duplicate or concurrent delivery, failed or reversed state, recovery, pilot routing, or rollback.
- Flutterwave operator withdrawals remain live through `FlutterwaveWithdrawalService`, `RevenueController`, Flutterwave webhook handling and withdrawal reconciliation. They must not be changed by this collection migration.

## Launch blocker

Fulfilment must require a verified payment belonging to the exact pending local transaction. The current flow does not prove remote reference, amount and currency match the local transaction. It also lacks a recovery path for a paid order whose IPN and customer return are both missed.

## Safe remediation order

1. Add an explicit customer-collections provider setting, defaulting to the existing route. Pilot Pesapal only for a test tenant or allow-list. Never cut over because credentials happen to be present.
2. Centralize Pesapal status verification and require exact local reference, tracking ID, amount, currency, tenant and package matches before fulfilment. Reuse the existing locked idempotent fulfilment path.
3. Require a configured IPN ID before submitting an order. Register separate public HTTPS sandbox and production IPN endpoints.
4. Extend pending-payment reconciliation to verify eligible Pesapal transactions and leave unavailable checks pending for retry.
5. Add tests for mismatch rejection, missing IPN ID, duplicate and concurrent callback or IPN, failed invalid and reversed status, missed-notification recovery, pilot routing and rollback. Keep all Flutterwave withdrawal tests green.
6. Complete a real deployed sandbox checkout, IPN, callback and reconciliation. Then use a very small live pilot before changing the default.

## Rollback

Rollback is one configuration change back to the existing collection provider. Keep Pesapal records for reconciliation. Keep Flutterwave withdrawals and their reconciliation schedule untouched.

## Approval gate

Do not approve production credentials or live IPN registration until these changes are merged, tested and demonstrated in a sandbox deployment.
