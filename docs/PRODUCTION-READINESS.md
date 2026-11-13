# Production readiness

Status date: 2026-11-13

This document records repository changes and validation evidence. A passing
automated test is not a substitute for testing RouterOS firmware or verifying
the production proxy chain.

## Findings and status

| Finding | Status | Change / evidence |
|---|---|---|
| D1 — Misleading router health | In progress | Added site-agent telemetry commands for uptime, CPU, memory, and active hotspot users. Router detail status distinguishes recent telemetry from last-known state and displays separate agent/telemetry timestamps. Successful profile sync alone no longer marks a router online. Automated coverage added; physical-agent/router run pending. |
| D2 — Captive-portal credentials | In progress | Generated profile removes `http-pap` and keeps `http-chap`; login URLs no longer carry voucher codes, which remain on the application success page for manual entry into the router login form. RouterOS HTTPS captive login still requires a trusted router certificate and is not configured by the script. |
| D2 — Router API account | In progress | Removed `test` and `password` from the generated account policy and retained API/read/write. RouterOS group policies grant write at configuration scope; this script cannot restrict it to `/ip hotspot` alone. Do not describe this account as subtree-scoped. |
| D2 — Trusted proxies | In progress | Render now requires explicit `TRUSTED_PROXIES`; production preflight rejects empty and `*`. The actual Render proxy addresses and whether its edge overwrites all forwarded headers have not been verified in a deployed service. |
| D2 — Scheduler mail | Done in configuration | Scheduler worker now receives the same SMTP settings as the web service. Delivery must still be verified with the production mail provider. |
| D2 — CHR exposure/version | Done in repository | CHR ports bind to `127.0.0.1`; image pinned to `evilfreelancer/docker-routeros:7.24.6`. Container startup/RouterOS behavior not verified in this run. |
| D3 — Hardware matrix and acceptance plan | Done as documentation | [HARDWARE-MATRIX.md](./HARDWARE-MATRIX.md) records target models, RouterOS version target, untested status, and acceptance steps. No physical router result has been supplied or recorded. |
| D4 — Full test suite/CI | In progress | Local `php artisan test` passed on PHP 8.2/SQLite: 490 tests, 2,496 assertions. SQLite, MySQL 8, and PostgreSQL 16 full-suite jobs use PHP 8.3; Pint and Composer/npm audits are blocking, and `CI passed` includes these jobs. CI has not run for this change; the MySQL/PostgreSQL jobs and GitHub branch protection requirement remain pending. |
| D4 — npm audit network failure | Done locally; CI pending | `npm audit --omit=dev --audit-level=high` passed locally with 0 vulnerabilities and is blocking in GitHub Actions. If registry DNS/audit fails there, CI fails visibly rather than passing with an ignored result. |
| Notifications | Done in application; production schema pending | Added persisted role-targeted notifications for new tenant/user signups, subscription and account/KYC changes, router failures/recovery/configuration, package/site creation and edits, and voucher batches. Bell uses the authenticated API and polls unread counts. Docker database connectivity blocked applying its migrations; see database status below. |
| Registration email verification and welcome | Done in application; delivery pending | Registration sends a short-lived, rate-limited six-digit email code, verifies it before creating the tenant/user, and sends a welcome email after successful signup. Actual SMTP delivery has not been tested against a configured provider. |
| Tenant integration secret storage | Done in application; migration pending | Tenant SMS API keys and WhatsApp API/webhook secrets are encrypted at rest with the application key. Existing plaintext values are handled by a data migration and encrypted on the next settings save. Do not rotate `APP_KEY` without a planned re-encryption/rotation procedure. |
| Currency configuration | Done for package creation and display; provider limits remain | New packages inherit the platform-configured currency; package, voucher, customer, dashboard, wallet, and billing displays use configured/package currency. Pesapal submits package currency. MTN/Airtel collection remains Uganda/UGX-only and rejects non-UGX packages rather than charging the wrong amount. |
| Application and tenant URL defaults | Done in code; production DNS pending | Tenant URLs now use the scheme, host, and non-standard port from `APP_URL`; `TENANT_BASE_DOMAIN` is an explicit optional override. Router onboarding uses the actual authenticated router-specific script download and configurable RouterOS defaults. `hotspot.local` remains a configurable RouterOS captive-portal DNS name, not the SaaS URL. |
| System status and dependency alerts | Done in application; deployment monitoring pending | The static health claim is replaced by dependency status from database, cache, scheduler heartbeat, and queue configuration. Health transitions email active platform admins. `/health/ready` returns 503 when degraded; configure an external monitor to poll it so a stopped process is detected independently. Non-sync queue workers remain unverified without a worker heartbeat. |
| Expired sessions awaiting router enforcement | Done in application | Expired sessions with pending router actions are marked `disconnecting` and shown separately from active sessions; retries preserve this state until router confirmation. |
| Duplicate pending purchases and callbacks | Done in application; provider retry behavior tested | Pending payment intents are deduplicated under a tenant/package/normalized-phone fingerprint and cache lock; a new attempt is allowed after failure/completion. Pesapal uses customer email or configured `MAIL_FROM_ADDRESS`. Provider callback retry tests verify idempotent fulfillment. |
| Database recovery / migrations | Local development stack healthy; production recovery remains unverified | `docker compose ps` reports MySQL healthy and `docker compose exec -T app php artisan migrate:status` reports all migrations as run, including the latest revocation, blocked-device, and purchase-intent migrations. No production backup/restore rehearsal was performed. |

