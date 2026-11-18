# Detached RADIUS admission guard — WIP, not a secure adapter

This branch adds `security/radius_admission.py` and offline tests. **It is not
connected to Laravel, a RADIUS listener, a router, or the policy API.** It does
not implement RADIUS authentication, encryption, a network service, or a
policy client. No opt-in, runtime, readiness or policy shutdown was changed.
Do not deploy or use this code as evidence that a router is authenticated.

## What is implemented

`AdmissionGuard` takes a trusted, locally provisioned list of exact literal
peer IPs and opaque key-slot labels. It rejects an empty, malformed, ambiguous,
scoped IPv6, all IPv6 link-local (including unscoped), IPv4-mapped IPv6,
unspecified, multicast or duplicate address configuration, duplicate slot labels,
invalid labels and more than 4096 peers.
IPv6 spelling is normalized. This strict one-peer/one-slot profile does not
prove the underlying secrets are unique. The map is copied. No secret is
stored in the guard.

Given the **direct socket peer** and immutable bytes, it rejects unknown peers,
packets outside 20–4096 bytes, bad declared lengths, unsupported request codes,
malformed/truncated attributes, type 0, more than 128 attributes, duplicate
selected singleton attributes, malformed NAS-IP and empty selected attributes.
It requires exactly one correctly sized Message-Authenticator for both Access
and Accounting and restricts Accounting status to Start/Stop/Interim-Update.
This deliberately strict profile may reject legitimate equipment: notably it
does not accept Accounting-On/Off. It is not a full semantic validator and it
does not parse or sanitize every attribute type. Profile compatibility needs
an explicit, reviewed policy and device tests before integration.

The declared RADIUS Length must equal the entire received datagram length.
This intentionally rejects trailing padding, a stricter policy than RFC 2865,
which permits padding beyond the declared Length to be ignored. Verify client
compatibility with this exact-length rule before integration or deployment.
Any future listener must detect and reject truncated or oversize datagrams
before calling the guard: use a socket receive API that reports `MSG_TRUNC`,
reject whenever that flag is set, and reject actual datagrams over 4096 bytes.
Do not assume a 4096-byte receive buffer alone detects an oversized datagram;
truncation could leave a plausible-looking 4096-byte prefix.

An accepted result contains only a **candidate key slot and request code**. The
all-zero Message-Authenticator in the tests is accepted by the framing gate:
its presence is not validation. NAS-Identifier, NAS-IP, usernames and every
other packet claim are never used to select a slot. Source IP itself may be
spoofed (especially over UDP) and is never proof of identity. Neither an
accepted result nor a signed policy request establishes tenant/router identity.
The guard performs no cryptography and intentionally cannot accept, reject or
respond to an access request on the network.

## Requirements before any runtime or session work can depend on this

- Select a maintained, independently reviewed RADIUS server/library with the
  needed protocol support and an explicitly reviewed legacy profile. Have that
  verifier validate the Message-Authenticator in constant time with the
  candidate secret, using the exact received packet and the protocol-defined
  substitutions for the Message-Authenticator and other relevant fields for
  the packet type and legacy profile. Do not simply hash the unchanged packet.
  Validate Accounting Request Authenticator and all required authenticators
  for the packet type and profile;
  authenticate and encode responses using the applicable standards. Handle
  invalid, missing or duplicate authenticators fail-closed. Do not implement
  custom cryptography or infer authenticity from attribute presence.
- Establish a trusted, non-packet-supplied mapping from transport peer and
  verified unique per-router credential to immutable router and tenant identity.
  Shared secrets, ambiguous NAT/source addresses, revoked/disabled peers,
  stale configuration, failed credential lookup, spoofed sources and rotation
  races need explicit fail-closed treatment. Never use NAS-Identifier or an
  adapter HMAC alone as proof. Make sure the policy service independently
  enforces this identity binding; the current middleware intentionally always
  returns 503 after adapter authentication and has no bypass.
- Protect transport and service-to-service communications (including verified
  TLS/private connectivity as appropriate), key generation/storage, rotation,
  revocation, least privilege, and source provenance. Select and test a real
  trust boundary; source IP filtering alone does not provide integrity.
- Add bounded concurrency, request/rate limits, replay and duplicate handling
  suitable for retransmissions, safe response construction and crash recovery.
  Do not accidentally discard legitimate retried accounting or replay a
  rejected event as new. Define secure logging/metrics without secrets,
  vouchers, raw packets, or high-cardinality sensitive labels.
- Restore and expand integration coverage for authorization, accounting and
  sessions, disconnect commands, replay/concurrency, tenant isolation,
  credential rotation/revocation, failure paths and payment recovery. Verify
  forged/tampered packets, incompatible peer configuration and deliberately
  invalid authenticators cannot reach state-changing code. Include isolated
  database tests and an independently controlled router lab only after review.
- Review the entire adapter, policy identity propagation and operational setup
  before considering removal of any shutdown. No production or physical-router
  security claim follows from the current tests.

## Offline tests

Run from the repository root with Python 3.10 or newer:

```
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tests/security -p 'test_*.py' -v
```

Tests use synthetic inputs only and open no sockets or databases. The original
PHP shutdown tests remain separate; passing the Python tests alone does not
exercise Laravel, a real verifier, accounting, sessions, transport, or router.
