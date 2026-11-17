# REMOTE SNAPSHOT INCOMPLETE

The integration code and test fixes are not yet published to this remote branch. Do not deploy or merge it. The complete reviewed work remains local.

# Integration status

- Local integration baseline: `5bbe5542a87a70e23e6a6b3113a665fe07ebf80f` (PR 54 head). A GitHub check previously found that main at `cddf65d6` matched the baseline's root content, despite the different commit SHAs; exact Git tree modes were not verified. This does not establish the current state of main or any external deployment.
- Includes the reviewed UI work and regression tests from the UI worktree, reconciled with the baseline payment status view. The standalone portal design preview is illustrative only, not a functioning portal.

## Current worktree safety safeguards

- Router setup script generation and automated deployment are blocked server-side because device identity and existing configuration cannot be verified. This is **not** a blanket block on all router writes or commands; other router and site-agent operations remain possible. Previously downloaded scripts remain hazardous and must not be used: they could overwrite bridge, DHCP, firewall or RADIUS settings.
- Signed RADIUS policy endpoints reject even correctly authenticated adapter requests with a 503: an adapter signature or claimed NAS identifier does not establish the actual router's identity. There is no configuration bypass. New router RADIUS opt-in is also blocked at both the controller and service boundaries.
- RADIUS readiness is hard-disabled: authorization rejects and RADIUS voucher activation is unavailable. Accounting events are rejected/quarantined as unavailable, **not buffered for later replay**. Existing RADIUS access and accounting cannot be relied on during this shutdown.
- When a paid sale's fulfillment encounters RADIUS unavailability, its work is returned to pending without consuming the retry budget, with another attempt scheduled. This preserves a pending state, not a guarantee of eventual recovery or delivery; operators must monitor and resolve affected sales. Customers are told not to pay again.
- Router connections using `RouterTransportConfig` require verified TLS in every environment, including on custom ports. The sole plaintext exception is port 8728 with an explicit test-only setting, and only when both the actual and configured application environment are `testing`. This is not a general guarantee for every possible adapter or legacy path; excluded/unintegrated adapters remain outside that claim. `RouterService` fails closed when no tenant is present instead of using the former tenantless `MikroTik::hotspot()` fallback. Router/site-agent failure reporting avoids exposing raw router exception messages in the changed paths; session-expiry disconnection failure logs omit the exception and retain only session, router and voucher IDs.

## Remaining work and verification

- No integrated, trusted-router RADIUS authentication solution is provided; external/other adapters are excluded from this integration and have not been verified as covered by these safeguards. The full PPPoE portal and tenant subscription backend are not implemented.
- The current test changes reflect the shutdown, but previous tests asserting enabled RADIUS authentication, accounting idempotence and router script safeguards are unavailable in the shutdown test coverage. Historical tests remain in Git history; restore and reconcile these assertions before enabling the corresponding flows. Shutdown tests are not a substitute for enabled-mode coverage.
- Real TLS transport, accounting and reconnect behavior, and payment recovery still require verification. Existing and new tests do not establish full integration, security, browser, adapter, or end-to-end coverage. No physical router or production testing was performed; no test counts are asserted here.
- New checkout initiation is blocked before a payment request when a tenant has a RADIUS router while RADIUS is unavailable, has no router, or has an unsupported backend; site-agent-only tenants remain supported. Concurrent router configuration changes are not atomic with the check. Existing payment callbacks remain separate, and recovery is not guaranteed.
- Uncertain experimental work in other branches, including conflicting authentication/revocation changes and the reversed sandbox checkout, was not merged. Other source worktrees were retained unchanged.
- This is a local integration, not production-ready and not a production release.
