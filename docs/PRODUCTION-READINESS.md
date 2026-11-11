# Production readiness

Status date: 2026-11-11

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
| D4 — Full test suite/CI | In progress | Local `php artisan test` passed on PHP 8.2/SQLite: 452 tests, 2,278 assertions. SQLite, MySQL 8, and PostgreSQL 16 full-suite jobs use PHP 8.3; Pint and Composer/npm audits are blocking, and `CI passed` includes these jobs. CI has not run for this change; the MySQL/PostgreSQL jobs and GitHub branch protection requirement remain pending. |
| D4 — npm audit network failure | Done locally; CI pending | `npm audit --omit=dev --audit-level=high` passed locally with 0 vulnerabilities and is blocking in GitHub Actions. If registry DNS/audit fails there, CI fails visibly rather than passing with an ignored result. |

## Batches A–C

The current branch contains the earlier tenant-scoped router records, site-agent command channel, voucher provisioning/expiry flow, and one-time WireGuard
configuration. Their individual batch-to-finding mapping and historical test
results are not captured in this repository; this document does not claim that
those features have passed on physical hardware.

## Validation evidence

| Check | Result as of 2026-11-11 |
|---|---|
| Full local application suite (`php artisan test`, PHP 8.2/SQLite) | Passed: 452 tests, 2,278 assertions |
| Full SQLite / MySQL / PostgreSQL CI suite | SQLite passed locally; MySQL/PostgreSQL and GitHub Actions run pending |
| Pint (`php vendor/bin/pint --test`) | Passed |
| `composer audit` | Passed: no security vulnerability advisories found |
| `npm audit --omit=dev --audit-level=high` | Passed: 0 vulnerabilities |
| CHR end-to-end acceptance | Not run |
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
