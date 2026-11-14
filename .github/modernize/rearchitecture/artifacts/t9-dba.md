# t9 — Production Database Evidence and Isolated Test Target

## Decision

- **Actual production database: UNCONFIRMED.** Repository files describe a MySQL 8.0 setup, but repository configuration alone does not establish which engine/version is running in production. No database connection was attempted.
- **Documented repository/local target: MySQL 8.0.** `.env.example` selects MySQL, Docker Compose runs `mysql:8.0`, and the README identifies MySQL 8.0. These are repository/local-development indicators, not production attestation.
- **Approved automated test target: SQLite `:memory:` only.** `phpunit.xml` force-overrides `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. The standard test suite therefore has an isolated in-memory target.
- **Production engine-specific validation and DB operations remain blocked.** Do not run migrations, connect to a DB, or use environment-driven DB settings for this task. Confirm the production engine/version through an authorized source before claiming production compatibility or running engine-specific tests.

## Evidence

| Evidence source | Finding | What it establishes |
|---|---|---|
| `config/database.php` | Default connection falls back to SQLite; connections are defined for SQLite, MySQL, MariaDB, PostgreSQL, and SQL Server. | Framework capabilities and fallback only; not production selection. |
| `.env.example` | `DB_CONNECTION=mysql` and Docker service host `db`. | Example/local configuration. |
| `docker-compose.yml` | App and scheduler set MySQL; database service uses `mysql:8.0`. | Local Compose database version. |
| `README.md` | Technology stack and environment example identify MySQL 8.0/MySQL. | Documented project target; no live deployment evidence. |
| `phpunit.xml` | PHPUnit force-sets SQLite with `:memory:`. | Safe, isolated standard automated-test target. |
| `phpunit.ci-db.xml` | Omits forced `DB_*` values and explicitly allows environment-supplied MySQL/PostgreSQL. | Potential external DB use; do not select or run it absent an explicitly isolated test DB configuration. |

## Downstream Guardrails

- Treat MySQL 8.0 as the documented local/Compose target, not as the confirmed production engine. Keep production engine/version-dependent schema assumptions unresolved until confirmed.
- Use only the existing `phpunit.xml` test configuration for Phase 1 automated tests. Do not pass an alternate DB config or override its SQLite settings.
- The t6 DBA schema proposal's engine-specific constraint, locking, DDL, and index concerns remain open until the production DB engine/version is confirmed. SQLite tests do not establish MySQL/PostgreSQL locking or production DDL behavior.
- No migration was executed, no DB connection was made, and no project source/configuration was changed for this task.

## Verification

- Inspected the repository configuration and documentation listed above; no DB client, query, migration, or deployment action was invoked.
- No test command was run for this read-only decision task. The current-checkout baseline already recorded in `.github/modernize/rearchitecture/artifacts/t6-dba.md` reports `php artisan test --do-not-cache-result`: 540 passed, 0 failed, 0 skipped, using the in-memory SQLite target.

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/clarification.md` — phase boundary and requirement not to run migrations against the unknown production DB.
- `.github/modernize/rearchitecture/board.md` — read-only task/phase status and confirmation that t9 is the active decision gate.
- `.github/modernize/rearchitecture/artifacts/t6-dba.md` — proposed schema's unresolved production-engine-specific behavior and current-checkout SQLite baseline.
- `.github/modernize/rearchitecture/artifacts/t5-tester.md` — rationale for not using environment-driven `phpunit.ci-db.xml`.

## Evidence Mapping

- `clarification.md#Backend` and the follow-up database decision → production engine remains unconfirmed; no connection or migration was attempted.
- `phpunit.xml#php` → isolated SQLite `:memory:` target selected for automated Phase 1 tests.
- `phpunit.ci-db.xml#php` → environment-driven MySQL/PostgreSQL test target is excluded absent verified isolated test DB settings.
- `t6-dba.md#Open risks / decisions required` → production-engine-specific schema validation remains unresolved.
