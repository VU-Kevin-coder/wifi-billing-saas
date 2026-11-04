# Router end-to-end proof plan

## Purpose

Prove one real MikroTik connection safely before any hotspot provisioning or customer-impacting workflow is enabled. The first proof is deliberately read-only: a saved router record is used to request RouterOS system resources, and the application records a successful local health check.

## Current repository state

- `main` already contains `RouterConnectionService` with `test`, `liveStatus`, `disconnectUser`, and one per-router `configureRouter` method.
- `RouterController@testConnection` uses the saved router record and returns the read-only result as JSON.
- The older `ping` path still fabricates latency and telemetry. It must not be treated as proof of a real connection.
- Pull request #2 changes that legacy `ping` path, but is conflict-dirty because `main` independently added the broader service and a separate `testConnection` action. Merging its version of `RouterConnectionService` would remove `liveStatus` and `disconnectUser`, breaking the current router-detail and session-expiry flows. Do not auto-merge it.
- The current client configuration defaults to RouterOS API port 8728 and does not set TLS. Use a management-LAN-only test account for this proof; move the production path to API-SSL (8729) before any non-LAN deployment.
- Voucher activation currently creates the application session but does not create a matching RouterOS hotspot user/profile. Therefore, a completed customer login is not yet a real end-to-end hotspot proof.

## Safe proof sequence

1. Run the application from a machine that can reach the router management LAN. Never expose the RouterOS API to the public internet.
2. Create a dedicated test RouterOS user with the minimum read-only policy needed for `/system/resource/print`; do not use the router admin account.
3. Add or update one router record with the management-LAN address, API port, and that test user’s credentials.
4. Run the project test suite in the same Docker/Laravel environment that will perform the LAN test. At minimum, exercise the missing-credentials guard and the router test endpoint.
5. Use the UI action wired to `RouterController@testConnection`, not the legacy simulated Ping / Test control. Capture a success response and the updated local `status` and `last_seen_at`.
6. Confirm the router has no configuration, hotspot-user, firewall, or reboot changes. This is the acceptance evidence for the read-only proof.
7. Only after that proof, implement a separate, feature-flagged RouterOS hotspot-user create/remove operation plus an idempotency test. Then prove one disposable voucher on a dedicated test SSID and remove it.

## Merge resolution for PR #2

Resolve by keeping `main`’s `RouterConnectionService` and its `testConnection` action. Do not replace the service with PR #2’s smaller 62-line version. Either:

- close PR #2 as superseded and add focused regression coverage on top of `main`; or
- rebase it into a narrow change that makes the legacy `ping` action delegate to the existing `test` method, removes fake telemetry, and preserves the service’s `liveStatus` and `disconnectUser` methods.

The safer choice is the first one, followed by a clean test-only PR.

## Evidence to attach before calling this complete

- passing Laravel/Pest output from the LAN-capable environment
- one successful read-only RouterOS response, with credentials and addresses redacted
- local router record showing a new `last_seen_at` value
- RouterOS audit/diff showing no configuration changes
- confirmation that the API was reachable only from the management LAN
