#!/usr/bin/env python3
"""Offline loopback evidence test for radclient, NOT a disconnect worker.

Usage: python3 radius/test_disconnect_transport.py /path/to/readable/freeradius/3.0
The test starts a disposable FreeRADIUS simulator and sends only synthetic
packets to 127.0.0.1. It never connects to the application's command queue.
"""
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import time
from pathlib import Path

# Synthetic test secret only. Never pass a real secret to this test.
SECRET = 'synthetic-test-secret-not-for-use-anywhere-1234567890'


def start_proxy(target, corruption):
    """Relay one packet on loopback; corrupt a real FreeRADIUS response."""
    relay = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    relay.bind(('127.0.0.1', 0))
    relay.settimeout(4)
    addr = relay.getsockname()
    errors = []

    def run():
        try:
            packet, peer = relay.recvfrom(4096)
            upstream = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            try:
                upstream.settimeout(2)
                upstream.connect(('127.0.0.1', target))
                upstream.send(packet)
                response = bytearray(upstream.recv(4096))
            finally:
                upstream.close()
            if len(response) < 20:
                raise RuntimeError('short simulator response')
            if corruption == 'authenticator':
                response[4] ^= 1
            elif corruption == 'identifier':
                response[1] ^= 1
            elif corruption == 'code':
                response[0] = 44  # CoA-ACK rather than Disconnect-ACK
            elif corruption == 'source_port':
                # Same reply, sent from a different UDP source port.
                with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as impostor:
                    impostor.bind(('127.0.0.1', 0))
                    impostor.sendto(response, peer)
                return
            else:
                raise RuntimeError('unsupported test case')
            relay.sendto(response, peer)
        except Exception as exc:
            errors.append(type(exc).__name__)
        finally:
            relay.close()

    thread = threading.Thread(target=run, daemon=True)
    thread.start()
    return addr[1], thread, errors


def main(stock):
    with tempfile.TemporaryDirectory(prefix='wifi-disconnect-simulator-') as directory:
        temp = Path(directory)
        db = temp / 'raddb'
        shutil.copytree(stock, db, symlinks=True)
        for name in ('sites-enabled', 'mods-enabled'):
            if (db / name).is_symlink() or not (db / name).is_dir():
                raise RuntimeError('stock configuration has an unsafe enabled directory')
            for entry in (db / name).iterdir():
                if entry.is_dir() and not entry.is_symlink():
                    shutil.rmtree(entry)
                else:
                    entry.unlink()
        (db / 'mods-enabled' / 'always').symlink_to('../mods-available/always')
        # Select a transient loopback port. The simulator fails to start if it
        # loses the bind race; it never falls back to an external address.
        probe = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
        probe.close()
        (db / 'sites-enabled' / 'coa-test').write_text('''
listen {
 type = coa
 ipaddr = 127.0.0.1
 port = %d
 virtual_server = sim
}
server sim {
 recv-coa {
  if (&Acct-Session-Id == "synthetic-nak") {
   reject
  }
  else {
   ok
  }
 }
 send-coa {
  ok
 }
}
''' % port)
        (db / 'clients.conf').write_text('client lab {\n ipaddr = 127.0.0.1\n secret = '+SECRET+'\n}\n')
        secret_file = temp / 'secret'
        secret_file.write_text(SECRET + '\n')
        secret_file.chmod(0o600)
        wrong_file = temp / 'wrong-secret'
        wrong_file.write_text('wrong-synthetic-secret\n')
        wrong_file.chmod(0o600)
        # Debug logs are necessary to detect readiness, and contain only
        # throwaway simulator data. They remain inside the temporary directory.
        with (temp / 'server.log').open('w+') as log:
            server = subprocess.Popen(['freeradius', '-X', '-d', str(db)], stdout=log, stderr=subprocess.STDOUT)
            try:
                for _ in range(100):
                    time.sleep(.1)
                    log.flush()
                    if server.poll() is not None:
                        raise RuntimeError('simulator failed to start')
                    if 'Ready to process requests' in (temp / 'server.log').read_text():
                        break
                else:
                    raise RuntimeError('simulator readiness timeout')

                def request(target, session, secret=secret_file):
                    # -S avoids the secret being exposed in process arguments.
                    return subprocess.run(
                        ['radclient', '-F', '-t', '1', '-r', '1', '-S', str(secret),
                         '127.0.0.1:%d' % target, 'disconnect'],
                        input='Acct-Session-Id = "%s"\n' % session,
                        text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                        timeout=4, check=False,
                    )

                def check(result, label, expected, marker=None):
                    if (result.returncode != expected or
                            (marker is not None and marker not in result.stdout) or
                            (expected != 0 and 'Received Disconnect-ACK' in result.stdout)):
                        raise RuntimeError('unexpected radclient result: ' + label)

                good = request(port, 'synthetic-ack')
                check(good, 'valid ACK', 0, 'Received Disconnect-ACK')
                nak = request(port, 'synthetic-nak')
                check(nak, 'valid NAK', 1, 'Received Disconnect-NAK')
                wrong = request(port, 'synthetic-ack', wrong_file)
                check(wrong, 'wrong secret or no reply', 1)
                for kind in ('authenticator', 'identifier', 'code', 'source_port'):
                    proxy_port, thread, errors = start_proxy(port, kind)
                    bad = request(proxy_port, 'synthetic-ack')
                    thread.join(5)
                    if thread.is_alive() or errors:
                        raise RuntimeError('proxy test failed')
                    check(bad, kind, 1)
                print('PASS: valid ACK; valid NAK; wrong-secret/no-reply; altered response authenticator, identifier, code, or UDP source port rejected.')
            finally:
                server.terminate()
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait()


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit('Usage: python3 radius/test_disconnect_transport.py /path/to/readable/freeradius/3.0')
    main(sys.argv[1])
