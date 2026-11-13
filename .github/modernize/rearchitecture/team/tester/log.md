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
