"""Offline synthetic tests: no sockets, service secrets, or databases."""
import random
import unittest

from security.radius_admission import AdmissionGuard, Candidate, MAX_ATTRIBUTES, MAX_DATAGRAM, MAX_PEERS, _address


def attr(kind, value):
    return bytes((kind, len(value) + 2)) + value


def packet(code=1, attrs=()):
    data = b''.join(attrs)
    return bytes((code, 7)) + (20 + len(data)).to_bytes(2, 'big') + bytes(16) + data


MA = attr(80, bytes(16))  # Merely present; deliberately NOT a valid authenticator.
STATUS_START = attr(40, (1).to_bytes(4, 'big'))


class AdmissionTests(unittest.TestCase):
    def setUp(self):
        self.guard = AdmissionGuard([('192.0.2.10', 'slot_a'), ('2001:db8::10', 'slot_b')])

    def inspect(self, raw, peer='192.0.2.10'):
        return self.guard.inspect(peer, raw)

    def test_valid_shape_only_produces_unverified_candidate(self):
        # Zero authenticator must never be described as verified: no crypto runs here.
        self.assertEqual(Candidate('slot_a', 1), self.inspect(packet(attrs=[MA])))
        self.assertEqual(Candidate('slot_b', 4), self.inspect(packet(4, [STATUS_START, MA]), '2001:db8:0:0:0:0:0:10'))

    def test_no_caller_supplied_claim_can_choose_slot(self):
        claims = [attr(32, b'router-b'), attr(4, bytes([192, 0, 2, 11])), attr(1, b'voucher-secret')]
        decision = self.inspect(packet(attrs=claims + [MA]))
        self.assertEqual(Candidate('slot_a', 1), decision)
        self.assertNotIn('voucher-secret', repr(decision))
        self.assertIsNone(self.inspect(packet(attrs=claims + [MA]), '192.0.2.11'))

    def test_empty_bad_or_ambiguous_configuration_is_rejected(self):
        bad = [[], {}, [('192.0.2.1/24', 'a')], [('0.0.0.0', 'a')], [('224.0.0.1', 'a')],
               [('::ffff:192.0.2.1', 'a')], [('fe80::1%eth0', 'a')],
               [('not-ip', 'a')], [('192.0.2.1', '')], [('192.0.2.1', 'a b')],
               [('192.0.2.1', 's'*65)], [('192.0.2.1', 'é')], [('192.0.2.1', 1)],
               [('2001:db8::1', 'a'), ('2001:db8:0:0:0:0:0:1', 'b')],
               [('192.0.2.1', 'a'), ('192.0.2.1', 'a')],
               [('192.0.2.1', 'a'), ('192.0.2.2', 'a')], [('192.0.2.1',)], None]
        for cfg in bad:
            with self.subTest(cfg=cfg), self.assertRaises(ValueError):
                AdmissionGuard(cfg)

    def test_peer_count_bounded(self):
        peers = ((f'10.{i // 65536}.{(i // 256) % 256}.{i % 256}', f's{i}') for i in range(MAX_PEERS + 1))
        with self.assertRaises(ValueError):
            AdmissionGuard(peers)

    def test_ipv6_link_local_rejected_in_configuration_and_transport(self):
        raw = packet(attrs=[MA])
        # Both ends of fe80::/10, compressed and expanded, with or without a scope.
        for peer in ('fe80::', 'fe80::1', 'fe80:0:0:0:0:0:0:1',
                     'febf:ffff:ffff:ffff:ffff:ffff:ffff:ffff', 'fe80::1%eth0'):
            with self.subTest(peer=peer):
                with self.assertRaises(ValueError):
                    AdmissionGuard([(peer, 'a')])
                with self.assertRaises(ValueError):
                    _address(peer)
                self.assertIsNone(self.inspect(raw, peer))
        self.assertEqual(Candidate('slot_b', 1), self.inspect(raw, '2001:db8::10'))

    def test_only_exact_transport_source_is_candidate(self):
        raw = packet(attrs=[MA])
        for peer in ['192.0.2.11', '::ffff:192.0.2.10', '192.0.2.10/32', 'fe80::1%eth0', '', None, 123, '0.0.0.0']:
            with self.subTest(peer=peer):
                self.assertIsNone(self.inspect(raw, peer))

    def test_only_immutable_bytes_accepted(self):
        raw = packet(attrs=[MA])
        for value in [bytearray(raw), memoryview(raw), None, 'text', [], 123]:
            self.assertIsNone(self.inspect(value))

    def test_lengths_exact_including_trailing_bytes(self):
        valid = packet(attrs=[MA])
        for raw in [b'', bytes(19), valid[:-1], valid + b'X', valid[:2] + b'\x00\x13' + valid[4:],
                    valid[:2] + b'\x10\x01' + valid[4:], bytes(MAX_DATAGRAM + 1)]:
            self.assertIsNone(self.inspect(raw))

    def test_unsupported_codes(self):
        for code in (0, 2, 3, 5, 11, 12, 40, 43, 255):
            self.assertIsNone(self.inspect(packet(code, [MA])))

    def test_malformed_tlvs(self):
        for suffix in [b'\x01', b'\x01\x00', b'\x01\x01', b'\x01\x03', b'\x00\x02', b'\x01\xff']:
            self.assertIsNone(self.inspect(packet(attrs=[MA, suffix])))

    def test_message_authenticator_required_exactly_once_and_length_18(self):
        for attrs in [[], [attr(80, b'')], [attr(80, bytes(15))], [attr(80, bytes(17))], [MA, MA]]:
            self.assertIsNone(self.inspect(packet(attrs=attrs)))
            self.assertIsNone(self.inspect(packet(4, [STATUS_START] + attrs)))

    def test_singleton_duplicates_and_invalid_empty_values(self):
        for kind in (1, 4, 32, 40, 44):
            value = bytes(4) if kind in (4, 40) else b'x'
            one = attr(kind, value)
            self.assertIsNone(self.inspect(packet(attrs=[MA, one, one])))
        for kind in (1, 32, 44):
            self.assertIsNone(self.inspect(packet(attrs=[MA, attr(kind, b'')])))
        self.assertIsNone(self.inspect(packet(attrs=[MA, attr(4, bytes(3))])))

    def test_accounting_only_known_status_and_correct_size(self):
        for status in [None, attr(40, b''), attr(40, bytes(3)), attr(40, bytes(4)), attr(40, (7).to_bytes(4, 'big'))]:
            self.assertIsNone(self.inspect(packet(4, [MA] + ([status] if status else []))))
        for n in (1, 2, 3):
            self.assertEqual(Candidate('slot_a', 4), self.inspect(packet(4, [MA, attr(40, n.to_bytes(4, 'big'))])))
        self.assertIsNone(self.inspect(packet(1, [MA, STATUS_START])))

    def test_attribute_count_is_bounded(self):
        unknown = attr(200, b'')
        self.assertIsNotNone(self.inspect(packet(attrs=[MA] + [unknown] * (MAX_ATTRIBUTES - 1))))
        self.assertIsNone(self.inspect(packet(attrs=[MA] + [unknown] * MAX_ATTRIBUTES)))

    def test_declared_maximum_datagram_accepted_only_with_valid_tlvs(self):
        # 20 + 18 + 15*255 + 233 = 4096 bytes, 17 attributes.
        raw = packet(attrs=[MA] + [attr(200, bytes(253))] * 15 + [attr(200, bytes(231))])
        self.assertEqual(MAX_DATAGRAM, len(raw))
        self.assertIsNotNone(self.inspect(raw))
        self.assertIsNone(self.inspect(raw + b'\x00'))

    def test_random_untrusted_bytes_never_raise(self):
        rng = random.Random(6789)
        for _ in range(5000):
            n = rng.randrange(0, 300)
            raw = rng.randbytes(n)
            self.assertIsNone(self.inspect(raw))

    def test_structured_mutations_of_valid_packets(self):
        # Synthetic valid *framing*, not valid cryptographic authenticators.
        for code, attrs in ((1, [attr(1, b'user'), MA]), (4, [STATUS_START, MA])):
            raw = packet(code, attrs)
            self.assertEqual(Candidate('slot_a', code), self.inspect(raw))
            mutations = {
                'unsupported code': bytes([255]) + raw[1:],
                'declared length too short': raw[:2] + (len(raw) - 1).to_bytes(2, 'big') + raw[4:],
                'declared length too long': raw[:2] + (len(raw) + 1).to_bytes(2, 'big') + raw[4:],
                'truncated datagram': raw[:-1],
                'trailing padding': raw + b'\x00',
                'zero first attribute type': raw[:20] + b'\x00' + raw[21:],
                'short first attribute length': raw[:21] + b'\x01' + raw[22:],
                'first attribute overruns packet': raw[:21] + b'\xff' + raw[22:],
                'duplicate authenticator': packet(code, attrs + [MA]),
            }
            for name, mutated in mutations.items():
                with self.subTest(code=code, mutation=name):
                    self.assertIsNone(self.inspect(mutated))
            # A candidate still means shape only; many mutations remain valid.
            for pos in range(len(raw)):
                for bit in range(8):
                    mutated = bytearray(raw)
                    mutated[pos] ^= 1 << bit
                    result = self.inspect(bytes(mutated))
                    self.assertIn(result, (None, Candidate('slot_a', code)))

    def test_configuration_is_copied_not_controlled_by_later_mutation(self):
        peers = [['192.0.2.1', 'a']]
        guard = AdmissionGuard(peers)
        peers[0][1] = 'b'
        peers.append(['192.0.2.2', 'c'])
        self.assertEqual(Candidate('a', 1), guard.inspect('192.0.2.1', packet(attrs=[MA])))
        self.assertIsNone(guard.inspect('192.0.2.2', packet(attrs=[MA])))


if __name__ == '__main__':
    unittest.main()
