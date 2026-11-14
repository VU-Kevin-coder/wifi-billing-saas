# t19 — Independent Full Build and Startup Smoke Test

## Verdict

**PASS — all declared build surfaces and the isolated Laravel startup probe passed.** The repository has separate Composer and Vite build surfaces rather than one root command combining them; both were verified without executing application scripts or reading environment files. No first-party application source, dependency manifest/lockfile, configuration, migration, database, infrastructure, deployment, or router was edited for this task. Composer updated the local `vendor` tree to the current lockfile as part of the build.

## Build and Startup Evidence

- Runtime: PHP 8.2.12; Composer 2.10.2; Node.js v24.15.0; npm 11.12.1. Required Laravel 12 / PHP 8.2 target preserved.
- Composer locked platform: `composer check-platform-reqs --lock --no-interaction --no-plugins` — **PASS**, exit code 0; all locked requirements including PHP 8.2.12 passed.
- Locked Composer install: `composer install --no-scripts --no-plugins --no-interaction --no-progress` — **PASS**, exit code 0. Used an isolated temporary `COMPOSER_HOME` and cache. Composer installed the lockfile versions (Laravel 12.72.0, laravel-mfa 1.1.6, spatie/laravel-permission 6.25.0), generated optimized autoload files, and removed the no-longer-locked package. Composer scripts and plugins were disabled; no plugin failures occurred or were applicable.
- Production frontend assets: `node tests/e2e/build-assets.mjs` with `PLAYWRIGHT_ENV_PATH` set to a fresh empty temporary directory — **PASS**, exit code 0; Vite 6.4.5 built all four configured CSS/JS entrypoints. Build output reported 33.80 seconds; helper wall time 41.32 seconds. The helper supplied an isolated Vite `envDir`.
- PHP syntax: `php -l` on all PHP files in `app`, `bootstrap`, `config`, `database`, `public`, `routes`, and `tests` — **338 passed, 0 failed**. `vendor` and `node_modules` were excluded.
- Startup: `php -S 127.0.0.1:<ephemeral-port> -t public tests/e2e/router.php` with explicit testing-only variables, an isolated environment directory, `APP_CONFIG_CACHE` under that directory, and SQLite `:memory:` — **PASS**. Laravel `/up` returned HTTP 200 in **1.71 seconds** from process start. The router readiness endpoint and a second `/up` probe also returned HTTP 200.
- The isolated router disables environment-file loading and rejects non-SQLite/URL database settings. No DB query or migration was performed. The temporary server and environment directory were stopped/removed after verification.
- Docker, external services, production settings, live providers, and routers were not used.

## Test Results

- Validation checks: **344 passed, 0 failed, 0 skipped** — 338 PHP syntax checks plus 6 build/platform/HTTP checks (locked-platform check, Composer install, Vite build, and three HTTP probes).
- Failures: none.
- Application PHPUnit and Playwright suites were not run in this smoke-test task; the assigned runtime-validation task owns that matrix.
- Full build surfaces: Composer locked install and optimized autoload generation passed; all Vite production assets passed. There is no single repository root command that combines these separate build surfaces.

## Safety and Scope

