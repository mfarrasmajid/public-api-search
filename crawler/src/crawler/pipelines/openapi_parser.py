"""Phase 3: find an OpenAPI spec and turn it into endpoint records.

Discovery only probes the conventional, publicly documented spec paths with
GET requests. It never enumerates or brute forces - see
docs/security-and-legal.md.
"""

from __future__ import annotations

import json
from urllib.parse import urljoin

import httpx
import yaml

from ..models import EndpointRecord

SPEC_PATHS = (
    "/openapi.json",
    "/openapi.yaml",
    "/swagger.json",
    "/swagger.yaml",
    "/api-docs",
    "/v1/openapi.json",
    "/.well-known/openapi.json",
)

HTTP_METHODS = ("get", "post", "put", "patch", "delete", "head", "options")


async def discover_spec_url(client: httpx.AsyncClient, base_url: str) -> str | None:
    """Probe the conventional spec locations. Returns the first that parses."""
    for path in SPEC_PATHS:
        candidate = urljoin(base_url.rstrip("/") + "/", path.lstrip("/"))
        try:
            response = await client.get(candidate)
        except Exception:
            continue

        if response.status_code != 200:
            continue

        if parse_spec(response.text) is not None:
            return candidate

    return None


def parse_spec(raw: str) -> dict | None:
    """Accept both JSON and YAML; return None when it is not a spec."""
    document: dict | None = None

    try:
        document = json.loads(raw)
    except Exception:
        try:
            document = yaml.safe_load(raw)
        except Exception:
            return None

    if not isinstance(document, dict):
        return None

    if "openapi" not in document and "swagger" not in document:
        return None

    return document


def extract_endpoints(spec: dict) -> list[EndpointRecord]:
    """paths -> flat endpoint list, the shape stored in api_endpoints."""
    endpoints: list[EndpointRecord] = []

    for path, operations in (spec.get("paths") or {}).items():
        if not isinstance(operations, dict):
            continue

        shared_params = operations.get("parameters") or []

        for method, operation in operations.items():
            if method.lower() not in HTTP_METHODS or not isinstance(operation, dict):
                continue

            description = operation.get("summary") or operation.get("description") or None

            endpoints.append(
                EndpointRecord(
                    method=method.upper(),
                    path=path,
                    description=(description or "").strip()[:500] or None,
                    operation_id=operation.get("operationId"),
                    parameters=[
                        {
                            "name": param.get("name"),
                            "in": param.get("in"),
                            "required": param.get("required", False),
                            "description": param.get("description"),
                        }
                        for param in [*shared_params, *(operation.get("parameters") or [])]
                        if isinstance(param, dict)
                    ],
                )
            )

    return endpoints


def spec_metadata(spec: dict) -> dict:
    """Metadata worth copying onto the API row itself."""
    info = spec.get("info") or {}
    servers = spec.get("servers") or []

    base_url = None
    if servers and isinstance(servers[0], dict):
        base_url = servers[0].get("url")
    elif spec.get("host"):  # swagger 2.0
        scheme = (spec.get("schemes") or ["https"])[0]
        base_url = f"{scheme}://{spec['host']}{spec.get('basePath', '')}"

    return {
        "title": info.get("title"),
        "description": (info.get("description") or "").strip()[:500] or None,
        "version": info.get("version"),
        "license": (info.get("license") or {}).get("name"),
        "base_url": base_url,
    }
