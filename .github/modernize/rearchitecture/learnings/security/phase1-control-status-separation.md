# Phase 1 Control Status Separation

Distinguish confirmed code vulnerabilities from provider-contract uncertainty and deployment-readiness prerequisites when auditing partially operationalized security boundaries.

## What Happened

For wifi-billing-saas task t18.1, the repository contained code-level fixes for one-time router-secret delivery and a fail-closed RADIUS policy source guard, with focused isolated tests. Those tests did not verify production TLS/private reachability/shared-cache configuration. Airtel status checks bind to the stored provider transaction ID and local purchase terms, but omitted amount/currency behavior remained an external contract question. The user-facing selector wording (`access_mode`) also differed from repository artifacts (`access_backend`); no equivalence was inferred.

## Takeaway

Report code findings, provider assumptions, and operational prerequisites in separate categories. Treat passing isolated tests and clean CVE reports as scoped evidence only. Resolve selector-name drift with the coordinator/architect before treating a gate requirement as satisfied.

## History
- 2026-11-14 (wifi-billing-saas/t18.1): initial
