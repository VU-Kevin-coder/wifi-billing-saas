# Production readiness plan: open decisions and evidence

**Status:** Pending product, finance, engineering, and operations decisions.
**Source:** [Production implementation plan](../PRODUCTION-IMPLEMENTATION-PLAN.md)

No values below are assumed or inferred from development configuration. The
owner must record the decision and supporting evidence before the dependent
implementation or launch gate is approved.

| ID | Open question | Decision owner(s) | Needed before | Evidence to record |
|---|---|---|---|---|
| D-01 | What exactly is in the first commercial launch: operator billing and voucher sales only, or also withdrawals, SMS, PPPoE, RADIUS, VPN/WireGuard, and all other surfaced modules? | Product + Engineering | Scope lock, T007 | Approved capability list; excluded capabilities disabled or explicitly labeled |
| D-02 | Which subscription plans, prices, currency, taxes/fees, trial duration, grace period, access restriction, renewal, cancellation, refund, and customer notice rules apply? | Product + Finance + Legal adviser as needed | T007–T015 | Approved terms and state-transition table |
| D-03 | Is renewal manual or recurring? If recurring, what provider token/mandate and consent model is supported, and how are cancellation, retry, and disputes handled? | Product + Finance + Provider | T007, before any recurring-charge implementation | Provider capability evidence and approved consent/cancellation language |
| D-04 | Which provider and merchant account are the launch default? Can one merchant account safely receive voucher and subscription funds, or is a separate merchant account needed? | Finance + Provider + Product | T016–T020 | Provider/account approval, environment, settlement and fee evidence; secrets remain in secret management |
| D-05 | Which provider sandbox can be used, what callbacks and status APIs are available, and what authenticated status is authoritative after timeouts/reversals? | Engineering + Provider | T016–T019 | Sanitized sandbox test evidence and verified contract |
| D-06 | Is tenant access denied at subscription expiry immediately or after a grace period? Which capabilities remain available for payment/support during delinquency? | Product + Support + Engineering | T009–T011 | Approved action-by-lifecycle-state matrix |
| D-07 | Is tenancy served via wildcard subdomains or path routing? Which domain, DNS, TLS, sender domain, and certificate ownership model is available? | Operations + Product | T032–T033 | DNS/TLS configuration ownership and staging probe results |
| D-08 | What is the actual Render plan/topology, PostgreSQL version/backup retention, Redis availability, proxy IP behavior, and scheduler restart/alert owner? | Operations | T024, T027, T030, T034 | Provider dashboard/config evidence without secret values; restore and readiness results |
| D-09 | What are the measured latency/availability baselines, expected load, acceptable downtime, RPO/RTO, and growth assumptions? | Product + Operations + Engineering | T028, T034, T041 | Baseline measurements, business-approved targets, and load/recovery results |
| D-10 | Who has authority and duty to review unresolved invoices, refunds, disputes, settlement mismatches, and paid-but-not-provisioned events? | Finance + Support + Security | T012–T023, T036 | Named role/coverage matrix, escalation path, and access review |
| D-11 | Is RADIUS included in the first release? If yes, what exact access selector, target router models/RouterOS versions, network topology, and rollback operator are approved? | Product + Architecture + Operations + Security | T037–T040 | Contract decision, isolated lab authorization, hardware matrix and rollback evidence |
| D-12 | What privacy/data retention and deletion obligations apply to tenant contacts, customer payment identifiers, audit records, logs, backups, and support evidence? | Product + Privacy/Legal + Security | T035–T036 | Approved retention/access schedule and deletion procedure |
| D-13 | Who authorizes go-live, live checkout enablement, production migrations, incident response, and rollback? | Product + Engineering + Security + Finance + Operations | T041–T044 | Named approvers, contact path, and signed release checklist |

## Evidence rules

- Never place `.env` values, provider secrets, full phone numbers, payment
  tokens, or customer data in this file or attached test evidence.
- A mocked test proves application handling of the supplied fixture only; it
  does not prove a provider contract, settlement, live DNS, SMTP delivery,
  production proxy behavior, or physical router behavior.
- A SQLite test does not prove PostgreSQL locking/concurrency.
- A database backup job existing does not prove that a restore works.
- An application readiness endpoint does not prove independent external
  monitoring or alert delivery.
- A router API mock does not prove RouterOS acceptance.
