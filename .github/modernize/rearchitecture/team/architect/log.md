## [t1] Defined additive access/payment architecture
- Codebase/domain discoveries: `VoucherActivationService` enforces the global production site-agent requirement; successful `voucher_sale` fulfillment is already transaction-locked and combines wallet ledger credit, voucher creation, and activation. RADIUS is documented as deferred, while WireGuard is management-only.
- Wrong assumptions and corrections: the repository does not contain `context.md`, `decisions.md`, or architect `inbox.md`/prior log; no fanout artifact or separate `plan.md` was present in the workspace. Used the approved clarification answers and project profile as the available source of scope.
- Debugging dead-ends and what actually worked: full `PaymentService.php` exceeded the file reader limit; targeted `rg` plus bounded `view_range` reads recovered the verification and fulfillment contracts.
- Techniques/patterns worth reusing: keep a new access path explicitly router-selected and default legacy routers to site-agent; derive RADIUS tenant identity from the authenticated router, not voucher username; distinguish payment completion from access activation.
- Learnings consumed: [none]
