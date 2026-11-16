#!/usr/bin/env python3
import contextlib
import hashlib
import hmac
import importlib.util
import json
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('wifi_adapter', Path(__file__).with_name('adapter.py'))
a = importlib.util.module_from_spec(spec)
spec.loader.exec_module(a)
SECRET = 'test-service-secret-0123456789abcdef012345'

class Handler(BaseHTTPRequestHandler):
    response = {}
    status = 200
    requests = []
    nonces = set()
    def log_message(self, *args):
        pass
    def do_POST(self):
        raw = self.rfile.read(int(self.headers['Content-Length']))
        t, n = self.headers['X-Radius-Service-Timestamp'], self.headers['X-Radius-Service-Nonce']
        msg = '\n'.join(['POST', self.path, hashlib.sha256(raw).hexdigest(), t, n])
        signed = hmac.compare_digest(self.headers['X-Radius-Service-Signature'], hmac.new(SECRET.encode(), msg.encode(), hashlib.sha256).hexdigest())
        if not signed or n in type(self).nonces:
            self.send_response(401); self.end_headers(); return
        type(self).nonces.add(n)
        type(self).requests.append((self.path, json.loads(raw)))
        self.send_response(type(self).status); self.end_headers()
        self.wfile.write(json.dumps(type(self).response).encode())

class AdapterTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True); cls.thread.start()
        cls.config = {'base_url': 'http://127.0.0.1:%d' % cls.server.server_port, 'allow_loopback_http': True, 'service_secret': SECRET}
    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown(); cls.server.server_close(); cls.thread.join()
    def setUp(self):
        Handler.requests = []; Handler.nonces = set(); Handler.status = 200
        Handler.response = {'decision': 'accept', 'policy': {'session_timeout_seconds': 120, 'rate_limit_mbps': 10, 'device_limit': 1, 'data_limit_bytes': None}}
        self.env = {'NAS_IDENTIFIER': 'nas-lab', 'ACCT_SESSION_ID': 'sid-1', 'USER_NAME': 'VOUCHER-ABC', 'CALLING_STATION_ID': 'AA-BB-CC-DD-EE-FF'}
    def call_auth(self):
        import io
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            result = a.authorize(self.env, self.config)
        return result, out.getvalue()
    def test_signed_authorize_and_fresh_nonces(self):
        result, out = self.call_auth()
        self.assertEqual(result, 0)
        self.assertIn('Session-Timeout := 120', out)
        self.assertIn('Mikrotik-Rate-Limit := "10M/10M"', out)
        self.call_auth()
        self.assertEqual(len(Handler.nonces), 2)
        self.assertEqual(Handler.requests[0], ('/internal/radius/v1/authorize', {'nas_identifier': 'nas-lab', 'nas_session_id': 'sid-1', 'voucher_code': 'VOUCHER-ABC', 'mac_address': 'AA:BB:CC:DD:EE:FF'}))
    def test_reject_and_malformed_policy_fail_closed(self):
        for response in [{'decision':'reject'}, {'decision':'accept'}, {'decision':'accept','policy':{'session_timeout_seconds':True,'rate_limit_mbps':10,'device_limit':1,'data_limit_bytes':None}}, {'decision':'accept','policy':{'session_timeout_seconds':1,'rate_limit_mbps':10,'device_limit':1,'data_limit_bytes':42}}, {'decision':'accept','policy':{'session_timeout_seconds':1,'rate_limit_mbps':10,'device_limit':2,'data_limit_bytes':None}}]:
            Handler.response = response
            self.assertEqual(self.call_auth(), (1, ''))
    def test_invalid_and_missing_inputs_do_not_contact_api(self):
        for key, value in [('USER_NAME','X\nattack'), ('ACCT_SESSION_ID',''), ('NAS_IDENTIFIER','nas lab')]:
            with self.subTest(key=key):
                old = self.env[key]; self.env[key] = value
                with self.assertRaises(ValueError): self.call_auth()
                self.env[key] = old
        self.assertEqual(Handler.requests, [])
    def test_accounting_mapping_and_quarantine(self):
        self.env.update({'ACCT_STATUS_TYPE':'Start', 'TMP_INTEGER_0':'1700000000', 'ACCT_INPUT_OCTETS':'4294967295', 'ACCT_INPUT_GIGAWORDS':'1', 'CALLING_STATION_ID':'AA-BB-CC-DD-EE-FF'})
        Handler.response = {'accepted': True, 'quarantined': False, 'duplicate': False}
        self.assertEqual(a.accounting(self.env, self.config), 0)
        path, body = Handler.requests[-1]
        self.assertEqual(path, '/internal/radius/v1/accounting-events')
        self.assertEqual(body['event_time'], '2023-11-14T22:13:20+00:00')
        self.assertEqual((body['input_octets'],body['input_gigawords'],body['mac_address']), (4294967295,1,'AA:BB:CC:DD:EE:FF'))
        Handler.response = {'accepted': False, 'quarantined': True}
        self.assertEqual(a.accounting(self.env, self.config), 1)
        Handler.response = {'accepted': True}
        self.assertEqual(a.accounting(self.env, self.config), 1)
    def test_accounting_rejects_missing_timestamp_and_bad_counters(self):
        self.env.update({'ACCT_STATUS_TYPE':'Start'})
        with self.assertRaises(ValueError): a.accounting(self.env, self.config)
        self.env.update({'TMP_INTEGER_0':'1700000000', 'ACCT_INPUT_OCTETS':'4294967296'})
        with self.assertRaises(ValueError): a.accounting(self.env, self.config)
        self.assertEqual(Handler.requests, [])
    def test_insecure_or_malformed_origins_and_short_secrets_rejected(self):
        for changes in [{'base_url':'http://example.com'}, {'base_url':'http://127.0.0.1:1234/path'}, {'base_url':'http://user:pass@127.0.0.1'}, {'allow_loopback_http':False}, {'service_secret':'short'}]:
            with self.subTest(changes=changes):
                with self.assertRaises(ValueError): a.api(self.config | changes, 'authorize', {})
    def test_network_failure_is_not_success(self):
        with self.assertRaises(Exception): a.api(self.config | {'base_url':'http://127.0.0.1:1'}, 'authorize', {})

if __name__ == '__main__': unittest.main()
