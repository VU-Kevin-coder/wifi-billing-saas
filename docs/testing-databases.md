# Test databases and payment regression

Run the default suite directly with `vendor/bin/phpunit`. With the checked-in PHPUnit configurations, the default Laravel test suite uses in-memory SQLite, regardless of application `DB_*` or `.env` settings. Laravel feature-test bootstrap isolates the application environment and disables configuration cache and real WireGuard.

**Do not use `php artisan test` to run this suite.** Artisan boots Laravel before PHPUnit's bootstrap and the test database guards, and application service providers may connect to an ambient database before those guards run. The checked-in PHPUnit configuration cannot protect that earlier application bootstrap.

**Server-engine suites are destructive:** `RefreshDatabase` can wipe and recreate the selected schema. Run them only in an isolated environment whose database service you independently created and verified is disposable. Never expose a production, shared or valuable database at these addresses, including via a local proxy, tunnel, container port mapping, forwarded port or DNS/network redirection. **Loopback, fixed credentials and database name, and `TEST_DB_DISPOSABLE=yes` cannot establish disposability.** A real or forwarded service may match all of them accidentally; a connection happens before the live PDO/driver/database check. That check is not a safety proof and cannot undo an unwanted connection or detect a valuable database with the same name.

The checked-in PHPUnit configurations load a bootstrap that validates test database selection and clears the ambient `PG*` namespace (including `PGHOSTADDR`, `PGSERVICE`, `PGSERVICEFILE` and `PGOPTIONS`) before tests run, including standalone PHPUnit-only tests. Laravel feature-test bootstrap repeats these checks and ignores application `DB_*` settings before its connection. These checks do not intercept arbitrary PDO connections made by test code, prevent tests from changing the environment later, or apply when the checked-in bootstrap is bypassed or overridden. They block those libpq overrides at bootstrap, not all ways a local endpoint could route to the wrong service. Independently verify and isolate the service and surrounding host/network setup before running; do not rely on the test guard as the only boundary.

- MySQL-compatible: `127.0.0.1:13306`, database `wifi_billing_test`, user `wifi_test_runner`, password `disposable-test-password`.
- PostgreSQL: `127.0.0.1:15432`, same database/user/password; make the test user owner of the database and public schema.

CI creates fresh service containers and grants a dedicated test user access. To run against independently verified disposable services locally:

```sh
TEST_DB_DRIVER=mysql TEST_EXPECTED_DB_DRIVER=mysql TEST_DB_DISPOSABLE=yes vendor/bin/phpunit -c phpunit.ci-db.xml
TEST_DB_DRIVER=pgsql TEST_EXPECTED_DB_DRIVER=pgsql TEST_DB_DISPOSABLE=yes vendor/bin/phpunit -c phpunit.ci-db.xml
```

The checked-in CI config requires both `TEST_DB_DRIVER` and `TEST_EXPECTED_DB_DRIVER` explicitly and rejects mismatches or missing server opt-in in its PHPUnit bootstrap, including standalone unit runs. The live connection check applies only to Laravel tests, after the application has bootstrapped but before `RefreshDatabase` runs migrations. Application bootstrap may already have connected; the check verifies the selected engine and database name, not disposability, isolation, concurrency or row-lock correctness.

The payment regression simulates provider verification and failing activation without real provider/router traffic. It checks durable confirmation and wallet credit, explicit pending status, recovery of recorded work, repeated processing, a simulated expired worker lease, tenant scoping and the retry limit. These are sequential tests, not a concurrency or real-process-crash proof. See `docs/payment-fulfillment-durability.md` for design and limitations.
