# t6 — Additive Router Domain and Accounting Schema Proposal

## Summary

Recommend a small additive schema extension that defaults every existing router to the current access path, records one-to-one sale-to-voucher linkage for new online sales, and persists replay-safe RADIUS accounting evidence without making it a second entitlement authority. This is a design artifact only: no source files or migrations were changed.

## Current-checkout baseline

- Captured at `2026-11-13T19:11:15Z`; HEAD `29aad52ee185b5734e1abfc645f9cea1faa3e871`.
- `git status --short` at capture: only pre-existing edits to `.github/modernize/rearchitecture/artifacts/project-profile.yaml` and `board.md`; neither was modified for this task.
- Current code differs from t5's earlier test baseline in payment files and tests. The full suite was rerun on this checkout with `php artisan test --do-not-cache-result`: **540 passed, 0 failed, 0 skipped; 2,910 assertions; exit 0**. `phpunit.xml` forces SQLite `:memory:`, so this did not migrate the default DB.
- `npm run build`: **passed, exit 0**.
- No migration, deployment, router operation, or infrastructure change was run.

## Existing-schema findings

- `routers` has tenant ownership and RouterOS API credentials; `api_password` is encrypted in `Router` but there is no per-router access-backend selector or RADIUS credential (`database/migrations/2026_10_31_172000_create_routers_table.php`, `app/Models/Router.php`).
- `sessions_log` already represents application access sessions and carries `tenant_id`, `router_id`, voucher/package links, status, timestamps, and byte/rate counters (`2026_10_31_173000_create_sessions_log_table.php`, `Session.php`). Its counters currently have no RADIUS accounting writer.
- Successful sale fulfillment locks `transactions`, writes the wallet through `WalletService`, creates a voucher, stores its ID in `transactions.meta`, and activates it within the existing database transaction (`PaymentService::handleSuccessfulPackagePayment`). Do not create a competing wallet or entitlement record.
- `transactions.meta.voucher_id` is an application-level link, not a foreign-key/unique constraint. Voucher codes are tenant-scoped. `Package` already carries duration, speed, device, and data-limit terms; those terms must not change.
- Existing RouterOS provisioning explicitly sets `use-radius=no`; the current RADIUS schema/protocol path is not present.
- The default connection in `config/database.php` is SQLite, while MySQL, MariaDB, PostgreSQL, and SQL Server are also configured. A production database engine was not established by this task.

## Proposed additive schema

### 1. Explicit router access mode and RADIUS identity

Extend `routers`:

| Field | Proposed type / rule | Purpose |
|---|---|---|
| `access_backend` | `varchar(16) NOT NULL DEFAULT 'site_agent'`; allowed values `site_agent`, `radius` | Selects the path per router. The default preserves existing router behavior; missing or invalid selection never implies RADIUS. |
| `radius_nas_identifier` | nullable string, globally unique when non-null | Stable NAS identity used to map an authenticated NAS to exactly one router. Canonicalize consistently. |
| `radius_shared_secret` | nullable text, encrypted at application layer | Separate, recoverable per-router RADIUS credential. Add an encrypted cast; never reuse API, site-agent, user, or WireGuard credentials. |

Keep all existing routers on `site_agent`; do not infer mode from agent presence, package settings, or credentials. A router may be set to `radius` only when its required identity/credential is present and valid. Fail closed if mode or credential configuration is missing. Prefer a database check for the mode/credential invariant where the selected database supports it, with application validation retained in all cases. Credential rotation must be coordinated; this single-current-secret shape does not support a dual-secret grace window.

The encrypted secret is not usable by a normal FreeRADIUS SQL lookup. The integration must obtain it through a trusted adapter/secret-delivery mechanism. Do not give FreeRADIUS Laravel's `APP_KEY` or permission to read the encrypted column.

### 2. Durable sale-to-voucher identity for new sales

Add `vouchers.sale_transaction_id` as a nullable unique foreign key to `transactions.id`, with restrictive deletion. It is nullable for manually generated vouchers. On new online-sale fulfillment, write it at voucher creation in the same transaction that completes the sale, credits the ledger, and creates the session. A unique constraint prevents a transaction from being linked to multiple sale vouchers; the existing transaction lock remains responsible for replay-safe fulfillment.

For tenant correctness, add a unique parent key on `transactions(tenant_id, id)` and constrain `vouchers(tenant_id, sale_transaction_id)` to that pair when supported by the selected engine. The application must still validate tenant/package correspondence. Do **not** backfill historical rows in this task: existing `transactions.meta.voucher_id` remains the compatibility lookup for older completed sales. New reads should prefer the typed relation and fall back to existing metadata for legacy rows.

