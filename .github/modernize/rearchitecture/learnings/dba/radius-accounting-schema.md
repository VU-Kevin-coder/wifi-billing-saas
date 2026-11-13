# RADIUS Accounting Schema

Model RADIUS as router-scoped protocol evidence linked to existing Laravel sessions, not as a second entitlement or payment authority.

## What Happened
For wifi-billing-saas t6, current models already provide tenant-owned routers, vouchers, transactions, and sessions, while no RADIUS accounting writer exists. The schema proposal keeps the legacy router mode as the default, adds a typed link for new sale vouchers without backfilling legacy metadata, and separates a deduplicated NAS-session projection from append-only accounting events. Encrypted router secrets cannot be consumed by FreeRADIUS through ordinary SQL without a trusted decryption boundary.

## Takeaway
For future RADIUS adoption, derive tenant identity from the authenticated router; retain the existing voucher/session as entitlement truth; use server-computed event fingerprints and preserve raw normalized Start/Interim/Stop evidence; prevent RADIUS principals from mutating payment, wallet, voucher, or session lifecycle tables. Never drop populated accounting evidence during rollback.

## History
- 2026-11-13 (wifi-billing-saas/t6): initial
