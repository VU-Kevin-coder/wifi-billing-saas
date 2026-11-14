# One-Time Router Secret Delivery

Use an authorized, tenant-bound POST to persist and directly disclose a router credential once; never stage it in session flash or logs.

## What Happened
For wifi-billing-saas task t22.2, the missing operator workflow was added as a tenant-scoped Laravel route for `operator_admin`, protected by recent-MFA policy and throttling. The credential service locks the router, rejects repeat issuance, and verifies persistence before returning generated material. The controller renders the one-time view within the transaction and returns no-store/no-cache headers; audit metadata contains no secret. Runtime tests were blocked because PHPUnit may process prohibited project environment files.

The t22.2 isolated PHPUnit rerun used a temporary Laravel base path and SQLite `:memory:` without repository environment-file access. It exposed a route-parameter ordering defect: the tenant-domain slug reached `store()` before the router path parameter, but the controller expected an integer router ID in that position. Keep the tests pointed at the real route; correct the controller/route binding rather than weakening the assertion.

For the t22.2 fix, the action now accepts the domain slug before the router ID, verifies the slug against the tenant resolved by `TenantContext`, and retains the router query's `tenant_id` constraint. Symfony normalized the emitted Cache-Control directive order, so the test was updated to match the serialized header while preserving all required no-store directives.

## Takeaway
- Keep the generated credential in a direct protected response, not redirect/session flash.
- Lock the tenant-bound row and refuse any second generation once secret material exists.
- Ensure failed/cancelled saves throw before the secret is returned; include audit and view rendering in the same transaction.
- Keep audit data non-secret, ordinary model serialization hidden, and response cache/referrer protections explicit.
- For tenant-domain routes, declare the domain slug before path parameters, compare it with the resolved tenant context, and retain tenant-constrained model lookup; verify through the real feature route.
- When asserting security headers, account for framework normalization of directive order without dropping required directives.
- If tests may load a project environment file and that cannot be prevented, do not run them; document the block rather than assume the test DB alone makes execution safe.

## History
- 2026-11-13 (wifi-billing-saas/t22.2): initial
- 2026-11-13 (wifi-billing-saas/t22.2): recorded isolated regression evidence and tenant-domain route argument ordering defect
- 2026-11-14 (wifi-billing-saas/t22.2): corrected domain/path binding, preserved tenant scope, and verified all four isolated feature regressions
