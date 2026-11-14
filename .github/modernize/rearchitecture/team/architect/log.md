## [t1] Defined additive access/payment architecture
- Codebase/domain discoveries: `VoucherActivationService` enforces the global production site-agent requirement; successful `voucher_sale` fulfillment is already transaction-locked and combines wallet ledger credit, voucher creation, and activation. RADIUS is documented as deferred, while WireGuard is management-only.
- Wrong assumptions and corrections: the repository does not contain `context.md`, `decisions.md`, or architect `inbox.md`/prior log; no fanout artifact or separate `plan.md` was present in the workspace. Used the approved clarification answers and project profile as the available source of scope.
- Debugging dead-ends and what actually worked: full `PaymentService.php` exceeded the file reader limit; targeted `rg` plus bounded `view_range` reads recovered the verification and fulfillment contracts.
- Techniques/patterns worth reusing: keep a new access path explicitly router-selected and default legacy routers to site-agent; derive RADIUS tenant identity from the authenticated router, not voucher username; distinguish payment completion from access activation.
- Learnings consumed: [none]

## [t10] Recorded adapter and accounting policy decisions
- Codebase/domain discoveries: the t6 proposal did not include durable disconnect-command state or permit NAS session-ID reuse after Stop; both are required to make retries and disconnect truthfulness implementable. No platform accounting-retention rule was found in the repository/docs search.
- Wrong assumptions and corrections: a protected private policy API needs two distinct identity checks—FreeRADIUS service authentication and the router identity authenticated at the NAS boundary; neither is a tenant claim supplied by a voucher/user packet.
- Debugging dead-ends and what actually worked: inspected the clarification, t1/t2/t6/t9 artifacts and searched docs/rearchitecture for retention; repository evidence supports no invented retention duration.
- Techniques/patterns worth reusing: keep adapter calls outside DB transactions; use idempotent normalized accounting events and generation-scoped NAS session identity; retain pending/disconnecting status until authoritative accounting confirmation.
- Learnings consumed: [architect/router-scoped-radius-routing]

## [t17] Reviewed Phase 1 architecture conformance
- Codebase/domain discoveries: the router-scoped Laravel adapter, additive accounting schema, payment linkage, and site-agent fallback are broadly aligned; the Composer lock conflicts with PHP 8.2, operator one-time secret delivery is absent, and private-channel enforcement is not evidenced.
- Wrong assumptions and corrections: passing tests against an existing `vendor` directory does not establish that the locked dependency set installs on the required PHP runtime.
- Debugging dead-ends and what actually worked: avoided running Artisan/Composer application scripts because they could process `.env`; reviewed only explicit source/manifests and existing SQLite/browser test reports.
- Techniques/patterns worth reusing: inspect locked package-level PHP constraints independently of the root PHP constraint; distinguish code-level HMAC/HTTPS checks from proof of private-network reachability.
- Learnings consumed: [architect/router-scoped-radius-routing]

## [t19] Ran isolated build and startup smoke checks
- Codebase/domain discoveries: the installed app can answer Laravel `/up` on PHP 8.2.12 through the isolated test router, but locked platform checks fail for PHP `^8.3` requirements in `coding-libs/laravel-mfa v2.0.0` and `spatie/laravel-permission 8.3.2`.
- Wrong assumptions and corrections: successful startup with the pre-existing vendor tree is not proof of clean Composer install/full-build compatibility.
- Debugging dead-ends and what actually worked: used the existing Vite build helper with an isolated `envDir`, and the tested PHP router with an isolated `APP_CONFIG_CACHE`, SQLite `:memory:`, and no repository environment-file loading; avoided Artisan and the default `npm run build`.
- Techniques/patterns worth reusing: report frontend build, PHP lock platform, and application startup as separate gates; a lock platform check can expose runtime mismatch without installing dependencies or invoking Composer scripts.
- Learnings consumed: [architect/router-scoped-radius-routing]

## [t17] Re-reviewed Phase 1 architecture after remediation
- Codebase/domain discoveries: t22.1/t22.4 resolve the PHP 8.2 lock mismatch; t22.2 adds tenant-scoped, recent-MFA, one-time secret issuance; t22.3 adds a fail-closed direct-peer CIDR policy guard. Production reachability remains unproved.
- Wrong assumptions and corrections: a `device_limit` field in an authorization response is not evidence that concurrent requests are capped before accounting Start; provisioning sessions currently bypass open-session capacity checks.
- Debugging dead-ends and what actually worked: read only named source/config/test files and upstream artifacts; avoided all application startup/test commands and environment-file access.
- Techniques/patterns worth reusing: distinguish resolved code findings from operational prerequisites; retain atomic pre-Accept capacity enforcement as a lab gate rather than assuming adapter behavior.
- Learnings consumed: [architect/router-scoped-radius-routing, architect/php82-locked-dependency-platform-mismatch, architect/php82-locked-dependency-smoke-gates]

## [t19] Passed isolated full build and startup smoke
- Codebase/domain discoveries: t22.1's PHP 8.2-compatible dependency lock now passes both Composer platform checks and a real scripts/plugins-disabled lock install; the repository uses separate Composer and Vite build surfaces, and the existing Vite helper accepts an isolated envDir. The Laravel router successfully served `/up` using explicit testing settings and SQLite `:memory:`.
- Wrong assumptions and corrections: the prior t19 smoke artifact predated the t22.1 lock fix, so its PHP 8.3 platform failure was no longer current; rechecking the current lock changed the smoke verdict to pass. A root-level combined build command is not declared.
- Debugging dead-ends and what actually worked: install with an isolated `COMPOSER_HOME`, no scripts/plugins, then Vite via `tests/e2e/build-assets.mjs` and a temporary env directory; startup used the test router and disabled environment-file loading.
- Techniques/patterns worth reusing: report Composer lock install, frontend production asset build, PHP syntax validation, and local HTTP startup separately; keep startup and DB/migration safety evidence explicit.
- Learnings consumed: [architect/php82-locked-dependency-platform-mismatch, architect/php82-locked-dependency-smoke-gates, architect/router-scoped-radius-routing]
