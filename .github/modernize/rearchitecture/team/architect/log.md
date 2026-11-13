## [t1] Defined additive access/payment architecture
- Codebase/domain discoveries: `VoucherActivationService` enforces the global production site-agent requirement; successful `voucher_sale` fulfillment is already transaction-locked and combines wallet ledger credit, voucher creation, and activation. RADIUS is documented as deferred, while WireGuard is management-only.
- Wrong assumptions and corrections: the repository does not contain `context.md`, `decisions.md`, or architect `inbox.md`/prior log; no fanout artifact or separate `plan.md` was present in the workspace. Used the approved clarification answers and project profile as the available source of scope.
- Debugging dead-ends and what actually worked: full `PaymentService.php` exceeded the file reader limit; targeted `rg` plus bounded `view_range` reads recovered the verification and fulfillment contracts.
- Techniques/patterns worth reusing: keep a new access path explicitly router-selected and default legacy routers to site-agent; derive RADIUS tenant identity from the authenticated router, not voucher username; distinguish payment completion from access activation.
- Learnings consumed: [none]

## [t10] Recorded adapter and accounting policy decisions
- Codebase/domain discoveries: the t6 proposal did not include durable disconnect-command state or permit NAS session-ID reuse after Stop; both are required to make retries and disconnect truthfulness implementable. No platform accounting-retention rule was found in the repository/docs search.
- Wrong assumptions and corrections: a protected private policy API needs two distinct identity checks—FreeRADIUS service authentication and the router identity authenticated at the NAS boundary; neither is a tenant claim supplied by a voucher/user packet.
- Debugging dead-ends and what actually worked: inspected the clarification, t1/t2/t6/t9 artifacts and searched docs/rearchitecture for retention; repository evidence supports no invented retention duration.
- Techniques/patterns worth reusing: keep adapter calls outside DB transactions; use idempotent normalized accounting events and generation-scoped NAS session identity; retain pending/disconnecting status until authoritative accounting confirmation.
- Learnings consumed: [architect/router-scoped-radius-routing]
