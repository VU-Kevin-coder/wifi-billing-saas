# Internal RADIUS Policy Channel

The internal RADIUS policy endpoints are intended for the authenticated Laravel
service channel only. In production, the application requires the setting
`RADIUS_POLICY_ALLOWED_SOURCE_CIDRS` to contain a comma-separated list of
narrowly scoped CIDR ranges for the private adapter peers or explicitly trusted
direct network peers.

For example, a deployment might configure `10.60.4.0/24` or a narrowly scoped
IPv6 ULA range. Select values appropriate to the deployment; this example is
not a statement about the current production network. Catch-all ranges such as
`0.0.0.0/0` and `::/0`, missing values, malformed addresses, and invalid prefix
lengths are rejected. The middleware checks the TCP peer address (`REMOTE_ADDR`)
and does not use caller-supplied forwarding headers to satisfy the allowlist.
When a trusted proxy is used, configure the allowlist for the proxy's direct
peer address/range, not for an unverified forwarded client address.

This setting supplements—not replaces—the existing production HTTPS,
service-secret HMAC, timestamp, nonce/replay, and shared-cache checks. It is a
production configuration prerequisite before enabling or exposing these
endpoints. This document does not provision infrastructure, configure
production, or establish that a production route is reachable only through the
approved channel; reachability remains unverified.
