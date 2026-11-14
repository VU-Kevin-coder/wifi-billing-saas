# Test databases and payment regression

The default `vendor/bin/phpunit` / `php artisan test` suite uses in-memory SQLite, regardless of application `DB_*` or `.env` settings. The test harness isolates the environment and disables configuration cache and real WireGuard.

**Server-engine suites are destructive:** `RefreshDatabase` can wipe and recreate the selected schema. Run them only in an isolated environment whose database service you independently created and verified is disposable. Never expose a production, shared or valuable database at these addresses, including via a local proxy, tunnel, container port mapping, forwarded port or DNS/network redirection. **Loopback, fixed credentials and database name, and `TEST_DB_DISPOSABLE=yes` cannot establish disposability.** A real or forwarded service may match all of them accidentally; a connection happens before the live PDO/driver/database check. That check is not a safety proof and cannot undo an unwanted connection or detect a valuable database with the same name.

The harness ignores application `DB_*` settings and clears the entire ambient `PG*` environment namespace (including `PGHOSTADDR`, `PGSERVICE`, `PGSERVICEFILE` and `PGOPTIONS`) before Laravel bootstrap and PDO connection. This blocks those libpq overrides, not all possible ways a local endpoint could route to the wrong service. Independently verify and isolate the service and surrounding host/network setup before running; do not rely on the test guard as the only boundary.

- MySQL-compatible: `127.0.0.1:13306`, database `wifi_billing_test`, user `wifi_test_runner`, password `disposable-test-password`.
- PostgreSQL: `127.0.0.1:15432`, same database/user/password; make the test user owner of the database and public schema.

CI creates fresh service containers and grants a dedicated test user access. To run against independently verified disposable services locally:

```sh
TEST_DB_DRIVER=mysql TEST_EXPECTED_DB_DRIVER=mysql TEST_DB_DISPOSABLE=yes vendor/bin/phpunit -c phpunit.ci-db.xml
TEST_DB_DRIVER=pgsql TEST_EXPECTED_DB_DRIVER=pgsql TEST_DB_DISPOSABLE=yes vendor/bin/phpunit -c phpunit.ci-db.xml
```

The CI config requires both `TEST_DB_DRIVER` and `TEST_EXPECTED_DB_DRIVER` explicitly and refuses mismatches or missing server opt-in. The live connection test checks the selected engine and database name, not disposability, isolation, concurrency or row-lock correctness.

The payment regression simulates provider verification and a failing activation without real provider/router traffic. It characterizes the current rollback of the completed payment, wallet credit, ledger and voucher, then checks a **sequential** successful retry and repeat. This is not a concurrency or exactly-once proof. The future-durability test is intentionally skipped because this change does not fix payment production logic; it allows either handled or propagated activation failures. Separate design work is needed to make verified payments durable when activation fails.
