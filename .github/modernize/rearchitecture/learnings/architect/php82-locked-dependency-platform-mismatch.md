# PHP 8.2 Locked Dependency Platform Mismatch

A Composer manifest can advertise PHP 8.2 while its lockfile resolves direct packages that require PHP 8.3, making clean install compatibility different from tests against an existing `vendor` tree.

## What Happened
In wifi-billing-saas task t17, `composer.json` required PHP `^8.2`, while `composer.lock` pinned `coding-libs/laravel-mfa` v2.0.0 and `spatie/laravel-permission` 8.3.2, both requiring PHP `^8.3`; the observed CLI was PHP 8.2.12. Existing test reports do not remove this platform mismatch.

## Takeaway
For a fixed PHP target, inspect the package platform requirements in the lockfile as well as `composer.json`. Treat a locked platform conflict as a release blocker; retain the approved runtime target, select compatible package versions, and do not mask the conflict with `--ignore-platform-reqs`.

## History
- 2026-11-14 (wifi-billing-saas/t17): initial
