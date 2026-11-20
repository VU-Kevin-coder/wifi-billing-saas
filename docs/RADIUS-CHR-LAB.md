# Local CHR RADIUS lab

This is a local integration path for a RouterOS CHR. FreeRADIUS discovers
eligible routers dynamically by their WireGuard peer IP while retaining a
unique RADIUS secret per router. It is not a production deployment or an
external UDP RADIUS service.

## One-line enrollment and activation gate

The guided onboarding screen displays one RouterOS command that downloads and imports a short-lived script over HTTPS with certificate checking enabled.
The script configures the assigned WireGuard peer, API-SSL on port 8729, the
router-specific RADIUS client, and a dedicated HotSpot profile, then posts the
router identity and API certificate to the enrollment callback. Enrollment
stores the encrypted certificate on the router record and burns the token;
the PEM file used by the API client is a regenerable cache, not the source of
truth. Legacy local certificates are imported lazily when accessed.

Onboarding advances through `awaiting_bootstrap`, `enrolled`,
`tunnel_verified`, `api_verified`, `radius_ready`, and `complete`. Enrollment
alone is not reported as a verified connection: the app first requires a
recent WireGuard handshake and a successful native API-SSL check. Only then
does it queue a RADIUS readiness check. That check verifies the policy bridge,
a FreeRADIUS health listener, and the router's RADIUS configuration.
Enrollment prepares RADIUS but does not rewrite a historical non-RADIUS
backend value. The site-agent backend is no longer supported; those routers
remain fail-closed until an operator explicitly migrates them to RADIUS. For
enrolled routers, API-SSL verifies the persisted router CA while skipping
hostname verification; certificate peer verification remains enabled.
Only a signed FreeRADIUS post-auth Access-Accept from that router activates
RADIUS. Three consecutive Access-Reject events pause pending activation and
alert Platform Admin while preserving the previous backend. An Access-Reject
may reflect an invalid customer credential; it is not proof that the router's
shared secret is incorrect.

The local Compose RADIUS profile now also requires a distinct 32-byte
hexadecimal `RADIUS_HEALTH_SECRET`; configure the same value for the app,
queue worker, and RADIUS service. The health listener is internal to the
Compose network and is not published on the host. It uses UDP 18121 to avoid
colliding with FreeRADIUS's built-in `inner-tunnel` listener on UDP 18120.

## Trust and data path

```text
CHR -- RADIUS over WireGuard --> FreeRADIUS -- private REST --> policy bridge
                                                            --> Laravel policy API
```

- FreeRADIUS shares the app container's network namespace and binds only to
  `WIREGUARD_SERVER_ADDRESS`; Compose publishes no UDP 1812/1813 ports.
- A dynamic FreeRADIUS client block is limited to the application's
  `10.8.0.0/16` WireGuard peer range. Unknown peers are looked up through the
  private bridge; Laravel returns a client record only for an enabled peer
  belonging to a RADIUS-enabled router with a usable unique secret. FreeRADIUS
  caches the record for at most 30 seconds. Policy requests are independently
  bound to the signed router shortname, NAS identifier, and packet source IP.
- Laravel remains authoritative for voucher eligibility and package policy.
  FreeRADIUS has no application database credentials.
- Accounting is validated and written as sanitized local log observations.
  It does not update application sessions, counters, or disconnect state.
- Router API access outside verified API-SSL over WireGuard, CoA, and
  RADIUS CoA remain disabled. Session kicks use the verified API-SSL path
  first, with a durable HTTPS polling queue as fallback. The polling scheduler
  accepts only the `kick` operation and maps it to removal of a matching
  HotSpot active entry; it does not parse or evaluate server-provided scripts.
  Polling credentials are per-router bearer tokens stored as hashes on the
  server, separate from the one-time bootstrap token. A command acknowledgement
  is delivery evidence, not accounting confirmation, and does not clear a
  pending session revocation.

## Local setup

1. In the ignored local `.env`, set `APP_ENV=local`, `RADIUS_ENABLED=true`,
   `RADIUS_PRODUCTION_ENABLED=false`, `WIREGUARD_ENABLED=true`,
   `ROUTER_LAB_PROVISIONING_ENABLED=true`, and
   `RADIUS_LAB_ACCOUNTING_LOG=true`. Set `APP_URL` to the publicly reachable
   HTTPS application URL whose certificate chain the router trusts, and set
   `WIREGUARD_ENDPOINT` to the endpoint reachable by the CHR. Keep the default
   host binding for WireGuard unless the CHR needs another explicitly
   firewalled address.
2. Generate distinct 32-byte hexadecimal values for
   `RADIUS_LARAVEL_SERVICE_SECRET`, `RADIUS_BRIDGE_TOKEN`, and
   `RADIUS_HEALTH_SECRET`. Do not reuse `APP_KEY`, the RADIUS client secret,
   or any router/agent credential.
   `RADIUS_POLICY_API_URL=http://web` and `RADIUS_BRIDGE_ALLOW_HTTP=true` are
   allowed only for this local Compose lab.
3. Start the regular app stack and use the guided router onboarding screen to
   copy and run its one-line bootstrap command. It provisions the WireGuard
   tunnel and verified API-SSL connection, then enrolls the router's identity.
4. The bootstrap prepares its RADIUS client and dedicated `prof-billing`
   profile without switching a pre-existing access backend. The queued
   readiness check must pass before service configuration can proceed.
   Existing routers using the separate manual RADIUS-only workflow can still
   use the one-time CHR `.rsc`; that legacy script expects the
   `prof-billing` profile and does not apply itself.
5. Start the listener and bridge. No per-router FreeRADIUS client entry or
   `RADIUS_LAB_*` client settings are needed; the listener resolves the router
   from its enabled WireGuard peer and fetches its unique secret over the
   authenticated private lookup:

   ```powershell
   docker compose --profile radius-lab up -d --build radius-policy-bridge radius
   ```

6. Test a real voucher through the CHR HotSpot. Check FreeRADIUS and bridge
   container logs for request failures. Sanitized accounting observations are
   written to `storage/logs/radius-accounting.log`; no voucher code, raw
   packet, username, client IP/MAC, or raw NAS session ID is recorded there.

The FreeRADIUS entrypoint fails if the WireGuard listen address is absent or
the bridge token is not at least 32 bytes of hexadecimal. The API policy
bridge fails closed outside local/testing unless its upstream uses HTTPS.

## Explicit non-goals and production blockers

- The schema default affects new routers only; historical non-RADIUS router
  records are not rewritten. Those values do not enable a supported access
  runtime. Router access selection is per router, not per tenant or site.
- RADIUS authorization has not been validated against CHR or physical
  RouterOS in this environment. RouterOS attribute compatibility,
  Message-Authenticator behavior, retransmission, and CHAP/PAP flows need a
  recorded lab acceptance run.
- Accounting reconciliation remains disabled because multi-device session
  state, event ordering, counter completeness, and database lock ordering are
  unresolved. A local observation is not proof of billable usage.
- There is no RADIUS CoA implementation, production secret rotation and
  revocation workflow, production private-network deployment, or production
  TLS/proxy acceptance evidence. The command polling script and RouterOS
  behavior have not been tested on CHR or physical hardware. The configuration
  passes `freeradius -XC` on FreeRADIUS 3.2.1, but dynamic client attribute
  resolution and cache/revocation behavior still require end-to-end and
  RouterOS hardware validation.
- Do not expose RADIUS UDP ports publicly.
- Production readiness requires a separate security review, verified network
  boundaries, shared-cache replay controls, MySQL concurrency tests, operator
  rotation/revocation workflows, and end-to-end RouterOS acceptance.
