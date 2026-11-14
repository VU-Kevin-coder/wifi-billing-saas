# PHP 8.2 Composer Lock Compatibility

Resolve Composer PHP platform mismatches from package release metadata before changing constraints, and verify the resulting lock without running scripts or plugins.

## What Happened

In wifi-billing-saas task t22.1, the newest published MFA 1.x release (v1.1.6) supports PHP 8.2 and Laravel 12; its v2.0.0 transition changed Composer requirements but no package source. Spatie permission v6.25.0 supports PHP ^8.0 and Illuminate 12, while its v7/v8 lines require PHP 8.3. The application uses the core role/permission APIs retained in v6.25.0. Composer update with `--no-install --no-scripts --no-plugins` produced a PHP 8.2-compatible lock. Composer 2.10 also rewrote empty lock metadata; restoring that unrelated metadata kept the change focused.

## Takeaway

Do not assume the previous major release is compatible: verify exact published package requirements and app API usage. Then update only the affected packages with scripts/plugins/install disabled, validate the lock against the active target runtime, inspect the diff for lock-format churn, and leave runtime-regression risk explicit when app tests cannot safely run.

## History

- 2026-11-13 (wifi-billing-saas/t22.1): initial
