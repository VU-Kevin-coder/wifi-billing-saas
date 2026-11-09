# Read-only RouterOS LAN API validation

This is the first live-router proof for Wi-Fi Billing SaaS. It deliberately proves only API reachability and authentication. It must not provision a hotspot, create or disconnect users, edit profiles, or change firewall rules.

## What the selected-router test does

The selected-router endpoint (`POST /routers/{router}/test`) resolves the router inside the active tenant, then calls `RouterConnectionService::test()`. That service makes one RouterOS system-resource read (`getResources`) and returns the result. Router credentials are read from the router record, whose password field is Laravel-encrypted at rest.

Do not use Provision, Sync, Disconnect, or the older settings-wide test as evidence for this check.

## Preflight: do not test until all are true

- [ ] Run the Laravel app from a machine or site agent that can route to the router management LAN. A public cloud deployment cannot normally reach RFC1918 router addresses such as `10.x.x.x` or `192.168.x.x`.
- [ ] Select the intended router in the current tenant and confirm its management address, API username, API password and port are present. Do not paste credentials into tickets, chat, logs or screenshots.
- [ ] Use a dedicated least-privilege RouterOS account, restricted by source IP to the LAN runtime. It needs read/API access only, not write, policy or terminal access.
- [ ] Prefer API-SSL on port 8729. The installed `zilleali/mikrotik-laravel` v1.8 package supports TLS, but the current router record and connection service contain no per-router TLS or certificate-verification setting. Confirm the application TLS configuration is active and verified before entering a production password.
- [ ] Use a non-production demo router first if one exists. Never use predictable demo seed credentials outside a disposable local environment.

## Safe validation sequence

1. Sign in as an operator for the target tenant.
2. Open the target router detail page.
3. Confirm you are using Test connection, not Provision.
4. Run it once. The expected network operation is a read-only RouterOS system-resource request.
5. Record only the outcome, timestamp, router label and sanitized error category if it fails. Do not record the host, username or password in shared notes.
6. Stop after the first successful response. A successful reachability check is not permission to provision or manage hotspot users.

## Interpreting the result

| Outcome | Meaning | Next safe move |
| --- | --- | --- |
| Success | The LAN path, API listener and supplied credentials worked for a read operation. | Capture sanitized evidence and keep provisioning disabled. |
| Missing credentials | The application refused the call before networking. | Fill the selected router record through the protected UI; do not share the secret. |
| Timeout or refused | The Laravel runtime cannot reach the RouterOS API service, or the service/firewall blocks it. | Check routing and API service availability from the same LAN runtime. |
| Authentication failure | The connection reached the router but the API identity is not accepted. | Verify the dedicated read-only account and allowed source, without broadening permissions. |
| TLS/certificate failure | Secure transport is being attempted but trust is not configured. | Fix certificate verification; do not fall back to plaintext API. |

## Evidence required before router write actions

Keep these three facts together: the commit SHA deployed to the LAN runtime, one sanitized successful selected-router test, and confirmation that the account is read/API-only and source-restricted. This validates reachability only; a separate, reviewed stage is required before enabling provisioning, voucher sync, session disconnects or hotspot changes.
