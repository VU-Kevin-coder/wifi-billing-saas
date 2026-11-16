# Disconnect transport investigation — simulator only, not a worker

This worktree starts at `0de39823cb6fcc4ef2cb35fa353721567e9e5f9c`.
**Do not connect it to a real router, real users, or production.** No queue
consumer or physical-device sender is enabled or provided. The test in this
slice is a loopback-only FreeRADIUS simulator, not an end-to-end test of the
Laravel service or MikroTik. The gaps below prevent safely attaching a real
sender to the current contract, even in a lab with actual devices.

## Current contract, exactly

* `GET /internal/radius/v1/disconnect-commands` claims up to 25 rows in one
  database transaction. It returns `{"commands":[{"command_id": <uuid>,
  "nas_identifier": <string>, "nas_session_id": <string>, "generation":
  <integer>, "attempt": <integer>}]}`. It does not return address, port,
  MAC, voucher/user name, credential, lease token or expiry. No query string
  is permitted by the authentication middleware. The shared service identity
  can claim all eligible routers, not a tenant-scoped subset.
* Eligible states are pending/retry_wait due, delivery_acknowledged due, and
  expired leases; the accounting generation must still be open at claim time.
  Claim increments attempt and sets a lease (default 60 seconds; minimum 15).
  Failed/ACKed delivery is re-eligible after `retry_seconds` (default 30,
  minimum 15); an expired lease is re-eligible. Batch size and processing time
  make lease expiry before send plausible. Attempts use an unsigned small
  integer column, so unbounded retries also need an overflow/terminal policy.
* `POST /internal/radius/v1/disconnect-commands/{uuid}/ack` accepts `result`
  `delivered` or `retryable_failure` and optional `result_code` matching
  `[A-Za-z0-9._-]*`, length <= 64. The response is `accepted`, `duplicate`,
  and `delivery_state`. The implementation checks the UUID, but **does not
  require a current lease, token, attempt, worker, or unexpired lease**. A late
  result can update another worker's lease, a stopped session's old command,
  or an otherwise unleased command. Repeated delivered acknowledgements are
  marked duplicate only while the row is `delivery_acknowledged`. This is not
  a safe lease-fencing protocol.
* `delivered` means only delivery acknowledged. It does not change the
  session to disconnected. A subsequent accepted and identity-bound Accounting
  Stop is required to close the accounting generation and application session.
  Accounting validation includes voucher, MAC, timestamp ordering and counters;
  missing or quarantined Stop cannot prove disconnection. The claim query
  excludes stopped generations but Stop does not mark the command complete,
  so stale state/cleanup needs explicit reconciliation.
* HMAC service authentication: `METHOD\nPATH\nsha256(raw_body)\nTIMESTAMP\nNONCE`,
  HMAC-SHA256 hex; headers `X-Radius-Service-Timestamp`,
  `X-Radius-Service-Nonce`, `X-Radius-Service-Signature`. GET signs the empty
  body. Nonce is unique and cache-backed, timestamp checked, query forbidden;
  production requires HTTPS, a restricted direct-peer CIDR, and a shared atomic
  nonce cache. `radius/adapter.py` signs only POST and cannot be called unchanged
  for the claim GET. Its HTTPS, no-redirect and no-proxy constraints should be
  preserved. All endpoints share `throttle:120,1`.

## Why no device sender is committed

1. A database `generation` is not sent in the RADIUS Disconnect-Request and is
   not a NAS-enforced generation discriminator. The code permits a later
   accounting generation for a previously stopped `(router, nas_session_id)`.
   A leased or delayed packet for the previous generation could terminate the
   new one. Checking current generation just before send cannot remove the
   network/Stop/reuse race. At minimum establish a NAS-supported, proven unique
   on-wire discriminator or enforce a durable non-reuse rule before admission,
   including the in-flight and reboot cases. The existing reservation and the
   three queue fields alone do not establish this property.
