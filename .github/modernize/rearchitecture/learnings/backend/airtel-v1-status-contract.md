# Airtel v1 Status Contract

For Airtel v1 reconciliation, bind the successful status result to the queried provider transaction ID; do not assume amount/currency are echoed unless the account's official schema or a captured sandbox response confirms that contract.

## What Happened
For wifi-billing-saas task t4.3, the Airtel Uganda developer portal required an account to access its documentation. The existing project fixture included `id`, `amount`, `currency`, and `status`, but was not a real provider capture. An available typed client modeled a status transaction with `id` and `status` only. The implementation therefore requires an exact non-empty returned ID, checks local transaction/package/UGX consistency, and validates amount/currency when present without requiring undocumented echoes.

## Takeaway
Before changing provider-field requirements, inspect the precise endpoint/version and response captured from the supported provider account. Keep stable provider identity mandatory, preserve local price/currency invariants, reject mismatched echoed money fields, and document uncertainty when the official schema is inaccessible. Confirm the live Uganda sandbox shape before production use.

## History
- 2026-11-13 (wifi-billing-saas/t4.3): initial