No `.env` file or other secret was inspected, printed, searched, copied, or processed. Composer was run with scripts/plugins disabled and an isolated Composer home. Laravel startup used only explicit synthetic test settings, no default/production DB connection, and no migration. No first-party source/config files were edited; Composer updated the ignored `vendor` tree and Vite generated ignored `public/build` output. The Phase 1 release gate remains with its assigned reviewer/security/runtime tasks and is not declared complete by this task.

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/artifacts/t12-dba.md` — SQLite-only test target and prohibition on production/unknown database migration.
- `.github/modernize/rearchitecture/artifacts/t13-backend.md` — unchanged site-agent default and router-scoped optional behavior.
- `.github/modernize/rearchitecture/artifacts/t14-tester.md` — regression-suite evidence and isolated database constraints.
- `.github/modernize/rearchitecture/artifacts/t15-tester.md` — isolated Vite helper and Laravel router/bootstrap approach.
- `.github/modernize/rearchitecture/artifacts/t16-backend.md` — prior dependency platform mismatch to re-check after remediation.
- `.github/modernize/rearchitecture/artifacts/t22.1-backend.md` — PHP 8.2-compatible Composer lock remediation and safe install constraints.
- `.github/modernize/rearchitecture/artifacts/t22.2-backend.md` — isolated Laravel test harness and no-environment-file requirement.
- `.github/modernize/rearchitecture/artifacts/t22.3-backend.md` — private policy configuration remains an unverified deployment prerequisite; no infrastructure probe in this task.
- `.github/modernize/rearchitecture/artifacts/t22.4-backend.md` — post-remediation dependency/platform validation context.
- `.github/modernize/rearchitecture/clarification.md` — Laravel 12/PHP 8.2, contract/site-agent/tenant preservation, SQLite-only test DB, and no production/router/infrastructure changes.
- `.github/modernize/rearchitecture/board.md` — task ordering and Phase 1 stop condition (read-only).
- `.github/modernize/rearchitecture/artifacts/t19-architect.md` — prior smoke result predating t22.1; its Composer platform failure was re-evaluated against the remediated lock.

## Evidence Mapping

- `clarification.md#Backend` → PHP 8.2.12 locked-platform check and install pass; `#Generic/Out of scope` → isolated SQLite-only startup, no production DB/migration/deploy/router activity.
- `t22.1-backend.md#Result` and `#Validation` → the exact remediated lock resolves and installs on PHP 8.2.12 without ignored platform requirements; Composer install used `--no-scripts --no-plugins`.
- `t22.4-backend.md#Target and Lock Validation` → current Composer platform status rechecked during actual locked dependency installation.
- `t15-tester.md#Key Deliverables` → existing `build-assets.mjs` ran Vite with a fresh isolated `envDir`; `t15-tester.md#Test Results` → router-based local startup pattern exercised with explicit test settings.
- `t12-dba.md#Residual Risks / Handoff` and `t14-tester.md#Test Results` → SQLite `:memory:` only; this smoke run performs no migration and makes no production-engine claim.
- `t13-backend.md#Status` and `clarification.md#API contract preservation` → no application or route source was changed; the default site-agent path and existing contracts remain untouched.
- `t22.3-backend.md#Residual Risks` → production CIDR/private-network reachability remains outside this local build/startup evidence.

## Remaining Phase 1 Boundaries

- This result validates local build/install and startup only; it is not the final Phase 1 release-gate verdict.
- Full application/runtime regression is assigned to t20; the binary gate is assigned to t21.
- Production DB engine/migrations, deployment configuration and private-network reachability, operator prerequisites, and Phase 2 lab/router work remain outside this task and must not be inferred from local smoke success.

## Commands Executed

- `composer check-platform-reqs --lock --no-interaction --no-plugins` — exit code 0.
- `composer install --no-scripts --no-plugins --no-interaction --no-progress` — exit code 0; isolated temporary `COMPOSER_HOME` and cache.
- `node tests/e2e/build-assets.mjs` — exit code 0; `PLAYWRIGHT_ENV_PATH` pointed to a fresh empty temporary directory.
- PowerShell enumerated `*.php` under `app`, `bootstrap`, `config`, `database`, `public`, `routes`, and `tests`, ran `php -l <file>` for each, and recorded 338 successes / 0 failures.
- `php -S 127.0.0.1:<ephemeral-port> -t public tests/e2e/router.php` — `/__playwright/ready` and `/up` returned 200; `/up` was reprobed during the measured startup run and returned 200.

## Smoke Test Verdict
- install_command: `n/a (PHP/Laravel project; no JS/TS frozen install was run)`
- install_returncode: n/a
- build_command: `composer install --no-scripts --no-plugins --no-interaction --no-progress` + `node tests/e2e/build-assets.mjs` (separate declared backend/frontend build surfaces; isolated Vite env)
- returncode: 0
- covers_all_modules: yes
- startup_http_status: 200
- test_script_present: n/a
- test_returncode: n/a
