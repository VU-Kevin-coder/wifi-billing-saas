"""Detached, pre-authentication RADIUS datagram admission filter.

This module does NOT authenticate a router or packet and has no network I/O.
A non-None result only permits a configured verifier to attempt verification.
It MUST NOT be used as an authorization, accounting or identity decision.
"""

from dataclasses import dataclass
from ipaddress import IPv6Address, ip_address
from types import MappingProxyType
from typing import Iterable

MAX_DATAGRAM = 4096
MAX_ATTRIBUTES = 128
MAX_PEERS = 4096
# Conservative singleton profile. Rejection may exclude legitimate clients.
SINGLETONS = frozenset({1, 4, 32, 40, 44, 80})


@dataclass(frozen=True, slots=True)
class Candidate:
    """An unverified configuration lookup, never an authenticated identity."""
    key_slot: str
    request_code: int


def _address(value: object):
    if type(value) is not str or not value or '%' in value:
        raise ValueError('a literal, unscoped peer address is required')
    try:
        address = ip_address(value)
    except ValueError:
        raise ValueError('invalid peer address') from None
    # Mapped forms otherwise create ambiguous v4/v6 configuration matches.
    if isinstance(address, IPv6Address) and address.ipv4_mapped is not None:
        raise ValueError('IPv4-mapped IPv6 is unsupported')
    # A link-local address without an interface scope is not a unique peer.
    if isinstance(address, IPv6Address) and address.is_link_local:
        raise ValueError('IPv6 link-local peer addresses are unsupported')
    if address.is_unspecified or address.is_multicast:
        raise ValueError('unspecified and multicast peer addresses are unsupported')
    return address


class AdmissionGuard:
    """Exact transport-source-to-key-slot lookup plus a strict framing gate.

    Configuration comes only from a trusted local control plane. Source IP can
    be spoofed, especially over UDP: a returned slot is merely a candidate.
    """

    def __init__(self, peers: Iterable[tuple[str, str]]):
        if isinstance(peers, (str, bytes, dict)):
            raise ValueError('peers must be an iterable of address/slot pairs')
        mapping = {}
        slots = set()
        try:
            for entry in peers:
                if type(entry) not in (tuple, list) or len(entry) != 2:
                    raise ValueError('invalid peer entry')
                address, slot = entry
                address = _address(address)
                if (type(slot) is not str or not 1 <= len(slot) <= 64
                    or not slot.isascii() or not all(c.isalnum() or c in '_-' for c in slot)):
                    raise ValueError('invalid key slot label')
                if address in mapping:
                    raise ValueError('duplicate peer address')
                if slot in slots:
                    raise ValueError('duplicate key slot')
                slots.add(slot)
                mapping[address] = slot
                if len(mapping) > MAX_PEERS:
                    raise ValueError('too many peers')
        except (TypeError, OverflowError):
            raise ValueError('invalid peers') from None
        if not mapping:
            raise ValueError('at least one exact peer is required')
        self._peers = MappingProxyType(mapping)

    def inspect(self, peer_address: object, datagram: object) -> Candidate | None:
        """Reject malformed/unconfigured input; otherwise return a candidate only.

        Callers must pass the socket's actual peer address, not a forwarded
        header or any packet attribute. No logs or packet data are returned.
        """
        try:
            address = _address(peer_address)
        except ValueError:
            return None
        slot = self._peers.get(address)
        if slot is None or type(datagram) is not bytes:
            return None
        size = len(datagram)
        if size < 20 or size > MAX_DATAGRAM:
            return None
        code = datagram[0]
        if code not in (1, 4) or int.from_bytes(datagram[2:4], 'big') != size:
            return None
        offset, count, seen = 20, 0, set()
        accounting_status = None
        while offset < size:
            if size - offset < 2:
                return None
            attribute_type, length = datagram[offset], datagram[offset + 1]
            if attribute_type == 0 or length < 2 or offset + length > size:
                return None
            count += 1
            if count > MAX_ATTRIBUTES:
                return None
            if attribute_type in SINGLETONS:
                if attribute_type in seen:
                    return None
                seen.add(attribute_type)
            if attribute_type == 80 and length != 18:  # Message-Authenticator
                return None
            if attribute_type == 4 and length != 6:  # NAS-IP-Address
                return None
            if attribute_type in (1, 32, 44) and length == 2:
                return None
            if attribute_type == 40:
                if length != 6:
                    return None
                accounting_status = int.from_bytes(datagram[offset + 2:offset + 6], 'big')
            offset += length
        # Deliberately stricter than baseline RADIUS: require a single MA on
        # both packet types. Presence is NOT cryptographic verification.
        if 80 not in seen:
            return None
        if code == 4 and accounting_status not in (1, 2, 3):
            return None
        if code == 1 and accounting_status is not None:
            return None
        return Candidate(slot, code)
