## [t2] Mapped current domain and payment fulfillment seams
- Codebase/domain discoveries: Laravel uses tenant-scoped models; current online sale fulfillment is centralized in `PaymentService`, then `VoucherActivationService` issues a voucher/session and either queues the paired site agent or starts active access according to a global config. Existing router provisioning disables RADIUS; session usage counters have no discovered runtime writer.
- Wrong assumptions and corrections: the clarification dependency was at the stated rearchitecture-root path, not in `artifacts/`. The root `context.md`, `decisions.md`, and backend log/inbox were absent.
- Debugging dead-ends and what actually worked: no architecture design artifact was included; source-oriented inspection of payment routes/services, domain models, agent control-plane paths, migrations, and existing tests provided a current-state map without changing code.
- Techniques/patterns worth reusing for future tasks: use the common successful-sale transaction as the payment fulfillment seam; keep per-router access selection separate from global site-agent behavior; enforce provider/reference/tenant/payment-amount consistency before granting a new entitlement.
- Learnings consumed: []

## [t4.1] Bound Pesapal callbacks to verified local orders
- Codebase/domain discoveries: Both the browser callback and IPN/webhook can reuse the existing `PaymentService::handleSuccessfulPackagePayment()` only after the exact local `reference` + `provider_reference` pair and provider-returned merchant reference, amount, and currency match; amount must also remain consistent with the package price.
- Wrong assumptions and corrections: Pesapal's transaction-status response does not document an echoed tracking ID; the request is made using the callback tracking ID, and the response confirms merchant reference, amount, and currency.
- Debugging dead-ends and what actually worked: Existing success fixtures omitted required provider fields. Supplying the documented response fields kept the existing 6 Pesapal feature tests meaningful; the full in-memory SQLite suite remained green.
- Techniques/patterns worth reusing for future tasks: Centralize shared provider-order identity and money checks in a service used by every callback surface; leave webhook acknowledgement and idempotent fulfillment semantics intact when verification fails.
- Learnings consumed: [backend/current-voucher-fulfillment-seam]

## [t4.3] Require Airtel status transaction identity
- Codebase/domain discoveries: Airtel's status query is keyed by the stored provider ID; package amount/currency and transaction amount are local integrity checks when the provider status schema does not guarantee money echoes.
- Wrong assumptions and corrections: The test fixture that included amount and currency was not evidence that Airtel always returns them. The official developer portal required a login; an available public typed client modeled ID/status without money fields.
- Debugging dead-ends and what actually worked: Static web fetching returned the portal shell, while selecting Uganda in the browser redirected to account login; treating optional money echoes as mandatory would rely on an unconfirmed response shape.
- Techniques/patterns worth reusing for future tasks: Make provider identity mandatory, compare echoed values if available, keep local transaction/package invariants, and record provider-contract uncertainty for sandbox confirmation.
- Learnings consumed: [backend/current-voucher-fulfillment-seam]
