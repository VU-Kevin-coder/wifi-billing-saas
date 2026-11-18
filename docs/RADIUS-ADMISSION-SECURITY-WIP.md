# RADIUS admission and adapter status

The application now has a FreeRADIUS 3.x listener and signed Laravel REST
policy bridge for an explicitly enabled, local CHR lab. See
[RADIUS-CHR-LAB.md](./RADIUS-CHR-LAB.md). This is not a production-ready
deployment and has not been validated against a running CHR or physical
router. Production readiness remains disabled by default.

The Python `security/radius_admission.py` guard described below remains a
detached prototype; it is not used by the FreeRADIUS listener. FreeRADIUS
performs RADIUS packet verification for this lab.

The listener uses a dynamic client block scoped to the WireGuard peer range.
For an unknown peer, a private bridge signs a lookup containing only its IPv4
address; Laravel returns a router-specific secret only when that address maps
to an enabled peer of a RADIUS-enabled router. The resulting client record is
cached for 30 seconds. Subsequent policy requests still verify that the router
ID, NAS identifier, and packet source IP match the enabled peer. FreeRADIUS
has no direct database access. The configuration passes `freeradius -XC` on
FreeRADIUS 3.2.1; end-to-end dynamic attribute resolution and hardware
compatibility remain unverified. Lab accounting is append-only sanitized
logging and does not reconcile application sessions or counters. Direct
router API deployment, CoA, and Disconnect-Request remain disabled.

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

## Requirements before production or accounting reconciliation

- Independently verify the chosen FreeRADIUS version, packet verification,
  RouterOS Message-Authenticator compatibility, exact client IP handling,
  and response attribute mapping against supported CHR and physical hardware.
- Define and verify production private networking and TLS on the REST policy
  path. A signed request, a Docker-private network, or source allowlisting
  alone is not a production deployment boundary.
- Validate dynamic per-router client provisioning, then define and test
  credential rotation, revocation, and operational recovery.
- Protect transport and service-to-service communications (including verified
  TLS/private connectivity as appropriate), key generation/storage, rotation,
  revocation, least privilege, and source provenance. Select and test a real
  trust boundary; source IP filtering alone does not provide integrity.
- Keep accounting reconciliation disabled until multi-device limits,
  generation lifecycle, ordering, duplicates, counter completeness,
  concurrency, and database lock ordering are addressed. Do not mistake the
  sanitized lab log for reliable usage accounting.
- Add disconnect/CoA only with durable per-device state, replay protection,
  command recovery, and verified RouterOS responses.
- Restore and expand integration coverage for authorization, accounting and
  sessions, disconnect commands, replay/concurrency, tenant isolation,
  credential rotation/revocation, failure paths and payment recovery. Verify
  forged/tampered packets, incompatible peer configuration and deliberately
  invalid authenticators cannot reach state-changing code. Include isolated
  database tests and an independently controlled router lab only after review.
- Review the entire adapter, policy identity propagation, production network,
  and operational setup before enabling production. Lab tests and a
  configuration file do not establish production or physical-router security.

## Offline tests

Run from the repository root with Python 3.10 or newer:

```
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tests/security -p 'test_*.py' -v
```

Tests use synthetic inputs only and open no sockets or databases. The original
PHP shutdown tests remain separate; passing the Python tests alone does not
exercise Laravel, a real verifier, accounting, sessions, transport, or router.
