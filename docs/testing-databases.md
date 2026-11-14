# Test databases and payment regression

The default `vendor/bin/phpunit` / `php artisan test` suite uses in-memory SQLite, regardless of application `DB_*` or `.env` settings. The tests isolate the environment and disable configuration cache and real WireGuard.

For server-engine tests, use ONLY a newly created, disposable local service: `RefreshDatabase` can destroy its schema. Never expose production, shared, or valuable databases at the test addresses, even by port forwarding. The harness deliberately ignores application connection settings and only permits the fixed loopback endpoints below with the test-only user/password and database. It checks the live PDO driver and database before test migrations. These safeguards reduce accidental connection risk but cannot distinguish a deliberately configured production database impersonating the test service.

- MySQL-compatible: `127.0.0.1:13306`, database `wifi_billing_test`, user `wifi_test_runner`, password `disposable-test-password`.
- PostgreSQL: `127.0.0.1:15432`, same database/user/password; make the test user owner of the database and public schema.

CI creates fresh service containers and grants a dedicated test user access. To run against equivalent disposable services locally:

```sh
TEST_DB_DRIVER=mysql TEST_EXPECTED_DB_DRIVER=mysql TEST_DB_DISPOSABLE=yes vendor/bin/phpunit -c phpunit.ci-db.xml
TEST_DB_DRIVER=pgsql TEST_EXPECTED_DB_DRIVER=pgsql TEST_DB_DISPOSABLE=yes vendor/bin/phpunit -c phpunit.ci-db.xml
```

The CI config requires an explicit expected driver and refuses mismatches or missing server opt-in. An additional test queries the live server to check the selected engine and database. This verifies the target, not concurrency or row-lock correctness.

The payment regression simulates provider verification and a failing activation without real provider/router traffic. It characterizes the current rollback of the completed payment, wallet credit, ledger and voucher, then checks successful retry/idempotency. The future-durability test is intentionally skipped because this change does not fix payment production logic. Separate design work is needed to make verified payments durable when activation fails.
