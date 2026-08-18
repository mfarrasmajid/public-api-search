"""Phase 4: is this API still alive?

Read-only by design: GET/HEAD only, never POST/PUT/PATCH/DELETE, because a
health check must not create side effects on someone else's service.
"""

from __future__ import annotations

import socket
import ssl
import time
from datetime import datetime
from urllib.parse import urlparse

import httpx

SAFE_METHODS = ("HEAD", "GET")


async def check_api(client: httpx.AsyncClient, url: str, timeout: int = 10) -> dict:
    parsed = urlparse(url)
    result: dict = {
        "status": "unknown",
        "http_status": None,
        "response_time_ms": None,
        "dns_ok": False,
        "tls_ok": False,
        "tls_expires_at": None,
        "error": None,
    }

    if not parsed.hostname:
        result["error"] = "invalid url"
        result["status"] = "unhealthy"
        return result

    # 1. DNS
    try:
        socket.gethostbyname(parsed.hostname)
        result["dns_ok"] = True
    except OSError as exc:
        result["error"] = f"dns: {exc}"
        result["status"] = "unhealthy"
        return result

    # 2. TLS (informational - an http-only API is not "down")
    if parsed.scheme == "https":
        tls = inspect_tls(parsed.hostname, parsed.port or 443, timeout)
        result["tls_ok"] = tls["ok"]
        result["tls_expires_at"] = tls["expires_at"]

    # 3. HTTP. HEAD first: cheapest for the target. Some hosts answer 405,
    #    so fall back to GET before calling it unhealthy.
    for method in SAFE_METHODS:
        started = time.monotonic()
        try:
            response = await client.request(method, url, timeout=timeout)
        except Exception as exc:
            result["error"] = f"{method.lower()}: {type(exc).__name__}"
            continue

        result["response_time_ms"] = int((time.monotonic() - started) * 1000)
        result["http_status"] = response.status_code

        if response.status_code == 405 and method == "HEAD":
            continue

        result["status"] = classify(response.status_code, result["response_time_ms"])
        result["error"] = None
        return result

    result["status"] = "unhealthy"
    return result


def classify(http_status: int, response_time_ms: int) -> str:
    if http_status >= 500:
        return "unhealthy"
    # 401/403 means the service answered - it just wants credentials.
    if http_status >= 400 and http_status not in (401, 403, 405, 429):
        return "degraded"
    if response_time_ms > 3000:
        return "degraded"
    return "healthy"


def inspect_tls(hostname: str, port: int, timeout: int) -> dict:
    try:
        context = ssl.create_default_context()
        with socket.create_connection((hostname, port), timeout=timeout) as sock:
            with context.wrap_socket(sock, server_hostname=hostname) as tls_sock:
                cert = tls_sock.getpeercert()

        expires_at = None
        if cert and cert.get("notAfter"):
            expires_at = datetime.strptime(cert["notAfter"], "%b %d %H:%M:%S %Y %Z")

        return {"ok": True, "expires_at": expires_at}
    except Exception:
        return {"ok": False, "expires_at": None}
