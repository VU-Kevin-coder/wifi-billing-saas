# Traceability Checkpoint Revalidation

The t7 plan-to-task checkpoint stores item/task mappings under `phases`, so revalidation should compare both those phase rows and the separate `upstream_trace` against inline `[Plan:X.Y]` task annotations.

## What Happened
For wifi-billing-saas t8.1, the plan's T005 annotation already referenced items 1.2, 1.3, and 1.4, but the t7 checkpoint omitted T005 from 1.2 and 1.3. The first validator draft assumed a `plan_items` block; inspecting the checkpoint schema and comparing its `phases` and `upstream_trace` sections produced the correct validation. PowerShell interpolated strings also require `${name}` or `$($name)` when a variable is followed by a colon.

## Takeaway
Before automating a checkpoint comparison, inspect its actual section names and validate inline task references against both the plan-item mapping and upstream task trace. Avoid assuming that a review checkpoint's representation matches the source checkpoint.

## History
- 2026-11-13 (wifi-billing-saas/t8.1): initial
