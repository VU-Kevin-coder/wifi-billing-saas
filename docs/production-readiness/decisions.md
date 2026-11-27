# Production readiness decisions log

This is the decision record for the
[production implementation plan](../PRODUCTION-IMPLEMENTATION-PLAN.md).
Entries are intentionally **pending** until an accountable owner approves
them. Do not treat blank fields or existing development configuration as
production decisions.

| ID | Decision | Status | Approver(s) | Date | Evidence / link |
|---|---|---|---|---|---|
| D-01 | Initial launch capability scope | Pending |  |  |  |
| D-02 | Subscription plans, price/currency, trial, grace, expiry, cancellation and refunds | Pending |  |  |  |
| D-03 | Manual vs recurring subscription renewal | Pending |  |  |  |
| D-04 | Initial payment provider and merchant account | Pending |  |  |  |
| D-05 | Authoritative provider contract and sandbox acceptance | Pending |  |  |  |
| D-06 | Expired/past-due tenant capability matrix | Pending |  |  |  |
| D-07 | Tenant domain, DNS, TLS and sender-domain approach | Pending |  |  |  |
| D-08 | Deployment/database/cache/backup/proxy topology | Pending |  |  |  |
| D-09 | Availability, load, RPO and RTO targets | Pending |  |  |  |
| D-10 | Billing, settlement and customer-support ownership | Pending |  |  |  |
| D-11 | RADIUS launch scope and supported hardware | Pending |  |  |  |
| D-12 | Privacy, retention and deletion policy | Pending |  |  |  |
| D-13 | Go-live, live-checkout and rollback approval authority | Pending |  |  |  |

Store sanitized evidence in reviewed documentation or the controlled release
record. Keep secrets in the approved secret manager, never in this file.