This adds a relational pointer, not a new entitlement aggregate. Existing `Voucher` and `Session` remain the canonical sale/access records; `WalletService` remains the only wallet balance writer.

### 3. RADIUS accounting identity and evidence

Create `radius_accounting_sessions` as a mutable, one-row-per-NAS-session projection:

| Field | Proposed type / rule | Purpose |
|---|---|---|
| `id` | primary key | Internal row identity. |
| `tenant_id`, `router_id` | non-null; composite FK to `routers(tenant_id, id)` | Derive and constrain tenant ownership from the authenticated router. Add the supporting unique parent index if required. |
| `session_id` | nullable FK to `sessions_log.id` | Links accounting to an existing application session after exact tenant/router/voucher matching. Null means unmatched/quarantined; it must not grant access or mutate a business session. |
| `nas_session_id` | bounded string | NAS-provided session identity; treat as opaque and compare consistently. |
| `state` | bounded string, constrained to documented accounting states | Current projection of Start/Interim/Stop, not an entitlement authority. |
| `started_at`, `last_accounting_at`, `stopped_at` | nullable timestamps | Reconciliation and truthful disconnect evidence. |
| `input_bytes`, `output_bytes` | non-negative big integers | Latest accepted cumulative counters; never sum retransmitted snapshots. |

Add a unique key on `(router_id, nas_session_id)` so retransmitted packets address the same projection. Add the composite parent index on `sessions_log(tenant_id, router_id, id)` and a matching nullable composite FK from the projection if supported; this prevents binding an accounting session to a different tenant/router's application session. Do not add a unique constraint on `session_id`: multiple NAS sessions may legitimately map to one application session depending on existing device-limit semantics.

Create append-only `radius_accounting_events`, with an FK to `radius_accounting_sessions` and:

- a server-computed SHA-256 fingerprint, unique per accounting session, for packet replay deduplication;
- normalized `status_type`, event time, session time, reconstructed input/output byte counters (including RADIUS gigawords where supplied), termination cause, and necessary IP/MAC evidence;
- `received_at` for ingestion audit and indexes supporting `(accounting_session_id, event_time)` and retention scans.

Do not persist shared secrets, passwords, bearer tokens, raw voucher codes, payment tokens, or unrestricted raw packet attributes in accounting evidence. Insert each distinct event once; update the projection only for an accepted newer event. Older/replayed packets are no-ops. Keep Stop evidence terminal for the same NAS session identity; an unknown session remains unbound for reconciliation. The application reconciler, not a RADIUS database account, updates `sessions_log.bytes_in/bytes_out` and lifecycle status after verifying the linked session's tenant, router, and voucher. Do not overload `sessions_log.provisioned_at`, whose current meaning is site-agent confirmation.

### Tenant-safe database access

- Prefer an application-owned authorization/accounting adapter. If direct SQL integration is later selected, use a distinct least-privilege principal and parameterized, narrowly scoped operations (for example, specific stored procedures); no broad reads of `transactions`, `wallet_ledger_entries`, `payment_token_hash`, phone/customer data, site-agent credentials, or credential ciphertext.
- The RADIUS principal must not update vouchers, package terms, wallet balances, payment status, or `sessions_log` lifecycle/counters. It may submit normalized accounting facts only through the approved write boundary.
- Derive tenant identity from the authenticated NAS/router mapping, never from a RADIUS username, packet-supplied tenant value, or customer client. Confirm engine-specific grants and stored-procedure/view behavior only after the actual production database is identified.

## Compatibility and migration requirements

- Keep all old routers at `access_backend=site_agent` and preserve `SITE_AGENT_REQUIRED`, current APIs, agent command behavior, payment-token rules, payment status meanings, package terms, and voucher semantics.
- No new table is used to shadow package entitlements. Payment verification remains with the existing provider flows; the typed sale-voucher link and session are written only after verified payment in the existing locked transaction.
- No historical payment, voucher, router, or session is reassigned or rewritten. Legacy sale links continue to resolve through `transactions.meta`.
- Migrations must be additive, guarded for safe recovery from partial application, and tested on a disposable/in-memory database. Build composite unique indexes before dependent composite FKs. Test SQLite and the selected production engine; SQLite tests alone do not prove production FK/locking behavior.
- Review index-build and FK-lock impact against live table size before deployment. `routers` and `sessions_log` may require an online-index strategy if they are large.

## Rollback and irreversible effects

