# PHPUnit Isolated Environment

Laravel PHPUnit runs must override the application environment path before kernel bootstrap and explicitly force SQLite memory so tests never depend on repository environment files or ambient database URLs.

## What Happened
For wifi-billing-saas task t20, the user prohibited any access to `.env` or other secrets. Running the normal Artisan test command would bootstrap Laravel before PHPUnit and could load a repository environment file. `Tests\TestCase::createApplication()` was therefore hardened to use a random nonexistent temp environment/config-cache path, a synthetic test-only key, and explicit SQLite `:memory:` settings with `DB_URL` cleared. Direct PHPUnit then passed 564 tests; the Playwright harness independently kept its existing isolated disposable SQLite path.

## Takeaway
When environment-file access is forbidden, direct PHPUnit is safe only when the test application sets `useEnvironmentPath()` and a disabled environment filename before `Kernel::bootstrap()`. Force DB connection/name and clear DB_URL before bootstrap; keep the existing PHPUnit config and test DB rather than adding a duplicate config. Do not use `php artisan test` or a Composer aggregate that boots Artisan first unless its bootstrap is independently proven to avoid environment-file access.

## History
- 2026-11-14 (wifi-billing-saas/t20): initial
