# Pesapal customer collection migration

## Decision

Use Pesapal API 3.0 for customer voucher and package collections only. Keep Flutterwave collections live until Pesapal passes sandbox acceptance and a controlled live rollout. Keep `FlutterwaveWithdrawalService` for operator withdrawals. Payouts are a separate capability and are out of scope for this migration.

This branch changes no credentials, IPN registration, payment routing, or payout behaviour.

## Repository facts

| Area | Current state | Required approach |
|---|---|---|
| Checkout | `PortalController` creates a pending `voucher_sale` then calls Flutterwave specific `PaymentService::initiatePackagePayment` | Add a payment gateway boundary while retaining the local transaction first |
| Fulfilment | `PaymentService::handleSuccessfulPackagePayment` locks the transaction and performs wallet split and voucher issuance in one database transaction | Reuse only after verified Pesapal status |
| Webhook | `/api/webhooks/flutterwave` validates `verif-hash`, verifies with Flutterwave, then fulfils | Add a separate Pesapal endpoint; do not change Flutterwave verification |
| Withdrawals | Flutterwave withdrawal state uses transfer IDs and stops duplicate submissions | Leave it intact |
| Settings | Flutterwave uses `config/services.php`, `PlatformSetting`, `.env.example`, and platform settings UI | Add separately named Pesapal values |
| Safety | `PAYMENT_SIMULATION_ENABLED` defaults to false | Preserve this default and add provider specific fakes |

## Pesapal API 3.0 contract

1. Create a short lived OAuth bearer token from the Pesapal consumer key and consumer secret. Cache only within its five minute lifetime.
2. Register a public HTTPS IPN URL for each environment and store the returned `notification_id` as a protected setting.
3. For each checkout submit the exact local amount and currency, an immutable merchant reference, a short package description, callback URL, IPN ID, and customer billing address. Pesapal requires phone or email. Merchant references allow only alphanumeric characters, dash, underscore, dot, and colon, and are limited to 50 characters.
4. Persist the Pesapal `order_tracking_id` on the same pending local transaction before redirecting the customer.
5. Treat callback and IPN as signals only. Each carries a tracking ID and merchant reference, not an authoritative payment result.
6. Fetch `GetTransactionStatus` on every callback or IPN. Complete only after matching local reference, tracking ID, amount, currency, tenant, package, and remote `COMPLETED` state.
7. Lock the local row, record safe provider metadata, and call existing fulfilment once. Duplicate callback or IPN delivery must not issue another voucher or wallet credit.

Provider documentation: [Authentication](https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/authentication), [Register IPN URL](https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/registeripnurl), [Submit order](https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/submitorderrequest), [Status](https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/gettransactionstatus).

## Proposed shape

```text
PortalController
  -> CustomerPaymentGateway
      -> FlutterwaveCustomerGateway
      -> PesapalCustomerGateway
  -> pending Transaction

PesapalNotificationController
  -> PesapalCustomerGateway verifyOrder
  -> PaymentService handleSuccessfulPackagePayment
```

The gateway returns a redirect URL and provider tracking ID at checkout. Verification returns normalised status, provider IDs, amount, currency, and merchant reference. This keeps provider payloads out of controllers.

Add `GET /payments/pesapal/callback` and `GET|POST /api/webhooks/pesapal` based on the IPN method registered with Pesapal. Persist provider, order tracking ID, notification timestamps, verification result, and fulfilment result without altering old Flutterwave records.

## Sandbox acceptance

- [ ] Token acquisition, TTL cache, and refresh work.
- [ ] Checkout submits exact local amount, currency, reference, callback URL, and IPN ID.
- [ ] Callback parameters alone cannot complete a payment.
- [ ] Unknown or mismatched reference, tracking ID, amount, currency, tenant, or package cannot fulfil.
- [ ] Repeated callback and IPN deliveries fulfil exactly once.
- [ ] Failed, invalid, and reversed results never issue vouchers.
- [ ] A status timeout leaves the payment pending for retry.
- [ ] Existing Flutterwave payment, webhook, and withdrawal tests still pass.
- [ ] A real sandbox payment works through deployed public HTTPS URLs.

## Rollout and rollback

1. Merge the gateway abstraction with Flutterwave still default.
2. Configure sandbox Pesapal values and register sandbox IPN.
3. Run tests and a real end to end sandbox payment.
4. Enable Pesapal for an internal test tenant or feature flag.
5. Reconcile controlled live payments in the Pesapal dashboard before defaulting customer checkout to Pesapal.
6. Rollback is a configuration switch back to Flutterwave. Keep Pesapal transaction records for reconciliation.

## Out of scope

- Replacing Flutterwave operator transfers.
- Moving historical transactions.
- Enabling simulation in production.
- Logging consumer secrets, access tokens, phone numbers, or complete provider payloads.
