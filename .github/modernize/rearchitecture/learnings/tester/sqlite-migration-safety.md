# SQLite Migration Safety

Laravel migration re-entry and destructive rollback guards can be tested using the repository's existing `RefreshDatabase` SQLite `:memory:` connection without touching a configured default or production database.

## What Happened

For wifi-billing-saas t14, PHPUnit's `RefreshDatabase` applied the fresh schema in memory; focused tests then invoked the additive migration objects again to verify re-entry and invoked guarded `down()` paths only after inserting synthetic test rows. The new tests assert the connection driver and database name before exercising migration behavior.

## Takeaway

Reuse `phpunit.xml`'s forced SQLite memory database, assert `sqlite` and `:memory:` inside migration tests, and call migration objects directly for repeatability/rollback checks. Do not run `artisan migrate` against the default database to simulate a migration test.

## History
- 2026-11-13 (wifi-billing-saas/t14): initial
