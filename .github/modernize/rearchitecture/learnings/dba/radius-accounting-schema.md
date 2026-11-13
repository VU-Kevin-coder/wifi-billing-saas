# RADIUS Accounting Schema

Model RADIUS as router-scoped protocol evidence linked to existing Laravel sessions, not as a second entitlement or payment authority.

## What Happened
For wifi-billing-saas t6, current models already provide tenant-owned routers, vouchers, transactions, and sessions, while no RADIUS accounting writer exists. The schema proposal keeps the legacy router mode as the default, adds a typed link for new sale vouchers without backfilling legacy metadata, and separates a deduplicated NAS-session projection from append-only accounting events. Encrypted router secrets cannot be consumed by FreeRADIUS through ordinary SQL without a trusted decryption boundary.

For t12, implemented the additive schema using explicit composite parent keys before tenant-safe foreign keys, a generation cursor with a pointer constrained to that identity's generation, and a disconnect outbox whose delivery state is distinct from session termination. Migration rollback refuses populated router credentials/configuration, typed sale links, or accounting evidence. The full existing suite passed on PHPUnit's forced SQLite `:memory:` target; no production DB or standalone migration command was used.

## Takeaway
For future RADIUS adoption, derive tenant identity from the authenticated router; retain the existing voucher/session as entitlement truth; use server-computed, generation-scoped fingerprints for normalized Start/Interim/Stop evidence; prevent RADIUS principals from mutating payment, wallet, voucher, or session lifecycle tables. Use a durable NAS-identity cursor to serialize session-ID reuse, and a durable disconnect outbox whose delivery acknowledgement is not disconnect confirmation. Never drop populated accounting evidence or generation cursors during rollback. When adding composite foreign keys, create and verify the referenced composite unique index first; keep production-engine DDL and lock claims pending until that engine is confirmed. Apply Laravel encryption to the per-router secret in the model/application layer before any secret write path is introduced.

## History
- 2026-11-13 (wifi-billing-saas/t6): initial
- 2026-11-13 (wifi-billing-saas/t10.1): added generation cursor and disconnect outbox rules.
- 2026-11-13 (wifi-billing-saas/t12): implemented additive schema with guarded re-entry and populated-data rollback refusal.
