# t12 — Additive Router, Sale-Link, and Accounting Schemas

## Summary

Added two additive Laravel migrations for the optional, explicitly router-scoped RADIUS domain. Existing routers default to `site_agent`; legacy sales remain untouched; new RADIUS accounting is generation-aware, router/tenant constrained, and paired with a durable disconnect outbox. The migrations contain guarded re-entry checks and refuse rollback when router credentials, typed sale links, or accounting evidence have been populated.

No application behavior, models, production configuration, infrastructure, routers, or existing entitlement/payment data were changed. No production database was contacted and no standalone migration command was run.

## Deliverables

- `database/migrations/2026_11_16_000001_add_radius_router_and_sale_links.php` — adds the per-router access selector and NAS identity/credential columns; nullable typed sale-to-voucher linkage; and composite parent keys for tenant-safe foreign keys.
- `database/migrations/2026_11_16_000002_create_radius_accounting_tables.php` — adds the durable NAS-session generation cursor, generation projection, normalized accounting evidence, and idempotent disconnect-command outbox.

### Schema notes

- `routers.access_backend` defaults to `site_agent`; `radius_nas_identifier` is unique when populated. `radius_shared_secret` is nullable storage only; the Laravel model's encrypted cast and one-time delivery workflow remain implementation requirements for t13.
- `vouchers.sale_transaction_id` is nullable and unique, with a restrictive composite FK to `(transactions.tenant_id, transactions.id)`. There is no historical backfill or change to `transactions.meta`.
- `radius_nas_session_identities` serializes generation allocation by `(router_id, nas_session_id)` and stores a monotonic cursor. Its nullable current-generation pointer is constrained to a generation owned by that identity.
- `radius_accounting_sessions` projects one generation, constrains router/tenant and optional application-session linkage, and tracks lifecycle/cumulative counters. It is not an entitlement source.
- `radius_accounting_events` stores bounded normalized fields, generation-scoped fingerprints, receive time, and quarantine disposition; no raw packets, credentials, voucher codes, payment data, or retention policy were added.
- `radius_disconnect_commands` binds a stable UUID and idempotency key to the exact tenant/router/session generation and records retry lease and delivery-only status.
- Explicit unique parent keys support composite foreign keys. Table/column/index/FK existence checks support safe migration re-entry. `down()` refuses to remove populated typed sale links, RADIUS router configuration/credentials, or any rows in the accounting/evidence/outbox tables.

## Test Results

- Command: `php artisan test --do-not-cache-result`
- Passed: 540 tests (2,910 assertions)
- Failed: 0
- Skipped: 0
- Exit code: 0; duration 58.26 seconds.
- The standard test suite used the `phpunit.xml`-forced SQLite `:memory:` database and exercised a fresh schema build. No explicit `artisan migrate`, production connection, or environment-driven `phpunit.ci-db.xml` run was made.
- Syntax checks: `php -l` on both new migration files — passed.
- `git diff --check` reported pre-existing trailing blank-line issues only in `project-profile.yaml` and `board.md`, which were already modified before this task. The new migrations have no trailing whitespace.
- Not verified here: repeated migration execution, rollback refusal with populated rows, and production-engine DDL/locking. These require the focused migration tests and an authorized disposable instance of the confirmed production engine; production engine remains unknown.

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/artifacts/t10.1-dba.md` — generation cursor, stable disconnect command/outbox, quarantine, event normalization, and evidence-preserving rollback requirements.
- `.github/modernize/rearchitecture/artifacts/t11-security.md` — per-router secret isolation, no database access for FreeRADIUS, and credential handling requirements.
- `.github/modernize/rearchitecture/artifacts/t6-dba.md` — router opt-in default, typed sale link, composite tenant constraints, existing domain ownership, and additive-only requirements.
- `.github/modernize/rearchitecture/artifacts/t10-architect.md` — Laravel policy/state authority and accounting/disconnect truthfulness.
- `.github/modernize/rearchitecture/artifacts/t9-dba.md` — production DB is unconfirmed; standard in-memory SQLite tests are the only approved test target.
- `.github/modernize/rearchitecture/clarification.md` — preserve Laravel 12/PHP 8.2, current contracts and access terms; do not perform production, router, migration, or Phase 2 operations.
- `.github/modernize/rearchitecture/board.md` — read-only task and phase status.

## Evidence Mapping

- `t10.1-dba.md#radius_nas_session_identities` and `#radius_accounting_sessions` → generation cursor and composite identity/session constraints in `2026_11_16_000002_create_radius_accounting_tables.php`.
- `t10.1-dba.md#radius_accounting_events` and `#Generation and Event Processing Rules` → fingerprint uniqueness, normalized event columns, nullable chronology, and quarantine fields in the event table.
- `t10.1-dba.md#radius_disconnect_commands` → stable command UUID, generation-scoped idempotency, lease/retry fields, and delivery-only acknowledgement columns.
- `t11-security.md#Approved credential boundaries` → nullable per-router secret storage only; encrypted model cast, one-time reveal, service credential, and `APP_KEY` isolation remain for backend/security implementation, not schema exposure.
- `t6-dba.md#Proposed additive schema` and `#Compatibility and migration requirements` → site-agent default, nullable typed sale link, no backfill, and composite parent keys in `2026_11_16_000001_add_radius_router_and_sale_links.php`.
- `t9-dba.md#Decision` and `#Downstream Guardrails` → test run limited to forced SQLite memory; production-engine and standalone migration execution remain blocked.

## Residual Risks / Handoff

- Backend must add Laravel-side encryption and serialization protection for `radius_shared_secret` before any write path, alongside its approved one-time secret delivery flow.
- The router/accounting schemas intentionally do not establish application authorization, event-order validation, append-only enforcement, or session reconciliation; those belong to the subsequent implementation and test tasks.
- Production engine/version remains unconfirmed. SQLite test success does not establish production DDL, FK, index-build, or lock behavior. Do not execute migrations against an unknown/live database.
- Stay at the Phase 1 release gate; this task did not authorize Phase 2, infrastructure setup, deployment, or router changes.