## Batches A–C

The current branch contains the earlier tenant-scoped router records, site-agent command channel, voucher provisioning/expiry flow, and one-time WireGuard
configuration. Their individual batch-to-finding mapping and historical test
results are not captured in this repository; this document does not claim that
those features have passed on physical hardware.

## Validation evidence

| Check | Result as of 2026-11-13 |
|---|---|
| Full local application suite (`php artisan test`, PHP 8.2/SQLite) | Passed: 490 tests, 2,496 assertions |
| Full SQLite / MySQL / PostgreSQL CI suite | SQLite passed locally; MySQL/PostgreSQL and GitHub Actions run pending |
| Pint (`php vendor/bin/pint --test`) and `git diff --check` | Passed |
| `composer audit` | Passed: no security vulnerability advisories found |
| `npm audit --omit=dev --audit-level=high` | Passed: 0 vulnerabilities |
| Changed-area suite (tenant URLs, router provisioning, expiry, controls, health, and payments) | Passed: 63 tests, 339 assertions |
| Provisioning watchdog and site-agent retry regression suite | Passed: 31 tests, 198 assertions |
| Frontend production build (`npm run build`) | Passed |
| Tenant secret encryption / legacy compatibility | Passed in targeted SQLite test |
| Registration code and welcome email | Passed with Laravel notification fake; SMTP delivery not verified |
| Local application liveness (`curl.exe ... http://127.0.0.1:8000/up`) | Passed: HTTP 200 |
| Local MySQL migration status (`docker compose exec -T app php artisan migrate:status`) | Passed: all migrations reported as run |
| Docker Compose services | App, web, Redis, MySQL (healthy), and CHR containers reported running |
| CHR script import / end-to-end acceptance | Not run; no RouterOS configuration was changed |
| Physical router acceptance | Not run |
| Render proxy chain / forwarded-header overwrite | Not verified against a deployed service |

## Deferred work

| Item | Status | Reason / target |
|---|---|---|
| VPN UI polish | Deferred | Not required for the core router control path; revisit by 2026-12-11. |
| RADIUS | Deferred | Not implemented in this deployment; revisit by 2026-12-11 after captive-portal acceptance. |
| WireGuard key rotation UX | Deferred | Current recovery requires support intervention; define and test rotation before wider production rollout, target 2026-12-11. |
| RouterOS subtree-only write permissions | Deferred with security limitation | Native RouterOS user-group policy is global; a separate constrained command boundary is required before claiming least-privilege hotspot-only write. Reassess by 2026-11-18. |
| Router HTTPS captive portal | Deferred with configuration prerequisite | Requires a valid trusted certificate on each router and a customer-reachable hostname. Keep CHAP-only login until certificate provisioning and validation are implemented; reassess by 2026-11-18. |
| Render proxy verification | Deferred with deployment prerequisite | Configure exact proxy CIDRs and confirm forwarded-header overwrite behavior in the live Render service before production traffic; target 2026-11-18. |
| Real email delivery | Deferred with deployment prerequisite | Configure and verify SMTP delivery for registration codes and welcome messages before accepting public signups; target 2026-11-18. |
| Production database backup / restore rehearsal | Pending with deployment prerequisite | The local Docker MySQL service is healthy and its migrations are current. Before production testing, confirm the production target, take and verify a backup, inspect its schema/data, then apply approved migrations; do not run `migrate:fresh` against persistent data. |
| Physical router acceptance | Deferred with hardware prerequisite | Run and record the acceptance procedure in [HARDWARE-MATRIX.md](./HARDWARE-MATRIX.md) on an isolated physical router; no physical test is claimed. Target 2026-11-18. |