2. NAS identifier must be bound to an operator-controlled, unique router
   endpoint and its matching per-router secret. The queue does not supply the
   destination; the secret is encrypted in Laravel and disclosed once on
   provisioning, not exported by this API. A trusted provisioned mapping must
   bind exact identity, IP, port, credential and version; unknown/ambiguous,
   duplicate, changed, or missing entries must fail closed. Dynamic DNS,
   payload-supplied addresses, and secrets on a command line are not safe
   substitutes. The provisioned mapping needs a lifecycle and rotation plan.
3. Add a fresh unpredictable lease token per attempt, bind result to token,
   attempt and worker, reject stale/expired/unleased writes, and atomically
   check the still-current accounting generation. Define Stop/lease race,
   idempotent result replay, and cleanup. Test simultaneous claim/reclaim,
   expiry, stale worker result, Stop before/after send, and transaction races on
   the actual PostgreSQL/MySQL engine; SQLite tests do not prove row locking.
   Fencing prevents stale database updates, not a stale UDP packet, so item 1
   still needs its own solution.
4. Keep retry state durable in Laravel. Timeout, invalid or unauthenticated
   response, unknown mapping, HTTP error and crash must never count as delivered;
   an authentic NAK is not success and needs classified bounded retry or operator
   reconciliation. On an ACK/API failure, lease expiration permits replay; a
   repeat ACK is not Accounting Stop. Define backoff, jitter, attempt cap,
   post-ACK Stop timeout, alarms, and a durable ambiguous/manual-reconcile state.
   Limit lease batch and timeouts so active work cannot silently overrun leases.
5. RFC 5176 uses a response authenticator bound to the request and shared
   secret. Validate identifier, code, length, source endpoint, response
   authenticator and Message-Authenticator where present (and require it if the
   selected tested policy permits). Never treat a mere UDP receipt, ACK text,
   process start, or a forged/wrong-type reply as success. Classic RADIUS/CoA
   uses weak MD5-based integrity and does not provide confidentiality or robust
   replay protection; use an isolated authenticated encrypted network, strict
   source/destination ACLs, strong unique per-router secrets, and a verified
   replay-protection policy. RFC 5176 recommends IPsec and discusses
   Event-Timestamp when IPsec replay protection is not employed.

## Simulator evidence and limits

Run with a **trusted, readable stock FreeRADIUS 3 configuration** and installed
`freeradius`/`radclient` binaries:

```
python3 radius/test_disconnect_transport.py /path/to/readable/freeradius/3.0
```

The test copies the configuration to a disposable directory, installs only a
loopback CoA listener and synthetic client, and invokes `radclient` with its
synthetic secret in a mode-0600 file (`-S`), never on the command line. A
loopback proxy mutates authentic simulator responses without implementing
cryptography. It checks valid Disconnect-ACK and NAK, wrong secret/no reply,
modified response authenticator, modified identifier, altered response code,
and response from the wrong UDP source port. Outputs and debug data are only
synthetic; temporary data is deleted. This establishes observed behavior for
the installed version, not a general guarantee for every FreeRADIUS build. In
particular it does not prove Message-Authenticator enforcement, forged replies
with a recomputed response authenticator, duplicate/replay behavior, or any
production logging, Laravel, RouterOS, network, or session semantics. Verify
those separately before selecting a library/process interface. Never use
FreeRADIUS debug logs with real secrets or subscriber data.

MikroTik's [RouterOS documentation](https://help.mikrotik.com/docs/spaces/ROS/pages/328097/RADIUS)
says unsolicited Disconnect Messages use `/radius incoming`, which defaults to
`accept=no` and UDP port **1700**; RFC 5176's default is 3799. CoA changes
authorization while Disconnect terminates; neither is confirmed working for
the intended RouterOS version here. The precise minimum matching attributes,
source restrictions, Message-Authenticator/Event-Timestamp support, NAS
session ID uniqueness/reuse, and accounting Stop delivery remain unverified on
physical hardware. This is not a router configuration recipe or approval for a
physical test. [RFC 5176](https://www.rfc-editor.org/rfc/rfc5176) defines the
packet/reply and security considerations.
