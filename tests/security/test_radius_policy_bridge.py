import hashlib
import hmac
import sys
import unittest
from pathlib import Path
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "docker" / "radius-policy-bridge"))

import server


class PolicyBridgeTests(unittest.TestCase):
    def test_authorization_payload_accepts_only_configured_router_identity(self):
        payload = server.policy_payload("/authorize", {
            "router_id": "router-17",
            "peer_ip": "10.8.1.20",
            "nas_identifier": "chr-lab-17",
            "nas_session_id": "session-1",
            "voucher_code": "voucher-1",
            "unexpected": "ignored",
        })

        self.assertEqual(payload, {
            "authenticated_router_id": 17,
            "authenticated_peer_ip": "10.8.1.20",
            "nas_identifier": "chr-lab-17",
            "nas_session_id": "session-1",
            "voucher_code": "voucher-1",
        })

    def test_accounting_payload_is_allowlisted_and_rejects_bad_status(self):
        form = {
            "router_id": "router-17",
            "peer_ip": "10.8.1.20",
            "nas_identifier": "chr-lab-17",
            "nas_session_id": "session-1",
            "status_type": "Start",
            "input_octets": "123",
        }
        payload = server.policy_payload("/accounting", form)
        self.assertEqual(payload["status_type"], "Start")
        self.assertEqual(payload["input_octets"], "123")
        self.assertNotIn("voucher_code", payload)

        form["status_type"] = "Accounting-On"
        with self.assertRaises(ValueError):
            server.policy_payload("/accounting", form)

    def test_duplicate_fields_and_mismatched_router_shortname_are_rejected(self):
        with self.assertRaises(ValueError):
            server.parse_form(b"router_id=router-17&router_id=router-18")
        with self.assertRaises(ValueError):
            server.router_id("router-17-attacker")

    def test_policy_signature_matches_laravel_canonical_request(self):
        with patch("server.time.time", return_value=1770000000), patch(
            "server.secrets.token_hex", return_value="ab" * 32
        ):
            body, headers = server.signed_policy_request(
                "/internal/radius/v1/authorize",
                {"voucher_code": "voucher-1", "authenticated_router_id": 17},
                "cd" * 32,
            )

        canonical = "\n".join((
            "POST",
            "/internal/radius/v1/authorize",
            hashlib.sha256(body).hexdigest(),
            "1770000000",
            "ab" * 32,
        )).encode()
        expected = hmac.new(("cd" * 32).encode(), canonical, hashlib.sha256).hexdigest()
        self.assertEqual(headers["X-Radius-Service-Signature"], expected)

    def test_http_upstream_is_allowed_only_for_the_local_compose_lab(self):
        with patch.dict("server.os.environ", {
            "APP_ENV": "local",
            "RADIUS_POLICY_API_URL": "http://web",
            "RADIUS_BRIDGE_ALLOW_HTTP": "true",
        }, clear=False):
            self.assertEqual(server.upstream_url("/authorize"), "http://web/internal/radius/v1/authorize")

        with patch.dict("server.os.environ", {
            "APP_ENV": "production",
            "RADIUS_POLICY_API_URL": "http://web",
            "RADIUS_BRIDGE_ALLOW_HTTP": "true",
        }, clear=False):
            with self.assertRaises(RuntimeError):
                server.upstream_url("/authorize")


if __name__ == "__main__":
    unittest.main()
