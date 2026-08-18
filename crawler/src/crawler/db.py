"""PostgreSQL access.

The crawler owns writes to `apis`, `api_endpoints`, `api_health_checks` and
`crawl_jobs`. It deliberately does NOT touch OpenSearch: indexing stays a
Laravel responsibility (`php artisan search:reindex`), so there is exactly one
component that knows the document shape.
"""

from __future__ import annotations

import json
from contextlib import contextmanager
from datetime import UTC, datetime

import psycopg
from psycopg.rows import dict_row

from .config import settings
from .models import ApiRecord, EndpointRecord


@contextmanager
def connection():
    with psycopg.connect(settings.database_url, row_factory=dict_row) as conn:
        yield conn


def _lookup_or_create(conn, table: str, name: str, extra: dict | None = None) -> int:
    from .models import slugify

    slug = slugify(name)
    row = conn.execute(f"SELECT id FROM {table} WHERE slug = %s", (slug,)).fetchone()
    if row:
        return row["id"]

    columns = {"name": name, "slug": slug, **(extra or {})}
    placeholders = ", ".join(["%s"] * (len(columns) + 2))
    keys = ", ".join([*columns.keys(), "created_at", "updated_at"])
    now = datetime.now(UTC)

    row = conn.execute(
        f"INSERT INTO {table} ({keys}) VALUES ({placeholders}) RETURNING id",
        (*columns.values(), now, now),
    ).fetchone()
    return row["id"]


def upsert_api(conn, record: ApiRecord) -> tuple[int, bool]:
    """Insert or update one API. Returns (api_id, created)."""
    now = datetime.now(UTC)

    category_id = _lookup_or_create(conn, "categories", record.category) if record.category else None
    provider_id = (
        _lookup_or_create(conn, "providers", record.provider, {"country": record.country})
        if record.provider
        else None
    )

    existing = conn.execute("SELECT id FROM apis WHERE slug = %s", (record.slug,)).fetchone()

    payload = {
        "name": record.name,
        "description": record.description,
        "category_id": category_id,
        "provider_id": provider_id,
        "website": record.website,
        "documentation_url": record.documentation_url,
        "base_url": record.base_url,
        "authentication_type": record.authentication_type,
        "https": record.https,
        "cors": record.cors,
        "country": record.country,
        "status": record.status,
        "license": record.license,
        "version": record.version,
        "source": record.source,
        "source_url": record.source_url,
        "openapi_url": record.openapi_url,
        "has_openapi": record.has_openapi,
        "tags": json.dumps(record.tags),
        "last_seen_at": now,
        "updated_at": now,
    }

    if existing:
        assignments = ", ".join(f"{key} = %s" for key in payload)
        conn.execute(
            f"UPDATE apis SET {assignments} WHERE id = %s",
            (*payload.values(), existing["id"]),
        )
        return existing["id"], False

    payload |= {"slug": record.slug, "created_at": now, "quality_score": 0}
    keys = ", ".join(payload.keys())
    placeholders = ", ".join(["%s"] * len(payload))
    row = conn.execute(
        f"INSERT INTO apis ({keys}) VALUES ({placeholders}) RETURNING id",
        tuple(payload.values()),
    ).fetchone()
    return row["id"], True


def replace_endpoints(conn, api_id: int, endpoints: list[EndpointRecord]) -> int:
    """Endpoints are derived data - rewrite them wholesale on every parse."""
    now = datetime.now(UTC)
    conn.execute("DELETE FROM api_endpoints WHERE api_id = %s", (api_id,))

    for endpoint in endpoints:
        conn.execute(
            """
            INSERT INTO api_endpoints
                (api_id, method, path, description, operation_id, parameters, created_at, updated_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
            ON CONFLICT (api_id, method, path) DO NOTHING
            """,
            (
                api_id,
                endpoint.method.upper(),
                endpoint.path,
                endpoint.description,
                endpoint.operation_id,
                json.dumps(endpoint.parameters),
                now,
                now,
            ),
        )

    conn.execute("UPDATE apis SET has_openapi = TRUE WHERE id = %s", (api_id,))
    return len(endpoints)


def start_job(conn, source_slug: str | None) -> int:
    now = datetime.now(UTC)
    source_id = None

    if source_slug:
        row = conn.execute("SELECT id FROM crawl_sources WHERE slug = %s", (source_slug,)).fetchone()
        source_id = row["id"] if row else None

    row = conn.execute(
        """
        INSERT INTO crawl_jobs (crawl_source_id, status, started_at, created_at, updated_at)
        VALUES (%s, 'running', %s, %s, %s) RETURNING id
        """,
        (source_id, now, now, now),
    ).fetchone()
    conn.commit()
    return row["id"]


def finish_job(conn, job_id: int, stats, status: str = "success", error: str | None = None) -> None:
    now = datetime.now(UTC)
    conn.execute(
        """
        UPDATE crawl_jobs
           SET status = %s, finished_at = %s, updated_at = %s,
               items_found = %s, items_created = %s, items_updated = %s, items_failed = %s,
               error = %s
         WHERE id = %s
        """,
        (status, now, now, stats.found, stats.created, stats.updated, stats.failed, error, job_id),
    )
    conn.commit()


def record_health_check(conn, api_id: int, result: dict) -> None:
    now = datetime.now(UTC)
    conn.execute(
        """
        INSERT INTO api_health_checks
            (api_id, status, http_status, response_time_ms, dns_ok, tls_ok, error, checked_at,
             created_at, updated_at)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        """,
        (
            api_id,
            result["status"],
            result.get("http_status"),
            result.get("response_time_ms"),
            result.get("dns_ok", False),
            result.get("tls_ok", False),
            result.get("error"),
            now,
            now,
            now,
        ),
    )
    conn.execute("UPDATE apis SET last_checked_at = %s WHERE id = %s", (now, api_id))


def apis_to_check(conn, limit: int) -> list[dict]:
    """Least recently checked first, so a small batch still rotates fairly."""
    return conn.execute(
        """
        SELECT id, name, base_url, website, documentation_url
          FROM apis
         WHERE COALESCE(base_url, website, documentation_url) IS NOT NULL
           AND status <> 'dead'
         ORDER BY last_checked_at NULLS FIRST
         LIMIT %s
        """,
        (limit,),
    ).fetchall()
