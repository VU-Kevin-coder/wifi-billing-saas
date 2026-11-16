#!/usr/bin/env python3
"""Isolated FreeRADIUS 3 packet tests. Pass a readable copy of its stock raddb."""
import json
import hashlib
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import time
from http.server import ThreadingHTTPServer
from pathlib import Path
from test_adapter import Handler, SECRET

ROOT = Path(__file__).resolve().parent
SHARED = 'lab-only-unique-nas-secret-0123456789abcdef'

def port():
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM); s.bind(('127.0.0.1', 0))
    p = s.getsockname()[1]; s.close(); return p

def main(stock):
    with tempfile.TemporaryDirectory(prefix='wifi-radius-lab-') as temp:
        temp = Path(temp); db = temp / 'raddb'
        shutil.copytree(stock, db, symlinks=True)
        for directory in ('sites-enabled', 'mods-enabled'):
            for p in (db / directory).iterdir():
                if p.is_dir() and not p.is_symlink(): shutil.rmtree(p)
                else: p.unlink()
        for name in ('pap','chap','always'):
            (db / 'mods-enabled' / name).symlink_to('../mods-available/' + name)
        auth, acct = port(), port()
        while acct == auth: acct = port()
        site = (ROOT / 'freeradius/sites-enabled/wifi').read_text().replace('18120', str(auth)).replace('18130', str(acct))
        (db / 'sites-enabled/wifi').write_text(site)
        module = (ROOT / 'freeradius/mods-enabled/wifi').read_text().replace('/opt/wifi-radius/adapter.py', str(ROOT / 'adapter.py')).replace('/etc/wifi-radius/adapter.json', str(temp / 'config.json'))
        (db / 'mods-enabled/wifi').write_text(module)
        (db / 'clients.conf').write_text('client lab {\n ipaddr = 127.0.0.1\n secret = '+SHARED+'\n shortname = nas-lab\n require_message_authenticator = yes\n}\n')
        http = ThreadingHTTPServer(('127.0.0.1',0), Handler)
        th = threading.Thread(target=http.serve_forever, daemon=True); th.start()
        (temp / 'config.json').write_text(json.dumps({'base_url':'http://127.0.0.1:%d' % http.server_port,'allow_loopback_http':True,'service_secret':SECRET}))
        Handler.requests = []; Handler.nonces = set(); Handler.status = 200
        Handler.response = {'decision':'accept','policy':{'session_timeout_seconds':120,'rate_limit_mbps':10,'device_limit':1,'data_limit_bytes':None}}
        log = open(temp / 'server.log','w+')
        server = subprocess.Popen(['freeradius','-X','-d',str(db)], stdout=log, stderr=subprocess.STDOUT)
        def packet(attrs, kind, secret=SHARED, timeout='2'):
            p = subprocess.run(['radclient','-x','-t',timeout,'-r','1','127.0.0.1:'+str(auth if kind == 'auth' else acct),kind,secret],input=attrs,text=True,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,timeout=8)
            return p.stdout
        common = 'User-Name = "TESTVOUCHER"\nNAS-Identifier = "nas-lab"\nAcct-Session-Id = "sid-1"\nCalling-Station-Id = "AA-BB-CC-DD-EE-FF"\n'
        try:
            for _ in range(60):
                time.sleep(.1); log.flush()
                if server.poll() is not None: raise RuntimeError('FreeRADIUS failed to start: '+(temp/'server.log').read_text()[-3000:])
                if 'Ready to process requests' in (temp/'server.log').read_text(): break
            else: raise RuntimeError('server did not become ready')
            for method in ('User-Password', 'CHAP-Password'):
                ok = packet(common+method+' = "TESTVOUCHER"\nMessage-Authenticator = 0x00\n','auth')
                assert 'Received Access-Accept' in ok and 'Session-Timeout = 120' in ok and '10M/10M' in ok
                bad = packet(common+method+' = "WRONG"\nMessage-Authenticator = 0x00\n','auth')
                assert 'Received Access-Reject' in bad and 'Session-Timeout' not in bad
            assert 'Received Access-Reject' in packet(common.replace('nas-lab','nas-other')+'User-Password = "TESTVOUCHER"\nMessage-Authenticator = 0x00\n','auth')
            # radclient 3.2.7 adds Message-Authenticator automatically. Send a
            # raw Access-Request without it to test the server's requirement.
            authenticator = os.urandom(16)
            def attr(code, value):
                return bytes([code, len(value) + 2]) + value
            # PAP block encryption per RFC 2865; all required attributes present.
            padded = b'TESTVOUCHER'.ljust(16, b'\x00')
            digest = hashlib.md5(SHARED.encode() + authenticator).digest()
            encrypted = bytes(x ^ y for x, y in zip(padded, digest))
            attributes = (attr(1,b'TESTVOUCHER') + attr(2,encrypted) +
                          attr(32,b'nas-lab') + attr(44,b'sid-1'))
            length = 20 + len(attributes)
            raw = bytes([1, 7]) + length.to_bytes(2,'big') + authenticator + attributes
            sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            sock.settimeout(1)
            try:
                sock.sendto(raw, ('127.0.0.1', auth))
                try:
                    sock.recvfrom(4096)
                    raise AssertionError('packet without Message-Authenticator received a response')
                except socket.timeout:
                    pass
            finally:
                sock.close()
            assert 'No reply' in packet(common+'User-Password = "TESTVOUCHER"\nMessage-Authenticator = 0x00\n','auth',secret='wrong-secret',timeout='1')
            assert 'Received Access-Reject' in packet(common.replace('Acct-Session-Id = "sid-1"\nCalling-Station-Id = "AA-BB-CC-DD-EE-FF"\n','')+'User-Password = "TESTVOUCHER"\nMessage-Authenticator = 0x00\n','auth')
            assert 'Received Access-Reject' in packet(common+'User-Password = "TESTVOUCHER"\nMS-CHAP-Challenge = 0x1122334455667788\nMessage-Authenticator = 0x00\n','auth')
            assert 'Received Access-Reject' in packet(common.replace('Calling-Station-Id = "AA-BB-CC-DD-EE-FF"\n','')+'User-Password = "TESTVOUCHER"\nMessage-Authenticator = 0x00\n','auth')
            Handler.response = {'decision':'reject'}
            assert 'Received Access-Reject' in packet(common+'User-Password = "TESTVOUCHER"\nMessage-Authenticator = 0x00\n','auth')
            Handler.response = {'accepted':True, 'quarantined':False}
            for status in ('Start','Interim-Update','Stop'):
                result = packet(common+'Acct-Status-Type = '+status+'\nEvent-Timestamp = 1700000000\nAcct-Input-Octets = 12\n','acct')
                assert 'Received Accounting-Response' in result, result
                assert Handler.requests[-1][1]['status_type'] == status and Handler.requests[-1][1]['input_octets'] == 12
            Handler.response = {'accepted':False,'quarantined':True}
            assert 'No reply' in packet(common+'Acct-Status-Type = Start\nEvent-Timestamp = 1700000000\n','acct',timeout='1')
            assert 'No reply' in packet(common.replace('nas-lab','nas-other')+'Acct-Status-Type = Start\nEvent-Timestamp = 1700000000\n','acct',timeout='1')
            assert 'No reply' in packet(common+'Acct-Status-Type = Start\n','acct',timeout='1')
            Handler.status = 503
            assert 'Received Access-Reject' in packet(common+'User-Password = "TESTVOUCHER"\nMessage-Authenticator = 0x00\n','auth')
            assert 'No reply' in packet(common+'Acct-Status-Type = Start\nEvent-Timestamp = 1700000000\n','acct',timeout='1')
            print('PASS: PAP/CHAP correct and wrong passwords; NAS binding; missing session/Message-Authenticator; wrong shared secret; policy reject and HTTP failure; Start/Interim/Stop signed accounting; quarantine, HTTP failure and invalid accounting do not ACK.')
        finally:
            server.terminate()
            try: server.wait(timeout=5)
            except subprocess.TimeoutExpired: server.kill(); server.wait()
            log.close(); http.shutdown(); http.server_close(); th.join()

if __name__ == '__main__':
    if len(sys.argv) != 2: sys.exit('Usage: python3 radius/test_packets.py /path/to/readable/stock/freeradius/3.0')
    main(sys.argv[1])