1. **Before RADIUS is enabled and before new typed sale links exist:** application rollback can ignore the added nullable/defaulted columns and empty tables. Schema rollback is possible only after a preflight confirms no non-default router mode, credential, typed sale link, accounting session, or accounting event exists.
2. **After typed sale links are populated:** dropping `sale_transaction_id` loses durable sale/voucher linkage. Preserve the column or export/verify all links before removal; a `down()` migration should refuse to discard populated data.
3. **After accounting ingestion:** dropping accounting tables destroys audit and recovery evidence. Disable the router path first, retain the tables, and use an explicitly approved archive/retention procedure before any destructive rollback. Do not silently cascade-delete accounting evidence with router/tenant deletion.
4. **Router mode rollback:** switch only explicitly enabled routers back to the legacy path after confirmed customer-session handling. Do not bulk-change all router modes or globally disable agent requirements. Keep stored credential material protected until its authorized revocation/retention handling is complete.
5. Test `up`, repeated migration invocation/partial-recovery behavior, and guarded `down` only against disposable databases. Never run these migrations against the default DB as part of this phase.

## Required implementation tests (for the future schema/code task)

- Existing-router migration default and unchanged activation semantics; explicit RADIUS mode with missing/invalid credentials fails closed.
- NAS identifier uniqueness, encrypted credential persistence/serialization redaction, and tenant/router composite-FK rejection.
- New sale records its `sale_transaction_id` atomically; duplicate payment fulfillment cannot create a second voucher; legacy metadata-only sales still resolve.
- Accounting duplicate fingerprints create one event; repeated Start/Interim/Stop reconcile one NAS session; cross-tenant/router session binding fails; unknown sessions remain unbound; stale events cannot reverse Stop or decrease accepted cumulative usage.
- Run all existing payment, wallet, agent, voucher, package, and tenant-isolation suites unchanged, followed by full tests and build. Exercise the selected production engine in an isolated test database before rollout.

## Open risks / decisions required

- The architecture owner must decide how FreeRADIUS obtains the per-router secret without access to Laravel's decryption key, and whether authorization/accounting uses an app adapter or engine-specific restricted SQL operations.
- Production DB engine/version is not established; composite constraints, check enforcement, JSON support, online DDL, and SQL-role design require confirmation before implementation.
- Restrictive router deletion preserves accounting attribution but can block hard deletion once accounting exists; the product/operator needs a router archival/retention path before enabling accounting.
- RADIUS byte-counter rollover, NAS session-ID reuse, event retention, and supported interim-update frequency need explicit implementation policy and lab evidence. They must not be used to change package limits.

## Test Results

- Baseline command: `php artisan test --do-not-cache-result` — **540 passed, 0 failed, 0 skipped (2,910 assertions), exit 0** on current HEAD; PHPUnit uses isolated SQLite `:memory:`.
- Baseline build: `npm run build` — **passed, exit 0**.
- Schema migration tests: not applicable to this design-only task; no migrations were run.

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/artifacts/t1-architect.md` — access-mode, payment, tenant-boundary, and accounting invariants.
- `.github/modernize/rearchitecture/artifacts/t2-backend.md` — current Laravel model and fulfillment seams.
- `.github/modernize/rearchitecture/artifacts/t3-pm.md` — frozen sales, access, API, tenant, and regression requirements.
- `.github/modernize/rearchitecture/artifacts/t4.5-security.md` — payment-verification review and credential/tenant security concerns.
- `.github/modernize/rearchitecture/artifacts/t5-tester.md` — prior baseline provenance and isolated SQLite test posture; current checkout was rerun for this task.
- `.github/modernize/rearchitecture/clarification.md` — additive-only, no-default-DB-migration, no-router-change, and phase boundary constraints.
- `.github/modernize/rearchitecture/board.md` — task status and phase scope (read-only).

## Evidence Mapping

- `t1-architect.md#Additive Phase 1 architecture` and `#RADIUS authorization and accounting` → defaulted router mode, existing session as entitlement authority, normalized accounting evidence, and constrained access boundary above.
- `t2-backend.md#Current domain and ownership seams` and `#Payment and fulfillment path` → additive voucher-sale linkage and reuse of the existing locked transaction/WalletService/VoucherActivationService seam.
- `t3-pm.md#REQ-004`, `#REQ-005`, `#REQ-008`, and `#REQ-011` → composite tenant constraints, unchanged package/agent behavior, one-sale fulfillment, and prohibited migration/deployment/router actions.
- `t4.5-security.md#Review evidence` and `#Remaining risks / non-blocking findings` → require verified provider flow, maintain fail-closed tenant binding, and treat encrypted RADIUS secret access as an explicit integration decision.
- `t5-tester.md#Test Results` plus current-checkout rerun above → prior isolated baseline provenance and fresh 540-test current HEAD verification.
- `clarification.md#Backend` and `#Generic` → additive schema, no unverified legacy backfill, zero-regression compatibility, and no Phase 2 infrastructure or production/router activity.
