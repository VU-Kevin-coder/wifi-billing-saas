# Secret Isolation for the RADIUS Boundary

Use separate credentials for router-to-FreeRADIUS authentication and FreeRADIUS-to-Laravel service authentication; Laravel remains the only policy and tenant authority.

## What Happened

For wifi-billing-saas task t11, the proposed private Laravel policy API was approved for Phase 1 repository/domain implementation with explicit isolation, least-privilege, replay-protection, and secret-delivery controls. A service secret must not be treated as a general bearer credential: bind authenticated requests to method, path, body, timestamp, and a one-time nonce over protected TLS. Keep router secrets encrypted in Laravel and reveal them only once to authorized operators. Do not expose `APP_KEY` or router secrets to FreeRADIUS. This is a design decision, not approval for infrastructure or router operations.

## Takeaway

When reviewing a RADIUS policy API, verify distinct router and service credentials, router-authenticated identity mapped to Laravel-derived tenant, a route-limited default-deny service identity, replay-resistant request authentication, secret/log redaction, one-time delivery, and fail-closed policy behavior. Do not grant FreeRADIUS direct database access or let it mutate payment/entitlement truth.

## History
- 2026-11-13 (wifi-billing-saas/t11): initial
