---
schema: clarification/v1
generated_at: "2026-11-13T18:01:14Z"
scope:
  - frontend
  - backend
  - generic
clarity_score: 1.00
rounds: 1
gaps: []
blocking_gaps: []
---

# Scenario Clarification

## Frontend

- **Target framework**: Laravel Blade on Laravel 12; retain the current UI.
- **Component library**: Tailwind CSS 3.1 and existing Blade components.
- **Screenshots**: No redesign is intended. Preserve the current layout and change only the status information required by the architecture.
- **Design system**: Preserve current Blade/Tailwind styles and tokens from `tailwind.config.js` and `resources/css/app.css`.
- **Accessibility**: WCAG 2.1 AA.
- **Browser targets**: Modern evergreen browsers (Chrome, Firefox, Safari, Edge — latest 2 major versions).
- **Responsive strategy**: Mobile-first using existing breakpoints.
- **i18n locales**: Preserve current locales.
- **State management**: Preserve existing pattern if identifiable.
- **Routing**: Match existing Laravel web routes.

## Backend

- **Target framework**: Retain Laravel 12 / PHP 8.2 and add FreeRADIUS 3.2, pinned to a currently supported, security-patched release verified at deployment.
- **API contract preservation**: Must preserve existing contracts.
- **Data migration strategy**: In-place additive migrations in the existing application database; preserve legacy records and backfill only verified facts.
- **Auth framework**: Preserve existing Laravel authentication; add distinct per-router credentials for network services.
- **SLA targets**: Measure current latency, throughput, availability, and recovery baselines during discovery. Do not invent SLOs; the test rollout must not regress baseline behavior. Set formal SLOs only after measurement.

## Generic

- **Success definition**: An optional per-router WireGuard + FreeRADIUS access path works end-to-end on supported lab hardware: tenant-safe authentication and policy, measured customer browsing, durable/idempotent payment fulfillment and accounting, truthful portal states, verified disconnect/reconciliation, and tested failure/recovery behavior. Existing site-agent routers, API contracts, sales, and access terms continue unchanged. No production cutover until each release gate passes and the operator explicitly approves it.
- **Out of scope**: No production router changes, production deployment, global agent-requirement disablement, existing entitlement/payment mutation, or live cutover without explicit approval and a tested per-router rollback plan. Keep existing agent-managed routers operational.
- **Existing test posture**: All existing tests must pass (zero regressions).
- **Additional constraints**: None beyond the decisions listed in this specification.

## Gaps & Defaults Applied

None. All applicable fields were answered.

## Downstream Usage Notes

- Preserve existing user flows, API contracts, tenant isolation, payment/access terms, and agent-managed router behavior.
- Build and validate an optional per-router access backend. Do not disable the global agent requirement as a shortcut.
- Production deployment, router changes, and live cutover require separate explicit approval after their release gates and rollback evidence pass.
- Measure existing service baselines before defining formal SLOs; avoid regressions during the test rollout.
