# PHP 8.2 Locked Dependency Smoke Gates

Distinguish a Laravel process that starts from an already-installed vendor tree from a reproducible build against the current Composer lockfile.

## What Happened
In wifi-billing-saas task t19, PHP 8.2.12 served Laravel `/up` through the isolated test router, but `composer check-platform-reqs --lock` failed because `coding-libs/laravel-mfa v2.0.0` and `spatie/laravel-permission v8.3.2` require PHP `^8.3`. The existing t15 Vite helper also demonstrated how to build assets with an isolated `envDir` rather than default Vite environment loading.

## Takeaway
Report startup and lock-platform compatibility as separate evidence. For no-`.env` smoke checks, use an explicitly isolated environment path before Laravel kernel bootstrap and ensure Vite receives an isolated `envDir`; do not infer clean-install compatibility from a successful HTTP response.

## History
- 2026-11-14 (wifi-billing-saas/t19): initial
- 2026-11-14 (wifi-billing-saas/t19): after the PHP 8.2 lock remediation, `composer check-platform-reqs` and `composer install --no-scripts --no-plugins` passed on PHP 8.2.12; verify the current lock rather than carrying forward a pre-remediation failure.
