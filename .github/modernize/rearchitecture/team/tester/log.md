## [t5] Captured current-checkout test and build baseline
- The current PHPUnit configuration forces SQLite `:memory:`; use it for safe baseline tests and avoid `phpunit.ci-db.xml` unless an explicitly isolated DB is provided.
- `php artisan test --configuration=phpunit.xml` is rejected because Artisan already provides a configuration; `php artisan test --do-not-cache-result` correctly runs the full suite.
- Baseline revision `f9643fc759623e58596720f6609f872fe9bef6d9`: 515 tests and 2,774 assertions passed; Vite build passed. No production files were modified.
- No style or architecture decisions made.
- Learnings consumed: []

## [t4.2] Added Pesapal callback order-binding regression coverage
- Existing checkout already contained t4.1 backend source changes and successful-response fixture updates; kept those upstream edits intact.
- A shared PHPUnit data provider can drive both the browser callback and IPN with identical identity/amount/currency mismatch cases.
- Verify rejected payments through side effects (pending transaction, zero vouchers, unchanged wallet) as well as status/redirect responses.
- Commands and results: focused Pesapal suite 24 passed (134 assertions); full suite 533 passed (2,875 assertions); PHP syntax and `git diff --check` passed.
- No default DB, deployment, router, or infrastructure actions; no production code modified.
- Learnings consumed: []

## [t4.4] Added Airtel refusal and response-boundary regression coverage
- Fresh same-checkout baseline passed at `f9643fc759623e58596720f6609f872fe9bef6d9` (535 tests, 2,885 assertions); PHPUnit uses in-memory SQLite.
- Airtel status-contract test matrix covers mismatched ID, amount, invalid amount, currency mismatch, missing ID, changed package price, omitted optional fields, and matching optional echoes.
- Failure tests assert pending transaction, unchanged wallet, and no voucher; positive test asserts completion, wallet credit, and one voucher.
- Docker CLI was present but daemon did not respond during this task; local Playwright CLI was absent. Neither was required for the scoped Laravel HTTP-kernel tests; no installs or external calls were made.
- No production code, default DB, deployment, router, or Phase 2 changes.
- Learnings consumed: [tester/pesapal-order-binding-regressions]

## [t14] Added isolated migration and domain regressions
- `RefreshDatabase` runs the fresh migrations against the `phpunit.xml`-forced SQLite `:memory:` connection; directly calling the additive migration objects again verifies idempotent re-entry without opening another database or running a standalone migration command.
- Migration regressions now cover the legacy `site_agent` default, nullable typed sale links, tenant-scoped uniqueness, and refusal to roll back configured routers or populated sale/accounting evidence.
- Repeating activation after an explicit per-router rollback confirms the same session and one site-agent command are retained.
- Focused tests passed (23 tests, 186 assertions); full suite passed (555 tests, 3,016 assertions). Syntax, Pint, and diff checks passed.
- No production source, external/default database, deployment, router, infrastructure, or Phase 2 changes.
- Learnings consumed: []

## [t15] Added pinned Playwright portal status journey
- Added exact `@playwright/test` 1.65.1 and Chromium 159; single browser journey confirms a pending sale stays pending and the payment token travels in the header, not URL.
- Laravel browser setup uses an explicitly guarded disposable SQLite file, a generated encrypted test session cookie, and an isolated empty environment path; Vite assets are built via Node API with that isolated `envDir`.
- PHP built-in server and app run without loading repository environment files; cleanup removes the database, fixture, sessions, and temporary environment directory.
- Browser test passed (1/1); PHP syntax and Composer metadata validation passed. Full PHPUnit/integration suite was not run in t15.
- Docker availability was checked (`docker info` succeeded) but no container/infrastructure command was used.
- Learnings consumed: [tester/pesapal-order-binding-regressions, tester/airtel-optional-status-fields, tester/sqlite-migration-safety]


## [t20] Executed Phase 1 runtime and compatibility matrix
- Current Laravel 12.72.0 / PHP 8.2.12 lock compatibility passed; focused regressions (93 tests), full PHPUnit suite (564 tests), and the Playwright pending-payment journey (1 test) passed on isolated SQLite.
- Repository `context.md` and `decisions.md` were absent; clarification, board, and assigned dependency artifacts supplied the controlling requirements.
- Standard Artisan test startup could load a repository environment file before tests, so `Tests\TestCase::createApplication()` now directs Dotenv to a random nonexistent temp path, supplies synthetic test-only key/config, clears DB_URL, and forces SQLite memory. Use direct PHPUnit to keep this boundary; do not run `composer test` where its Artisan pre-bootstrap would violate the no-environment-file constraint.
- Docker CLI presence did not imply daemon availability; exact `docker info` failed because the Docker Desktop Linux engine named pipe was missing. Docker and browser axes are independent; Playwright passed despite the DB-infrastructure fallback.
- F-04 device-capacity race, production DB engine/locking, selector naming (`access_mode` vs `access_backend`), Airtel provider-field guarantee, deployment reachability, and credential rotation remain open; escalated to their owners. No app source or infrastructure was changed.
- Pint initially found only line-ending normalization after the test bootstrap edit; applying Pint and rerunning Pint, diff check, PHP syntax, and the isolated regression subset passed.
- Learnings consumed: [tester/airtel-optional-status-fields, tester/pesapal-order-binding-regressions, tester/playwright-laravel-isolated-env, tester/sqlite-migration-safety]
