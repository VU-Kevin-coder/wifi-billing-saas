# UI browser verification — 2026-11-17

Browser scope: synthetic fixtures only, not a production or full Laravel app. Cloud browser results and local Chromium attempts are distinct from the isolated feature tests below. The cloud fixture `tests/ui/browser-fixture.html` embeds dialog JavaScript and CSS and uses a viewport meta tag; it is not a running Laravel page. Existing local Chromium was 148.0.7778.96 on Debian 13; `/tmp` reported 100% full (260 KiB free at start), so browser profile, cache, HOME, temporary files and output were placed in `/dev/shm`. No artifacts need be retained.

## Cloud browser fixture

The real browser passed the fixture's dialog rendering, keyboard interaction, queue serialization and input behavior. A narrow iframe was used to inspect a constrained-width layout; it is not a real mobile device, mobile browser or complete mobile accessibility test. The fixture embeds a snapshot of the dialog code and styles, so it does not establish that production assets, Laravel pages, other components, or backend workflows work end to end. Reduced motion and real-device testing remain open.

## Scope and integration limits

The flash partial supplies escaped visible fallback messages if the bundle or dialog is unavailable or invocation fails; the platform tenant list has an eight-column empty state. Changed Alpine views move dynamic values such as package JSON and the router disconnect message into escaped data attributes to avoid injecting them into inline expressions. The mobile sidebar, customer-portal submit feedback and payment-status messages also changed. These are targeted fixes, not a system-wide injection or tenant-isolation audit or proof of successful payment or access.

Following security review, policy center and control settings no longer save or replay client drafts or flashed input. Validation redirects show current server-rendered settings; users must re-enter changes and the reason, including secrets where applicable. Legacy control-settings session-storage draft cleanup is best-effort. Laravel can still flash policies and reasons on the server, and successful saves record audit reasons. See [settings-verification.md](settings-verification.md); there is no lossless-input guarantee.

Both settings pages retain native `beforeunload` warnings when dirty, even for intended save or discard: browser prompts are synchronous and browser-controlled. Canceling the prompt during submission may leave the submit guard locked until page restoration or reload. Real-browser navigation and recovery need testing.

This branch is based on `b57f48e`; GitHub main was `cddf65d6e25d513787029d28a3c7cff12a38ffb9` at the check. The exact main commit was not locally fetchable, and upstream changes overlap with this branch's portal paths. Reconciliation is unverified. Integrate against the exact current main and repeat relevant checks before release. The full Laravel application and frontend build were not verified, and there was no production deployment; none of these results establishes system-wide coverage.

## Automated unit/VM checks

```sh
node --test tests/ui/*.test.mjs
python3 -m unittest discover -s tests/ui -p '*_test.py'
```

Result on this worktree: 35 Node tests passed, 0 failed; 9 Python tests passed. These include simulated dialog/confirmation, session action, settings, sidebar and portal feedback checks and targeted source-level regressions. They are simulated DOM/VM or static checks, not real-browser or live backend tests.

## Isolated Laravel feature run

A separate isolated Laravel feature run passed **7 tests, 176 assertions** on the old `b57f48e` base using donor dependencies, remapped classes and network blocking. This is useful targeted evidence only: it is not a full app test, not a run against the exact latest main commit, and not verification of the integration or production deployment. This worktree has no local `vendor` or `node_modules`; a frontend build and full application test remain open.

## Browser attempts

```sh
cd /home/daytona/workspace/wifi-ui-experience
mkdir -p /dev/shm/wifi-ui-browser/{profile,cache,tmp}
timeout -k 2s 35s env HOME=/dev/shm/wifi-ui-browser TMPDIR=/dev/shm/wifi-ui-browser/tmp XDG_CACHE_HOME=/dev/shm/wifi-ui-browser/cache \
  /usr/bin/chromium --headless --no-sandbox --disable-gpu --disable-dev-shm-usage \
  --disable-background-networking --disable-component-update --disable-sync --no-first-run \
  --no-default-browser-check --host-resolver-rules='MAP * ~NOTFOUND, EXCLUDE localhost' \
  --user-data-dir=/dev/shm/wifi-ui-browser/profile --disk-cache-dir=/dev/shm/wifi-ui-browser/cache \
  --virtual-time-budget=4000 --dump-dom file:///home/daytona/workspace/wifi-ui-experience/tests/ui/dialog-browser.html \
  >/dev/shm/wifi-ui-browser/result.html 2>/dev/shm/wifi-ui-browser/chromium.log
```

First run produced no DOM before the 35-second timeout. Repeated D-Bus errors: `Failed to connect to the bus: Could not parse server address: Unknown address type`; shutdown also emitted `Failed to call method: org.freedesktop.DBus.NameHasOwner`. The first run piped output through `tail`, so its precise Chromium exit code was not retained.

A materially different run removed the session D-Bus and proxy environment variables, used `--no-zygote --single-process --disable-crash-reporter --disable-breakpad`, with a separate `/dev/shm/wifi-ui-alt` profile/cache and 20-second timeout. It exited 137 after the kill timeout, output was 0 bytes, the same D-Bus parsing errors appeared, and Chromium reported `Cannot use V8 Proxy resolver in single process mode`.

A third run explicitly used `DBUS_SESSION_BUS_ADDRESS=unix:path=/run/dbus/system_bus_socket`, `--no-proxy-server`, a separate `/dev/shm/wifi-ui-fixed` profile/cache and a 20-second timeout. It exited 137 after kill timeout, output was 0 bytes and the error log was empty. Changing the D-Bus address suppressed errors but did not restore startup. These observations do not establish the root cause; `/tmp` fullness and the initial D-Bus failures are concrete environment issues.

No verdict was obtained from local Chromium. The older `dialog-browser.html` fixture used in these attempts omits `public/css/ui-dialog.css` and a mobile viewport meta tag; it dispatches a synthetic cancel event and uses programmatic clicks, so it cannot establish production styling, actual keyboard behavior, or mobile/reduced-motion behavior. The separate cloud fixture results are described above. A future integrated browser harness should load the actual production assets and drive real key events, including on real mobile devices and with reduced motion enabled. The session checks above are VM-only; no browser/backend session action was exercised.

Cleanup (only the disposable directories created by these attempts):
```sh
rm -rf /dev/shm/wifi-ui-browser /dev/shm/wifi-ui-alt /dev/shm/wifi-ui-fixed
```
