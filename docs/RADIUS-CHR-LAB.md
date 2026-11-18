# Local CHR RADIUS lab

This is a local integration path for a RouterOS CHR. FreeRADIUS discovers
eligible routers dynamically by their WireGuard peer IP while retaining a
unique RADIUS secret per router. It is not a production deployment or an
external UDP RADIUS service.

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
- Direct router API deployment, CoA, and Disconnect-Request delivery remain
  disabled.

## Local setup

1. In the ignored local `.env`, set `APP_ENV=local`, `RADIUS_ENABLED=true`,
   `RADIUS_PRODUCTION_ENABLED=false`, `WIREGUARD_ENABLED=true`,
   `ROUTER_LAB_PROVISIONING_ENABLED=true`, and
   `RADIUS_LAB_ACCOUNTING_LOG=true`. Set `WIREGUARD_ENDPOINT` to the endpoint
   reachable by the CHR. Keep the default host binding for WireGuard unless
   the CHR needs another explicitly firewalled address.
2. Generate distinct 32-byte hexadecimal values for
   `RADIUS_LARAVEL_SERVICE_SECRET` and `RADIUS_BRIDGE_TOKEN`. Do not reuse
   `APP_KEY`, the RADIUS client secret, or any router/agent credential.
   `RADIUS_POLICY_API_URL=http://web` and `RADIUS_BRIDGE_ALLOW_HTTP=true` are
   allowed only for this local Compose lab.
3. Start the regular app stack, pair the CHR with a WireGuard peer, and apply
   its one-time WireGuard configuration. Set the router's hardware model to
   `CHR` and ensure it has an enabled peer.
4. As an authorized operator, enable RADIUS for that router. The resulting
   no-store page displays the new shared secret once and, only in the enabled
   local CHR lab, offers a one-time RADIUS-only `.rsc`. Save it securely and
   import it manually into the intended CHR. The script expects an existing
   HotSpot profile named `prof-billing`; it does not apply itself or alter
   RouterOS API settings.
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

- The schema default affects new routers only; existing `site_agent` router
  records are not rewritten. Router access selection is per router, not per
  tenant or site.
- RADIUS authorization has not been validated against CHR or physical
  RouterOS in this environment. RouterOS attribute compatibility,
  Message-Authenticator behavior, retransmission, and CHAP/PAP flows need a
  recorded lab acceptance run.
- Accounting reconciliation remains disabled because multi-device session
  state, event ordering, counter completeness, and database lock ordering are
  unresolved. A local observation is not proof of billable usage.
- There is no CoA/Disconnect implementation, production secret rotation and
  revocation workflow, production private-network deployment, or production
  TLS/proxy acceptance evidence. The configuration passes `freeradius -XC` on
  FreeRADIUS 3.2.1, but dynamic client attribute resolution and cache/revocation
  behavior still require end-to-end and RouterOS hardware validation.
- Do not expose RADIUS UDP ports publicly.
- Production readiness requires a separate security review, verified network
  boundaries, shared-cache replay controls, MySQL concurrency tests, operator
  rotation/revocation workflows, and end-to-end RouterOS acceptance.
