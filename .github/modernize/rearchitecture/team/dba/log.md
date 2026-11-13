## [t6] Additive router/accounting schema proposal
- Codebase/domain discoveries: `sessions_log` already models access and has usage counters; online sale-to-voucher linkage exists only in transaction metadata; no RADIUS accounting writer was found.
- Wrong assumptions and corrections: the prior t5 test baseline revision predates payment-safety source changes; compared source paths and reran the full suite/build on current HEAD.
- Debugging dead-ends and what actually worked: context.md, decisions.md, and an existing DBA log were absent; upstream artifacts, board, current migrations/models, and current-checkout tests supplied sufficient context.
- Techniques/patterns worth reusing: keep `site_agent` as the explicit default; use composite tenant/router keys and append-only protocol evidence; avoid historical backfill and retain populated audit data on rollback.
- Learnings consumed: [(none)]

## [t9] Confirm DB evidence boundary and isolated test target
- Codebase/domain discoveries: MySQL 8.0 is the documented `.env.example`/Compose/README target, but the live production engine/version cannot be inferred from these repository files. `config/database.php` also defines SQLite, MariaDB, PostgreSQL, and SQL Server. `phpunit.xml` force-selects SQLite `:memory:`; `phpunit.ci-db.xml` permits environment-driven external DB selection.
- Wrong assumptions and corrections: documented/local MySQL is not confirmation of the production database. Keep production-engine-specific validation blocked rather than selecting a DB by inference.
- Debugging dead-ends and what actually worked: no DB access was needed; source configuration, test XML, README, and prior t5/t6 evidence establish the boundary.
- Techniques/patterns worth reusing: use the standard in-memory PHPUnit configuration only; do not run the environment-driven CI DB config without a verified disposable target. SQLite test success does not prove production-engine locking/DDL behavior.
- Learnings consumed: [dba/production-engine-evidence-boundary]

## [t10.1] Refine disconnect outbox and NAS-session generation schema proposal
- Codebase/domain discoveries: t10 approves generation-aware RADIUS accounting and durable disconnect commands; a router/NAS identity cursor is needed to serialize reused NAS session IDs and allocate generations. Disconnect delivery acknowledgement is not proof of a customer disconnect.
- Wrong assumptions and corrections: a unique `(router_id, nas_session_id)` accounting-session key is insufficient because NAS IDs can be reused after Stop; a generation key protects database state but does not make the NAS disconnect protocol generation-aware.
- Debugging dead-ends and what actually worked: `context.md` and `decisions.md` are absent in this workspace; t6/t10, clarification.md, board.md, and DBA learnings supplied the required decision context.
- Techniques/patterns worth reusing: persist outbox intent before network I/O; lock briefly, lease/retry the same command ID, bind it to a session generation, and require accounting Stop or approved authoritative reconciliation for terminal status. Quarantine events whose generation/order cannot be established.
- Learnings consumed: [dba/production-engine-evidence-boundary, dba/radius-accounting-schema]
