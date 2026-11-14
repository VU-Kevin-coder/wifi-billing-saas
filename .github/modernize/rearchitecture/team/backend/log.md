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

## [t13] Added guarded RADIUS policy/accounting domain
- Codebase/domain discoveries: `PaymentService` remains the locked fulfillment seam; RADIUS accounting can reconcile only an existing Laravel `Session` bound to an explicitly configured router and redeemed voucher. Legacy active sessions without RADIUS evidence must be rejected.
- Wrong assumptions and corrections: A successful disconnect delivery acknowledgement is not proof of a disconnected client and cannot terminate retries. Keep the same outbox command eligible for retry until a valid Stop closes its accounting generation.
- Debugging dead-ends and what actually worked: Laravel feature tests that call internal routes through the HTTP kernel validate route/middleware/controller wiring without exposing a public API contract or contacting external infrastructure.
- Techniques/patterns worth reusing for future tasks: Bind adapter identity to the router record; scope normalized event fingerprints to a NAS-session generation; schedule a retry after delivery acknowledgement until Stop; do not reconnect a blocked activation edit that the user explicitly undid.
- Learnings consumed: [backend/current-voucher-fulfillment-seam]

## [t13] Added opt-in activation and explicit per-router rollback
- Codebase/domain discoveries: RADIUS activation can reuse the existing voucher/session transaction, persist `provisioning` without an agent command, and wait for accepted accounting to mark active. A router explicitly switched back to `site_agent` can resume a same-voucher pending session by queuing a command only when no provision command already exists.
- Wrong assumptions and corrections: The database default `access_backend=site_agent` is not present on a newly created Eloquent instance until it is reloaded; assert the persisted default via `fresh()`. Rollback must target the same pending session to prove no payment or entitlement duplication.
- Debugging dead-ends and what actually worked: Existing-session activation returns early, so explicit rollback recovery belongs immediately before that return and must be narrowly gated by current router backend, session state, and absence of an existing command.
- Techniques/patterns worth reusing for future tasks: Treat rollback as an explicit router-mode change, never automatic fallback; preserve voucher/session identity and command idempotency. Validate the public portal route as well as the service-level agent path.
- Learnings consumed: [backend/current-voucher-fulfillment-seam]

## [t16] Patched the Laravel framework advisory in the lockfile
- Codebase/domain discoveries: The dependency set is Composer plus npm. The shared advisory tool accepts npm, while Composer's native locked audit surfaced one LOW Laravel framework advisory. Final audits reported no advisories.
- Wrong assumptions and corrections: Composer 2.10's `--with-dependencies` preview proposed 20 updates, including Symfony 8 components. A targeted update without that flag changed only `laravel/framework`; lock-format-only changes were reverted.
- Debugging dead-ends and what actually worked: Composer resolution on PHP 8.2 failed because pre-existing locked `coding-libs/laravel-mfa` and `spatie/laravel-permission` require PHP ^8.3. Updated only Laravel while ignoring that platform check for resolution, then independently verified Laravel 12.72.0 itself requires PHP ^8.2. No application tests were run to avoid processing `.env`.
- Techniques/patterns worth reusing for future tasks: Use the package manager's full lockfile advisory scan for complete transitive coverage; keep shared-tool ecosystem coverage explicit. For narrow Composer fixes, avoid dependency expansion and inspect the lock diff for unrelated metadata churn.
- Learnings consumed: []

## [t22.1] Aligned Composer packages to PHP 8.2
- Codebase/domain discoveries: MFA v1.1.6 supports PHP >=8.2 and Illuminate 12; the v1.1.6-to-v2.0.0 upstream diff contains no package source changes. Spatie permission v6.25.0 supports PHP ^8.0 and Illuminate 12; app usages are core APIs, while no v7-renamed events/commands or removed clearClassPermissions API are referenced.
- Wrong assumptions and corrections: Spatie 7.4.2 is not a PHP 8.2 fallback; it also requires PHP ^8.3. Its compatible line must move to v6.25.0, whose APIs/config were reviewed against actual app use.
- Debugging dead-ends and what actually worked: Composer 2.10.2 rewrote empty lock metadata and bumped plugin API metadata during resolution. Reverting only those unrelated format changes preserved the focused package diff and the existing t16 Laravel update.
- Techniques/patterns worth reusing for future tasks: Use targeted Composer resolution with `--no-install --no-scripts --no-plugins`; verify via `composer check-platform-reqs --lock` and `composer install --dry-run` with scripts/plugins disabled. Do not run Laravel tests if that would process a prohibited `.env`.
- Learnings consumed: [backend/composer-cve-lockfile-remediation]

