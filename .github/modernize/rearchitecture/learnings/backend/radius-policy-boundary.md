# RADIUS Policy Boundary

Keep the RADIUS adapter as a narrowly authenticated protocol client of Laravel; Laravel remains authoritative for router tenancy, entitlement, accounting, and disconnect state.

## What Happened
For wifi-billing-saas task t13, implementation added signed private policy endpoints, router-derived tenancy, normalized generation-aware accounting, and a durable disconnect outbox. Review found that a delivery acknowledgement cannot finish revocation: when no Stop accounting arrives, the outbox must schedule another attempt while the session remains open. The retry now reuses the same command identity and ceases after terminal Stop evidence.

## Takeaway
Bind service requests to method, path, body digest, timestamp, and one-time nonce; keep router secrets independent and encrypted; never treat an Access-Accept or disconnect delivery acknowledgement as confirmed access/disconnection. Require an authenticated and explicitly authorized operator workflow before exposing a generated router secret. Keep per-router activation separate from the existing global site-agent requirement.

## History
- 2026-11-13 (wifi-billing-saas/t13): initial
