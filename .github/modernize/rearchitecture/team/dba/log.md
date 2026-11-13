## [t6] Additive router/accounting schema proposal
- Codebase/domain discoveries: `sessions_log` already models access and has usage counters; online sale-to-voucher linkage exists only in transaction metadata; no RADIUS accounting writer was found.
- Wrong assumptions and corrections: the prior t5 test baseline revision predates payment-safety source changes; compared source paths and reran the full suite/build on current HEAD.
- Debugging dead-ends and what actually worked: context.md, decisions.md, and an existing DBA log were absent; upstream artifacts, board, current migrations/models, and current-checkout tests supplied sufficient context.
- Techniques/patterns worth reusing: keep `site_agent` as the explicit default; use composite tenant/router keys and append-only protocol evidence; avoid historical backfill and retain populated audit data on rollback.
- Learnings consumed: [(none)]