## [t22.2] Added protected one-time router credential delivery
- Codebase/domain discoveries: The Router encrypted cast and hidden field already protect storage and ordinary serialization; the missing piece was an operator action. The existing tenant-domain middleware verifies that an authenticated tenant operator belongs to the domain tenant.
- Wrong assumptions and corrections: The credential service must work both in tenant-scoped HTTP requests and existing trusted internal/test calls with no active TenantContext; its lock query therefore binds explicitly to the persisted router's tenant and rejects an active mismatched context.
- Debugging dead-ends and what actually worked: `saveOrFail()` can return false when an Eloquent event cancels persistence, so the service must explicitly check the save result before returning secret material. PHPUnit's SQLite `:memory:` configuration alone does not guarantee its Laravel environment bootstrap avoids `.env`/`.env.testing`.
- Techniques/patterns worth reusing for future tasks: Use a direct POST response with no-store headers instead of session flash; perform issuance, non-secret audit, and view rendering within the transaction; reject repeat issuance without replacing the stored key.
- Learnings consumed: [backend/radius-policy-boundary, backend/current-voucher-fulfillment-seam, security/secret-isolation-radius-boundary]

## [t22.4] Re-scanned locked dependencies after PHP 8.2 compatibility updates
- Codebase/domain discoveries: The tracked dependency scope is root Composer and npm manifests/lockfiles; Composer locked 123 records and npm's lock graph covers 198 packages including optional dependencies.
- Wrong assumptions and corrections: The shared advisory tool accepts npm but not Composer; use the native lockfile-wide Composer and npm audits for full resolved-set coverage, with shared assessment as supplemental direct npm coverage.
- Debugging dead-ends and what actually worked: `composer audit`, platform check, and install dry-run all passed with plugins disabled and scripts disabled where applicable; no application bootstrapping was necessary.
- Techniques/patterns worth reusing for future tasks: Compare scoped `git status` before and after to distinguish pre-existing manifest/lock changes from scan outputs; keep unchanged dirty dependency files untouched.
- Learnings consumed: [backend/composer-cve-lockfile-remediation, backend/php82-composer-lock-compatibility]

## [t22.2] Re-ran one-time router credential regressions in an isolated Laravel base path
- Codebase/domain discoveries: PHPUnit can run the Laravel feature tests with `APP_BASE_PATH` pointed at a temporary application root, an alternate PHPUnit XML, and SQLite `:memory:`; use framework-default cache paths on Windows rather than `C:/...` override values.
- Wrong assumptions and corrections: The documented t15 Playwright harness protects its own setup/server bootstrap, but a direct PHPUnit run needs a separate temporary Laravel base path because `Tests\TestCase` creates a fresh application during each test.
- Debugging dead-ends and what actually worked: Initial harness attempts failed on Windows cache-path normalization and then on a missing isolated `composer.json`; after correcting only the temporary harness, all four tests executed without repository environment-file access.
- Techniques/patterns worth reusing for future tasks: With tenant-domain routes, verify runtime controller argument order: Laravel supplied the domain slug before the router path value, causing an `int $router` type error in `RadiusRouterCredentialController::store()`. Do not mask an implementation defect by rewriting the regression test.
- Learnings consumed: [backend/one-time-router-secret-delivery, backend/current-voucher-fulfillment-seam]

## [t22.2] Corrected router credential route binding and completed regressions
- Codebase/domain discoveries: Laravel supplies the `{tenant}` domain value before `{router}`; `store()` now accepts the slug first, verifies it against `TenantContext`, and still queries the router by both ID and tenant ID.
- Wrong assumptions and corrections: Symfony canonicalizes Cache-Control directive order, so tests must assert the emitted canonical form while retaining private/no-store/no-cache/must-revalidate expectations.
- Debugging dead-ends and what actually worked: A temporary base path with selected directory junctions, copied bootstrap files, an alternate PHPUnit XML, and forced SQLite `:memory:` prevented Laravel from looking in the project for `.env` or `.env.testing`; after correcting the header assertion, 4 tests and 35 assertions passed.
- Techniques/patterns worth reusing for future tasks: Route feature tests on tenant subdomains should cover both successful dispatch and tenant mismatch; keep test DB configuration explicit and isolated at the Laravel base path, not only in the repository PHPUnit config.
- Learnings consumed: [backend/one-time-router-secret-delivery, backend/current-voucher-fulfillment-seam, backend/radius-policy-boundary]

## [t22.3] Added fail-closed RADIUS source allowlisting
- Codebase/domain discoveries: The existing authenticated policy middleware is shared by the internal routes, so adding the direct-peer check before HMAC leaves the API surface and authentication/replay contract unchanged.
- Wrong assumptions and corrections: Forwarded client IPs must not be used for this guard; compare `REMOTE_ADDR` even though Laravel can honor trusted-proxy forwarding elsewhere. A production-mode HTTP feature test must use an HTTPS URI, not only set `HTTPS=on` in test server variables.
- Debugging dead-ends and what actually worked: The isolated harness needs a copied `composer.json` and a test-only `APP_KEY`; initial harness attempts failed before test logic. Using an HTTPS request URI allowed the production HTTPS/HMAC/nonce path to run against SQLite `:memory:`.
- Techniques/patterns worth reusing for future tasks: Validate all configured CIDRs before matching any of them, reject `/0`, and test both a forged forwarded header and production missing/invalid configuration. Treat production reachability as an external prerequisite, not a code-level claim.
- Learnings consumed: [backend/radius-policy-boundary, backend/one-time-router-secret-delivery, backend/current-voucher-fulfillment-seam]
