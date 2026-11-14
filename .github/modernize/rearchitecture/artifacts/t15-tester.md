# t15 — Pinned Playwright Portal Journey

## Summary

Added pinned Playwright browser validation for the existing customer payment-status page. The journey renders the Blade portal, polls the existing payment-status endpoint, confirms that the bearer token is carried only in `X-Payment-Token`, and verifies an unconfirmed sale remains pending without exposing a voucher or claiming active Wi-Fi.

The harness builds the existing Vite assets with an empty isolated environment directory, boots Laravel using only explicit test environment variables, and runs migrations against a dedicated disposable SQLite file under `storage/framework/testing/`. The isolated database, fixture, session files, and temporary environment directory are removed after the run. No `.env` file was read or processed.

## Key Deliverables

- `package.json` / `package-lock.json` — exact `@playwright/test` pin `1.65.1`; `npm run e2e` and `npm test` entry points.
- `composer.json` — `composer test` aggregates the existing `php artisan test --do-not-cache-result` command and Playwright E2E.
- `playwright.config.ts` — Chromium-compatible headless browser setup, managed local PHP server, and isolated test results output.
- `tests/e2e/phase1-payment-status.spec.ts` — pending-payment portal journey and token-transport checks.
- `tests/e2e/prepare-database.php`, `router.php`, `global-setup.ts`, `build-assets.mjs`, `environment.ts` — isolated SQLite fixture, Laravel bootstrap without loading project environment files, and local server/assets setup.

No application source, Laravel routes, API contracts, site-agent paths, router configuration, or infrastructure files were changed. No production database, deployment, migrations against a configured/default database, or live payment provider was used.

## Test Results

- `npm run e2e`
  - Passed: 1
  - Failed: 0
  - Skipped: 0
  - Exit code: 0
  - Coverage: Blade success page renders; CSS/JS assets load; payment-status request returns HTTP 202 with `pending`; token is in the request header and absent from the URL; pending state does not show a voucher or active access.
- `npx playwright install chromium` — passed; Chromium 159.0.8154.6 installed for pinned Playwright 1.65.1.
- `php -l tests/e2e/prepare-database.php` and `php -l tests/e2e/router.php` — passed.
- `composer validate --no-check-publish` — passed.
- Exact package pin verified in `package.json` and `package-lock.json`.
- `npm install --save-dev --save-exact @playwright/test@1.65.1` — passed; npm reported 0 vulnerabilities.
- Full PHPUnit/integration suite and `composer test` were not run in this scoped browser-setup task. No PHPUnit/Artisan command was run, avoiding any implicit environment-file loading; the full matrix remains for t20.

## Validation Verdict

```text
environment:
  docker: AVAILABLE — Docker 29.9.3; `docker info` succeeded; Docker was not used
  node: AVAILABLE — v24.15.0
  playwright: AVAILABLE — @playwright/test 1.65.1; Chromium installation and browser run passed
  infra-tier: NOT REQUIRED — this task uses only a disposable local SQLite database; no external service was started
  browser-tier: PRIMARY(Playwright) — the browser prerequisite was available and used
startup: PASS — local PHP 8.2.12 server started; the portal page and built assets rendered during the E2E run
integration: UNVERIFIED — exit_code: n/a, passed: 0, failed: 0, skipped: 0; full PHP integration suite is outside t15
e2e: PASS — exit_code: 0, passed: 1, failed: 0; pending payment status and header-only token transport; no voucher/active-access claim
overall: PASS — t15 deliverables and scoped browser journey passed; this is not the Phase 1 release-gate verdict
```

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/artifacts/t14-tester.md` — prior isolated regression results and unverified browser-E2E gap.
- `.github/modernize/rearchitecture/artifacts/t7-teamlead-plan.md` — T012 file targets, browser-tier strategy, disposable SQLite requirement, and validation boundaries.
- `.github/modernize/rearchitecture/artifacts/t1-architect.md` — frozen payment/API compatibility and no-change site-agent path.
- `.github/modernize/rearchitecture/artifacts/t3-pm.md` — customer status, token confidentiality, and existing contract requirements.
- `.github/modernize/rearchitecture/clarification.md` — Laravel 12/PHP 8.2 and UI preservation, local-only test DB, and production/router/infrastructure exclusions.

## Evidence Mapping

- `t7-teamlead-plan.md#Testing Strategy/Browser tier` and T012 → pinned Playwright tooling, managed local server, disposable SQLite fixture, and `phase1-payment-status.spec.ts`.
- `clarification.md#Backend/API contract preservation` and `#Generic/Out of scope` → unchanged Laravel/API and site-agent behavior; no production DB, router, deployment, or infrastructure activity.
- `t1-architect.md#Frozen compatibility surface` → no application routes or payment contracts changed.
- `t3-pm.md#REQ-009` → the browser observes `X-Payment-Token` on the status request and verifies the token is not in the URL.
- `t3-pm.md#REQ-010` → a 202 pending result remains pending and does not display a voucher or claim active Wi-Fi.
- `t14-tester.md#Validation Verdict` → the earlier E2E-unverified gap is now covered by the t15 browser test; t14's PHPUnit evidence was not rerun here.

## Remaining Gaps

- This is one browser journey, not the complete Phase 1 runtime/regression matrix. Integration/full-suite, payment-provider verification, successful fulfillment, tenant-mismatch, and agent-confirmation journeys remain for their assigned validation tasks.
- The production database engine remains unknown; this task intentionally uses only its disposable SQLite fixture and makes no engine-parity claim.
- `composer test` was added but not executed because it includes the PHPUnit Artisan command; only the browser command and metadata validation were executed for t15.
