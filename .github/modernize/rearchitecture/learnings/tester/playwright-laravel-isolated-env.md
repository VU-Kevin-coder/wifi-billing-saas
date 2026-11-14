# Playwright Laravel Isolated Environment

For browser tests in this Laravel repository, boot the application with an explicit SQLite test file and direct Dotenv to an empty temporary directory so the test server never loads project environment files.

## What Happened

In wifi-billing-saas t15, Playwright 1.65.1 and Chromium 159 exercised the existing Blade payment-status page. PHPUnit's SQLite `:memory:` connection cannot serve persistent state across PHP HTTP requests, so the browser fixture used a dedicated disposable SQLite file under `storage/framework/testing/`. Laravel was bootstrapped by test-only setup/router scripts that select an empty temporary environment path and set `APP_CONFIG_CACHE` to a non-existent file.

## Takeaway

Keep the browser DB, session directory, encrypted test session fixture, and result output under the ignored testing directory. Guard the database path and connection before `migrate:fresh`; set `DB_URL` empty; do not invoke `artisan serve` with the default bootstrap when environment-file access is prohibited. Build Vite assets via the Vite Node API with `envDir` pointed at the isolated empty directory.

## History
- 2026-11-13 (wifi-billing-saas/t15): initial
