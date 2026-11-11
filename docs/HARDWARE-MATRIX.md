# Hardware and RouterOS compatibility

Status date: 2026-11-11

This is the intended support matrix, not a claim that every model has passed a physical-router test. The app uses RouterOS API operations and generated RouterOS scripts; compatibility must be accepted per exact model and RouterOS release.

| Model | Intended use | RouterOS target | Validation status |
|---|---|---|---|
| MikroTik hAP ac² | Wireless hotspot edge router | 7.10+ | Physical test not recorded |
| MikroTik hAP ax² | Wireless hotspot edge router | 7.10+ | Physical test not recorded |
| MikroTik RB4011 | Higher-capacity wired/wireless edge router | 7.10+ | Physical test not recorded |
| MikroTik CHR | Disposable virtual acceptance target | 7.10+; local Compose image pinned to 7.24.6 | CI/API mocks do not execute RouterOS; CHR run pending |

RouterOS 7.10 is the proposed minimum for the supported range, not a verified minimum. Only mark a row tested after recording the model, exact RouterOS version/build, script revision, and pass/fail results below. RouterOS 6 is not in the target matrix.

## What is and is not tested

- Automated application tests exercise RouterOS behavior through fakes/mocks;
  they validate generated settings and control-plane decisions, not device
  firmware behavior.
- The Compose CHR service is pinned to 7.24.6 to avoid a moving `latest` tag.
  Starting that container and completing the workflow below is not yet evidence
  of a passing hardware acceptance test.
- No physical hAP ac², hAP ax², or RB4011 test has been recorded in this repository as of the status date.

## End-to-end acceptance procedure

Run on a disposable CHR or a factory-reset, isolated test router. Do not use a production router. Record the model and full RouterOS version before starting.
Keep an out-of-band recovery path and export a backup before provisioning.

1. **Provision the package:** create and publish a package with a known duration,rate limit, device limit, and data cap. Generate and review the router `.rsc`; confirm API access is source-restricted and the hotspot profile is `login-by=http-chap` (not `http-pap`).
2. **Create a voucher:** issue a one-use voucher for that package. Confirm the router assignment and generated hotspot user profile match the package.
3. **Purchase:** use the configured payment sandbox and verify that no voucher is issued before the provider confirms payment. Confirm activation is acknowledged by the paired site agent.
4. **Redeem:** redeem a separate unused voucher from the captive portal. Confirm
   the generated router login URL contains no voucher code or username query
   parameter; enter the voucher using RouterOS's captive-portal form.
5. **Hotspot login:** connect a test client to the hotspot SSID/port and authenticate through the RouterOS CHAP login page. Verify the client receives access and the session is visible in the application.
6. **Verify limits:** inspect the RouterOS hotspot user and active session. Verify rate, shared-device, uptime, and byte limits against the selected package and voucher. Confirm the application reports the same session.
7. **Expiry:** wait for a short test package to expire. Confirm the site agent disables the router user, disconnects any active host, and the application marks the session expired. Confirm the expired voucher cannot reconnect.
8. **Re-provision:** run the generated provisioning script a second time on the disposable router. Confirm it is idempotent and does not create duplicate bridge, address, DHCP, hotspot, API-service, or package-profile entries.
9. **Network-loss recovery:** interrupt the agent's cloud connection while a harmless test command is pending. Confirm it remains pending (not reported successful), the worker retries after connectivity returns, and the router state converges without duplicate voucher users or sessions.
10. **Capture evidence:** record router identity/version, application commit, timestamps, command outcomes, screenshots or sanitized exports, and any failures. Never include API passwords, agent bearer tokens, WireGuard private keys, payment credentials, or customer data.


