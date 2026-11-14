---
schema: clarification-answers/v1
status: submitted
generated_at: "2026-11-13T18:00:14.871Z"
scope: [frontend, backend, generic]
questions_file: clarification-questions.json
---

# Rearchitecture Clarification Answers

## 🖥️ Frontend

- **F1** Which frontend framework and version should the architecture work retain or target? The proposal keeps Laravel as the application and the current UI is Blade-rendered.
  - answer: Laravel Blade on Laravel 12 (retain current UI)
  - source: user
- **F2** Which component/UI library should be used for the portal and operator status updates? The repository currently uses Tailwind CSS 3.1 and existing Blade components.
  - answer: Tailwind CSS 3.1 and existing Blade components
  - source: user
- **F3** Provide screenshot/recording paths or a staging URL for any portal screens whose appearance should be reproduced. If no redesign is intended, state that the current layout should be preserved and only the required status information should change.
  - answer: No redesign is intended. The current layout should be preserved, and only the required status information should change.
  - source: user
- **F4** Which visual system should guide portal changes? The repository has Tailwind configuration and app CSS; the proposal asks to preserve the user experience.
  - answer: Preserve current Blade/Tailwind styles and tokens from tailwind.config.js and resources/css/app.css
  - source: user
- **F5** Which accessibility standard should portal changes meet?
  - answer: WCAG 2.1 AA
  - source: user
- **F6** Which browsers/runtime versions must the customer and operator portals support?
  - answer: modern evergreen (Chrome, Firefox, Safari, Edge - latest 2 major versions)
  - source: user
- **F7** What responsive strategy should be used for customer phones/laptops and operator screens?
  - answer: mobile-first using existing breakpoints
  - source: user
- **F8** Which locales are in scope for the portal changes?
  - answer: preserve current locales
  - source: user
- **F9** What client-side state-management approach should be used for the added portal states?
  - answer: preserve existing pattern if identifiable
  - source: user
- **F10** Should portal routing remain on the existing Laravel web routes, or should a separate client-side router be introduced?
  - answer: match existing Laravel web routes
  - source: user

## ⚙️ Backend

- **B1** Confirm the backend stack for the new access path. The proposal keeps Laravel as the policy/source-of-truth service and recommends FreeRADIUS 3.2 for RADIUS, pinned to a supported patched release.
  - answer: Retain Laravel 12 / PHP 8.2 and add FreeRADIUS 3.2
  - source: user
- **B2** What compatibility policy should apply to existing REST/API contracts while the new RADIUS policy interface is added?
  - answer: must preserve
  - source: user
- **B3** How should the new entitlement, reservation, accounting, and fulfillment data be introduced? The proposal calls for additive/reversible migrations and preserving legacy records.
  - answer: in-place
  - source: user
- **B4** Should app-user authentication remain as implemented, while adding distinct per-router WireGuard keys and RADIUS secrets?
  - answer: preserve existing Laravel authentication; add per-router credentials for network services
  - source: user
- **B5** What latency, throughput, availability, and recovery targets should gate production rollout? If no targets are established, should discovery measure the current baseline and avoid regressions?
  - answer: **Baseline and regression, no invented SLOs.** Formal SLOs should be set *after* discovery measures the current baseline. Do not invent targets now.

- **Latency:** Gate on the current baseline (likely sub-200ms within the EU, but this must be measured). No regressions allowed.
- **Throughput:** Gate on the current baseline (the Contabo VPS comfortably handles 5–10 tenants). No regressions allowed.
- **Availability:** Gate on the current baseline. No 99.9% uptime guarantee until a monitoring stack (e.g., Uptime Kuma) is live.
- **Recovery:** Gate on the current baseline (manual restore from local backup). Automated backups and RTO/RPO targets are pending.

**Verdict:** Discovery should measure the current baseline and strictly avoid regressions during the test rollout.
  - source: user

## 📋 General

- **G1** Confirm the success criteria for this staged implementation.
  - answer: An optional per-router WireGuard + FreeRADIUS access path works end-to-end on supported lab hardware: tenant-safe authentication and policy, measured customer browsing, durable/idempotent payment fulfillment and accounting, truthful portal states, verified disconnect/reconciliation, and tested failure/recovery behavior. Existing site-agent routers, API contracts, sales, and access terms continue unchanged. No production cutover until each release gate passes and the operator explicitly approves it.
  - source: user
- **G2** Confirm what must stay out of scope until separate approval.
  - answer: No production router changes, production deployment, global agent-requirement disablement, existing entitlement/payment mutation, or live cutover without explicit approval and a tested per-router rollback plan. Keep existing agent-managed routers operational.
  - source: user
- **G3** What must-pass policy should apply to the existing test suite during this phased migration?
  - answer: must pass
  - source: user
- **G5** Add any additional requirements, exclusions, dependencies, compliance rules, or operational constraints not covered above.
  - answer: None beyond the decisions listed in this specification.
  - source: user
