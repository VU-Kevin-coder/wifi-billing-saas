import hashlib
import hmac
import ipaddress
import json
import os
import re
import secrets
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qs, urlparse
from urllib.request import HTTPRedirectHandler, Request, build_opener


MAX_BODY_SIZE = 16384
ROUTER_SHORTNAME = re.compile(r"^router-([1-9][0-9]{0,9})$")
ALLOWED_STATUS_TYPES = {"Start", "Interim-Update", "Stop"}

class NoRedirectHandler(HTTPRedirectHandler):
    def redirect_request(self, _request, _response, _code, _message, _headers, _new_url):
        return None


UPSTREAM_OPENER = build_opener(NoRedirectHandler())


def required_secret(name):
    value = os.environ.get(name, "")
    if not re.fullmatch(r"[0-9a-fA-F]{64,}", value):
        raise RuntimeError(f"{name} must be a hexadecimal secret of at least 64 characters")
    return value


def router_id(value):
    match = ROUTER_SHORTNAME.fullmatch(value)
    if not match:
        raise ValueError("Invalid configured RADIUS client identity")
    return int(match.group(1))


def safe_value(value, name, maximum, required=True):
    if (required and not value) or len(value) > maximum or any(ord(char) < 32 or ord(char) == 127 for char in value):
        raise ValueError(f"Invalid {name}")
    return value


def parse_form(body):
    parsed = parse_qs(body.decode("utf-8"), keep_blank_values=True, strict_parsing=True)
    if any(len(values) != 1 for values in parsed.values()):
        raise ValueError("Duplicate form field")
    return {key: values[0] for key, values in parsed.items()}


def policy_payload(path, form):
    try:
        router = router_id(form.get("router_id", ""))
        peer_ip = str(ipaddress.ip_address(safe_value(form.get("peer_ip", ""), "peer IP", 45)))
        nas_identifier = safe_value(form.get("nas_identifier", ""), "NAS identifier", 255)
        nas_session_id = safe_value(form.get("nas_session_id", ""), "NAS session id", 255, required=False)
    except ValueError as exception:
        raise ValueError("Invalid RADIUS request identity") from exception

    payload = {
        "authenticated_router_id": router,
        "authenticated_peer_ip": peer_ip,
        "nas_identifier": nas_identifier,
        "nas_session_id": nas_session_id,
    }

    if path == "/authorize":
        payload["voucher_code"] = safe_value(form.get("voucher_code", ""), "voucher code", 128)
        payload["service_type"] = safe_value(form.get("service_type", ""), "service type", 32, required=False)
        return payload

    status_type = safe_value(form.get("status_type", ""), "accounting status", 32)
    if status_type not in ALLOWED_STATUS_TYPES or not nas_session_id:
        raise ValueError("Invalid RADIUS accounting event")

    payload.update({
        "username": safe_value(form.get("username", ""), "username", 64, required=False),
        "service_type": safe_value(form.get("service_type", ""), "service type", 32, required=False),
        "status_type": status_type,
        "event_time": safe_value(form.get("event_time", ""), "event time", 64, required=False),
        "session_time": safe_value(form.get("session_time", ""), "session time", 16, required=False),
        "input_octets": safe_value(form.get("input_octets", ""), "input octets", 16, required=False),
        "input_gigawords": safe_value(form.get("input_gigawords", ""), "input gigawords", 16, required=False),
        "output_octets": safe_value(form.get("output_octets", ""), "output octets", 16, required=False),
        "output_gigawords": safe_value(form.get("output_gigawords", ""), "output gigawords", 16, required=False),
        "termination_cause": safe_value(form.get("termination_cause", ""), "termination cause", 64, required=False),
    })
    return payload


def signed_policy_request(path, payload, service_secret):
    body = json.dumps(payload, separators=(",", ":"), sort_keys=True).encode("utf-8")
    timestamp = str(int(time.time()))
    nonce = secrets.token_hex(32)
    canonical = "\n".join(("POST", path, hashlib.sha256(body).hexdigest(), timestamp, nonce)).encode("utf-8")
    signature = hmac.new(service_secret.encode("utf-8"), canonical, hashlib.sha256).hexdigest()
    return body, {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "X-Radius-Service-Timestamp": timestamp,
        "X-Radius-Service-Nonce": nonce,
        "X-Radius-Service-Signature": signature,
    }


def upstream_url(path):
    base = os.environ.get("RADIUS_POLICY_API_URL", "").rstrip("/")
    parsed = urlparse(base)
    app_env = os.environ.get("APP_ENV", "production").lower()
    if parsed.scheme == "https" and parsed.hostname:
        return base + "/internal/radius/v1" + path
    if (parsed.scheme == "http" and parsed.hostname in {"web", "localhost", "127.0.0.1"}
            and os.environ.get("RADIUS_BRIDGE_ALLOW_HTTP") == "true"
            and app_env in {"local", "testing"}):
        return base + "/internal/radius/v1" + path
    raise RuntimeError("The RADIUS policy API must use HTTPS outside the local lab")


class Handler(BaseHTTPRequestHandler):
    server_version = "RadiusPolicyBridge"

    def log_message(self, _format, *_args):
        return

    def send_body(self, status, body=b"", content_type="application/json"):
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        if body:
            self.wfile.write(body)

    def do_GET(self):
        if self.path == "/health":
            self.send_body(200, b"ok", "text/plain")
            return
        self.send_body(404, b"")

    def do_POST(self):
        if self.path not in {"/authorize", "/accounting"}:
            self.send_body(404, b"")
            return

        try:
            expected_token = required_secret("RADIUS_BRIDGE_TOKEN")
            supplied_token = self.headers.get("Authorization", "")
            if not hmac.compare_digest(supplied_token, f"Bearer {expected_token}"):
                self.send_body(401, b"")
                return

            content_type = self.headers.get("Content-Type", "").split(";", 1)[0].strip().lower()
            body_length = int(self.headers.get("Content-Length", "0"))
            if content_type != "application/x-www-form-urlencoded" or body_length < 0 or body_length > MAX_BODY_SIZE:
                self.send_body(400, b"")
                return

            form = parse_form(self.rfile.read(body_length))
            payload = policy_payload(self.path, form)
            endpoint = "/accounting-events" if self.path == "/accounting" else "/authorize"
            path = "/internal/radius/v1" + endpoint
            body, headers = signed_policy_request(path, payload, required_secret("RADIUS_POLICY_SERVICE_SECRET"))
            headers["Content-Length"] = str(len(body))
            request = Request(upstream_url(endpoint), data=body, headers=headers, method="POST")

            try:
                with UPSTREAM_OPENER.open(request, timeout=5) as response:
                    self.send_body(response.status, response.read(65536), response.headers.get("Content-Type", "application/json"))
            except HTTPError as response:
                self.send_body(response.code, response.read(65536), response.headers.get("Content-Type", "application/json"))
            except (URLError, TimeoutError):
                self.send_body(503, b"")
        except (ValueError, UnicodeDecodeError, KeyError):
            self.send_body(400, b"")
        except RuntimeError:
            self.send_body(503, b"")


def main():
    required_secret("RADIUS_BRIDGE_TOKEN")
    required_secret("RADIUS_POLICY_SERVICE_SECRET")
    upstream_url("/authorize")
    host = os.environ.get("RADIUS_BRIDGE_HOST", "0.0.0.0")
    port = int(os.environ.get("RADIUS_BRIDGE_PORT", "8080"))
    ThreadingHTTPServer((host, port), Handler).serve_forever()


if __name__ == "__main__":
    main()
