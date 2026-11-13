# t7 — Phase 1 Implementation Plan and Test Strategy

## Summary

The plan sequences additive Laravel domain/schema work, a per-router access-selection seam that preserves the site-agent default, replay-safe accounting reconciliation, and payment-fulfillment linkage behind the existing verified-payment flow. It explicitly excludes all Phase 2 infrastructure, FreeRADIUS deployment/configuration, CHR/router operations, production deployment, and default-database migrations.

The 12 PM requirements map to 10 plan items and 13 implementation tasks. The Phase 1 release gate remains pending execution; this planning task does not claim implementation or gate completion.

## Deliverables

- [t7-teamlead-plan.md](./t7-teamlead-plan.md) — technical plan, requirement mapping, runnable task breakdown, test strategy, and release-gate criteria.
- [checkpoints/t7-spec-to-plan.yaml](./checkpoints/t7-spec-to-plan.yaml) — PM requirement-to-plan traceability.
- [checkpoints/t7-plan-to-tasks.yaml](./checkpoints/t7-plan-to-tasks.yaml) — plan-item-to-task traceability.

## Upstream Artifacts Consumed

- `.github/modernize/rearchitecture/artifacts/t1-architect.md` — additive per-router architecture, payment boundary, tenant identity, compatibility surface, and phase boundary.
- `.github/modernize/rearchitecture/artifacts/t2-backend.md` — current Laravel domain and source/test extension points.
- `.github/modernize/rearchitecture/artifacts/t3-pm.md` — REQ-001 through REQ-012, scenarios, and Phase 1 acceptance criteria.
- `.github/modernize/rearchitecture/artifacts/t4.5-security.md` — payment re-review, residual LOW advisory, Airtel contract uncertainty, and security evidence.
- `.github/modernize/rearchitecture/artifacts/t5-tester.md` — earlier baseline, canonical test/build commands, and isolated SQLite posture.
- `.github/modernize/rearchitecture/artifacts/t6-dba.md` — additive schema proposal, idempotency/tenant constraints, rollback policy, and unresolved engine/adapter decisions.
- `.github/modernize/rearchitecture/clarification.md` — approved stack, immutable compatibility constraints, and no-deployment/no-router/no-default-DB/no-Phase-2 boundary.
- `.github/modernize/rearchitecture/clarification-answers.md` — explicit user decisions used to scope the plan.
- `.github/modernize/rearchitecture/artifacts/project-profile.yaml` — single Laravel modular-monolith scope; no topology groups are defined.
- `.github/modernize/rearchitecture/board.md` — read-only task and phase status.

## Evidence Mapping

- `t3-pm.md#Requirements` (REQ-001–REQ-012) → plan requirement mapping and `t7-spec-to-plan.yaml`.
- `t1-architect.md#Additive Phase 1 architecture` and `#Frozen compatibility surface` → plan items 1.1–2.2 and compatibility fence.
- `t2-backend.md#Current access fulfillment path` and `#Payment and fulfillment path` → source-file tasks 2.1–3.2.
- `t6-dba.md#Proposed additive schema` and `#Rollback and irreversible effects` → plan items 1.2–1.4 and isolated migration tests.
- `t4.5-security.md#Verdict`, `#Remaining risks / non-blocking findings`, and `#Test and scan results` → payment work is regression-first; LOW advisory and Airtel uncertainty remain explicit risks.
- `t5-tester.md#Test Results` and `#Build Results`, corroborated by t6's rerun → baseline and final regression-gate commands.
- `clarification.md#Backend` and `#Generic` → immutable application and rollout boundaries.

## Planning Status and Warnings

- No standalone `context.md`, `decisions.md`, or `constitution.md` was present; the approved clarification and upstream artifacts supply the operative invariants for this plan.
- The production database engine and the final FreeRADIUS/application adapter boundary remain unresolved in t6; task T001 makes those decisions blocking prerequisites rather than guessing.
- Current tool probe: Docker CLI exists but `docker info` fails because `dockerDesktopLinuxEngine` is unavailable; Node.js v24.15.0 is available; Playwright is not installed (`npx --no-install playwright --version` reports the package is missing). These facts are recorded in the test strategy and must be rechecked by the tester.
- The current source baseline is the t6 run (540 tests pass, Vite build passes) at `29aad52...`. Current HEAD `c1fd3bd...` adds planning metadata only; working-tree changes are confined to `.github/modernize/rearchitecture/` and no application source paths are dirty.
