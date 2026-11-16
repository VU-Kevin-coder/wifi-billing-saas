#!/usr/bin/python3
"""FreeRADIUS 3 rlm_exec bridge. No packet parsing or password checking here."""
import datetime
import hashlib
import hmac
import json
import os
import re
import secrets
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

MAX_BODY = 16384


def required(env, name, limit, pattern):
    value = env.get(name, '')
    if not value or len(value.encode()) > limit or not re.fullmatch(pattern, value):
        raise ValueError('invalid attribute')
    return value


def api(config, endpoint, body):
    base = config['base_url'].rstrip('/')
    url = urllib.parse.urlsplit(base)
    # HTTP is intentionally restricted to the isolated loopback lab.
    if url.username or url.password or url.query or url.fragment or url.path or not url.hostname:
        raise ValueError('invalid API origin')
    if url.scheme != 'https' and not (url.scheme == 'http' and url.hostname in ('127.0.0.1', '::1') and config.get('allow_loopback_http') is True):
        raise ValueError('HTTPS required')
    secret = config['service_secret']
    if not isinstance(secret, str) or len(secret.encode()) < 32:
        raise ValueError('invalid service secret')
    path = '/internal/radius/v1/' + endpoint
    payload = json.dumps(body, separators=(',', ':'), ensure_ascii=True).encode()
    timestamp = str(int(time.time()))
    nonce = secrets.token_hex(24)
    canonical = '\n'.join(['POST', path, hashlib.sha256(payload).hexdigest(), timestamp, nonce])
    signature = hmac.new(secret.encode(), canonical.encode(), hashlib.sha256).hexdigest()
    request = urllib.request.Request(base + path, data=payload, headers={
        'Content-Type': 'application/json', 'Accept': 'application/json',
        'X-Radius-Service-Timestamp': timestamp, 'X-Radius-Service-Nonce': nonce,
        'X-Radius-Service-Signature': signature,
    }, method='POST')
    # No environment proxy and no redirects: neither credentials nor vouchers may be forwarded.
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args, **kwargs):
            return None
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    with opener.open(request, timeout=2) as response:
        if response.status != 200:
            raise ValueError('bad status')
        data = response.read(MAX_BODY + 1)
        if len(data) > MAX_BODY:
            raise ValueError('oversized response')
        return json.loads(data)


def authorize(env, config):
    nas = required(env, 'NAS_IDENTIFIER', 255, r'[^\x00-\x20\x7f]+')
    session = required(env, 'ACCT_SESSION_ID', 255, r'[^\x00-\x1f\x7f]+')
    voucher = required(env, 'USER_NAME', 128, r'[A-Za-z0-9._-]+')
    mac = required({'mac': env.get('CALLING_STATION_ID', '').replace('-', ':').upper()}, 'mac', 17, r'(?:[0-9A-F]{2}:){5}[0-9A-F]{2}')
    result = api(config, 'authorize', {'nas_identifier': nas, 'nas_session_id': session, 'voucher_code': voucher, 'mac_address': mac})
    if not isinstance(result, dict) or result.get('decision') != 'accept' or not isinstance(result.get('policy'), dict):
        return 1
    policy = result['policy']
    timeout = policy.get('session_timeout_seconds')
    rate = policy.get('rate_limit_mbps')
    device_limit = policy.get('device_limit')
    # This lab admits only the supported single-device, uncapped profile.
    if (type(timeout) is not int or not 1 <= timeout <= 4294967295 or
        type(rate) is not int or not 1 <= rate <= 100000 or
        type(device_limit) is not int or device_limit != 1 or
        'data_limit_bytes' not in policy or policy['data_limit_bytes'] is not None):
        return 1
    print('Session-Timeout := %d' % timeout)
    print('Mikrotik-Rate-Limit := "%dM/%dM"' % (rate, rate))
    return 0


def accounting(env, config):
    body = {
        'nas_identifier': required(env, 'NAS_IDENTIFIER', 255, r'[^\x00-\x20\x7f]+'),
        'nas_session_id': required(env, 'ACCT_SESSION_ID', 255, r'[^\x00-\x1f\x7f]+'),
        'voucher_code': required(env, 'USER_NAME', 128, r'[A-Za-z0-9._-]+'),
        'status_type': required(env, 'ACCT_STATUS_TYPE', 20, r'Start|Interim-Update|Stop'),
    }
    stamp = required(env, 'TMP_INTEGER_0', 10, r'[0-9]+')
    n = int(stamp)
    if not 1 <= n <= 4294967295:
        raise ValueError('invalid event timestamp')
    body['event_time'] = datetime.datetime.fromtimestamp(n, datetime.timezone.utc).isoformat()
    for attr, name in [('ACCT_SESSION_TIME', 'session_time'), ('ACCT_INPUT_OCTETS', 'input_octets'),
                       ('ACCT_INPUT_GIGAWORDS', 'input_gigawords'), ('ACCT_OUTPUT_OCTETS', 'output_octets'),
                       ('ACCT_OUTPUT_GIGAWORDS', 'output_gigawords')]:
        if attr in env:
            val = required(env, attr, 10, r'[0-9]+')
            if int(val) > 4294967295:
                raise ValueError('invalid counter')
            body[name] = int(val)
    if 'FRAMED_IP_ADDRESS' in env:
        import ipaddress
        body['ip_address'] = str(ipaddress.ip_address(env['FRAMED_IP_ADDRESS']))
    mac = env.get('CALLING_STATION_ID', '').replace('-', ':').upper()
    body['mac_address'] = required({'mac': mac}, 'mac', 17, r'(?:[0-9A-F]{2}:){5}[0-9A-F]{2}')
    if 'ACCT_TERMINATE_CAUSE' in env:
        body['termination_cause'] = required(env, 'ACCT_TERMINATE_CAUSE', 64, r'[A-Za-z0-9._ -]+')
    result = api(config, 'accounting-events', body)
    return 0 if isinstance(result, dict) and result.get('accepted') is True and result.get('quarantined') is False else 1


def main():
    if len(sys.argv) != 3 or sys.argv[1] not in ('authorize', 'accounting'):
        return 2
    with open(sys.argv[2], encoding='utf-8') as f:
        config = json.load(f)
    env = {key.replace('-', '_').upper(): value for key, value in os.environ.items()}
    return (authorize if sys.argv[1] == 'authorize' else accounting)(env, config)


if __name__ == '__main__':
    try:
        sys.exit(main())
    except Exception:
        # No request contents, voucher codes, credentials, URLs, or exception messages in logs.
        sys.exit(2)
