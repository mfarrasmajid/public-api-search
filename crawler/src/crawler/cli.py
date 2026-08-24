"""Crawler CLI.

    python -m crawler --help
    python -m crawler crawl public-apis --limit 200
    python -m crawler openapi --limit 20
    python -m crawler health --limit 50
    python -m crawler export data/apis.json

Everything writes to PostgreSQL only. Re-index afterwards from the backend:
    docker compose exec backend php artisan search:reindex
"""

from __future__ import annotations

import asyncio
import json
from pathlib import Path

import httpx
import typer
from rich.console import Console
from rich.table import Table

from . import db
from .config import settings
from .models import ApiRecord, CrawlStats
from .pipelines import health_checker, openapi_parser, portal_probe
from .sources import SOURCES, CkanSource
from .utils.http import DomainRateLimiter, RobotsCache, build_client

app = typer.Typer(help="Public API Discovery Engine - crawler", no_args_is_help=True)
console = Console()


@app.command("sources")
def list_sources() -> None:
    """List the sources this crawler knows about."""
    table = Table("slug", "name", "url")
    for slug, source in SOURCES.items():
        table.add_row(slug, source.name, source.url)
    console.print(table)


@app.command("probe")
def probe(
    portal_url: str = typer.Argument(..., help="Portal base URL, mis. https://data.go.id"),
    show_all: bool = typer.Option(
        False, "--all", help="Tampilkan semua percobaan, bukan hanya yang berhasil"
    ),
) -> None:
    """Cari tahu platform & endpoint API sebuah portal open data.

    Dipakai ketika sebuah portal menjawab 404: portal open data berpindah
    platform, dan menebak path hanya menghasilkan kegagalan senyap.
    """
    results = asyncio.run(_probe(portal_url))

    table = Table("status", "platform", "endpoint", "kunci JSON")
    for r in results:
        if not show_all and not r.usable:
            continue
        status = str(r.status or r.error or "-")
        style = "green" if r.confirmed else ("yellow" if r.usable else "dim")
        table.add_row(
            f"[{style}]{status}[/{style}]",
            r.platform,
            r.url.replace(portal_url.rstrip("/"), ""),
            ", ".join(r.matched_markers or r.sample_keys)[:60],
        )

    console.print(table)
    console.print(f"\n[bold]{portal_probe.summarise(results)}[/bold]")

    confirmed = [r for r in results if r.confirmed]
    if confirmed and confirmed[0].platform.startswith("CKAN"):
        console.print(
            f"\nJalankan: [bold]python -m crawler crawl data-go-id "
            f"--portal-url {portal_url.rstrip('/')} --limit 50 --dry-run[/bold]"
        )
    elif not confirmed:
        console.print(
            "\n[yellow]Belum ada parser untuk portal ini.[/yellow] Kirimkan output di atas "
            "(atau tambahkan --all) supaya parser baru bisa dibuat sesuai bentuk responsnya."
        )


@app.command("crawl")
def crawl(
    source: str = typer.Argument(..., help=f"One of: {', '.join(SOURCES)}"),
    limit: int = typer.Option(0, help="Stop after N records (0 = no limit)"),
    dry_run: bool = typer.Option(False, "--dry-run", help="Parse and print, do not write to the database"),
    portal_url: str = typer.Option(
        "", "--portal-url", help="Override the portal base URL (CKAN sources only)"
    ),
) -> None:
    """Fetch a directory source and upsert the APIs into PostgreSQL."""
    if source not in SOURCES:
        raise typer.BadParameter(f"Unknown source '{source}'. Known: {', '.join(SOURCES)}")

    try:
        records = asyncio.run(_fetch(source, limit or None, portal_url or None))
    except httpx.HTTPStatusError as exc:
        _explain_http_failure(source, exc)
        raise typer.Exit(code=1) from None
    except PermissionError as exc:
        console.print(f"[red]robots.txt melarang akses:[/red] {exc}")
        raise typer.Exit(code=1) from None
    except httpx.RequestError as exc:
        console.print(f"[red]Tidak bisa menghubungi sumber:[/red] {type(exc).__name__}: {exc}")
        raise typer.Exit(code=1) from None

    console.print(f"[green]Parsed {len(records)} records from {source}[/green]")

    if not records:
        console.print(
            "[yellow]Tidak ada record yang lolos.[/yellow] Untuk portal CKAN ini wajar bila "
            "dataset-nya hanya berisi CSV/XLSX — hanya dataset dengan endpoint yang bisa "
            "dipanggil yang disimpan."
        )

    if dry_run:
        for record in records[:10]:
            console.print(f"  · {record.name} [dim]({record.category})[/dim]")
        console.print("[yellow]--dry-run: nothing written[/yellow]")
        return

    stats = CrawlStats(found=len(records))

    with db.connection() as conn:
        job_id = db.start_job(conn, source)
        try:
            for record in records:
                try:
                    _, created = db.upsert_api(conn, record)
                    stats.created += int(created)
                    stats.updated += int(not created)
                except Exception as exc:
                    stats.failed += 1
                    console.print(f"[red]failed:[/red] {record.name}: {exc}")
            conn.commit()
            db.finish_job(conn, job_id, stats)
        except Exception as exc:
            conn.rollback()
            db.finish_job(conn, job_id, stats, status="failed", error=str(exc))
            raise

    console.print(
        f"[green]Done.[/green] created={stats.created} updated={stats.updated} failed={stats.failed}"
    )
    console.print("[dim]Next: docker compose exec backend php artisan search:reindex[/dim]")


@app.command("openapi")
def openapi(
    limit: int = typer.Option(20, help="How many APIs to probe in this run"),
    only_missing: bool = typer.Option(True, help="Skip APIs that already have endpoints"),
) -> None:
    """Discover OpenAPI specs and extract endpoints (phase 3)."""
    asyncio.run(_openapi(limit, only_missing))


@app.command("health")
def health(limit: int = typer.Option(0, help="Batch size (default: HEALTHCHECK_BATCH_SIZE)")) -> None:
    """Check availability of the least recently checked APIs (phase 4)."""
    asyncio.run(_health(limit or settings.healthcheck_batch_size))


@app.command("export")
def export(
    output: Path = typer.Argument(Path("data/apis.json")),
    source: str = typer.Option("public-apis", help=f"One of: {', '.join(SOURCES)}"),
    limit: int = typer.Option(0),
) -> None:
    """Crawl to a JSON file instead of the database.

    Useful to review a source before trusting it, or to feed the backend
    directly: php artisan apis:import storage/apis.json
    """
    records = asyncio.run(_fetch(source, limit or None))
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps([r.model_dump() for r in records], indent=2, ensure_ascii=False))
    console.print(f"[green]Wrote {len(records)} records to {output}[/green]")


async def _probe(portal_url: str) -> list[portal_probe.ProbeResult]:
    limiter = DomainRateLimiter(settings.crawler_requests_per_minute)

    async with build_client() as client:
        return await portal_probe.probe_portal(client, portal_url, limiter)


async def _fetch(source: str, limit: int | None, portal_url: str | None = None) -> list[ApiRecord]:
    limiter = DomainRateLimiter(settings.crawler_requests_per_minute)
    robots = RobotsCache(settings.crawler_user_agent)

    async with build_client() as client:
        instance = SOURCES[source](client, limiter, robots)

        if portal_url:
            if not isinstance(instance, CkanSource):
                raise typer.BadParameter("--portal-url only applies to CKAN sources")
            instance.portal_url = portal_url.rstrip("/")

        return await instance.fetch(limit)


def _explain_http_failure(source: str, exc: httpx.HTTPStatusError) -> None:
    """Turn a raw HTTPStatusError into something the operator can act on."""
    status = exc.response.status_code
    url = str(exc.request.url)

    console.print(f"[red]Sumber '{source}' menjawab HTTP {status}[/red]")
    console.print(f"[dim]{url}[/dim]")

    if status == 404:
        portal = f"{exc.request.url.scheme}://{exc.request.url.host}"
        console.print(
            "\nPortal ini tidak menyediakan API di path tersebut - kemungkinan besar "
            "sudah pindah platform atau bukan CKAN.\n"
            "Cari endpoint yang sebenarnya:\n"
            f"  [bold]python -m crawler probe {portal}[/bold]\n"
            "lalu jalankan ulang dengan portal yang benar:\n"
            f"  [bold]python -m crawler crawl {source} --portal-url <URL> --dry-run[/bold]"
        )
    elif status in (401, 403):
        console.print("\nAkses ditolak. Portal mungkin memblokir bot atau butuh kredensial.")
    elif status == 429:
        console.print(
            "\nKena rate limit. Turunkan CRAWLER_REQUESTS_PER_MINUTE lalu coba lagi nanti."
        )
    elif status >= 500:
        console.print("\nError di sisi portal, bukan di crawler. Coba lagi nanti.")


async def _openapi(limit: int, only_missing: bool) -> None:
    query = """
        SELECT a.id, a.name, a.base_url, a.website, a.openapi_url
          FROM apis a
         WHERE COALESCE(a.openapi_url, a.base_url, a.website) IS NOT NULL
    """
    if only_missing:
        query += " AND a.has_openapi = FALSE"
    query += " ORDER BY a.quality_score DESC LIMIT %s"

    limiter = DomainRateLimiter(settings.crawler_requests_per_minute)
    parsed_count = 0

    with db.connection() as conn:
        rows = conn.execute(query, (limit,)).fetchall()

        async with build_client() as client:
            for row in rows:
                target = row["openapi_url"] or row["base_url"] or row["website"]
                await limiter.acquire(target)

                spec_url = row["openapi_url"]
                if not spec_url:
                    spec_url = await openapi_parser.discover_spec_url(client, target)

                if not spec_url:
                    console.print(f"[dim]no spec: {row['name']}[/dim]")
                    continue

                try:
                    response = await client.get(spec_url)
                    spec = openapi_parser.parse_spec(response.text)
                except Exception as exc:
                    console.print(f"[red]spec fetch failed:[/red] {row['name']}: {exc}")
                    continue

                if not spec:
                    continue

                endpoints = openapi_parser.extract_endpoints(spec)
                metadata = openapi_parser.spec_metadata(spec)

                db.replace_endpoints(conn, row["id"], endpoints)
                conn.execute(
                    """
                    UPDATE apis
                       SET openapi_url = %s,
                           base_url = COALESCE(base_url, %s),
                           version = COALESCE(version, %s)
                     WHERE id = %s
                    """,
                    (spec_url, metadata["base_url"], metadata["version"], row["id"]),
                )
                conn.commit()

                parsed_count += 1
                console.print(f"[green]{row['name']}[/green]: {len(endpoints)} endpoints from {spec_url}")

    console.print(f"[green]Parsed specs for {parsed_count} APIs.[/green]")
    console.print("[dim]Next: php artisan apis:score --reindex[/dim]")


async def _health(limit: int) -> None:
    semaphore = asyncio.Semaphore(settings.crawler_max_concurrency)
    summary: dict[str, int] = {}

    with db.connection() as conn:
        rows = db.apis_to_check(conn, limit)

        async with build_client() as client:

            async def run(row: dict) -> tuple[dict, dict]:
                async with semaphore:
                    url = row["base_url"] or row["website"] or row["documentation_url"]
                    return row, await health_checker.check_api(
                        client, url, settings.healthcheck_timeout_seconds
                    )

            for coro in asyncio.as_completed([run(row) for row in rows]):
                row, result = await coro
                db.record_health_check(conn, row["id"], result)
                summary[result["status"]] = summary.get(result["status"], 0) + 1
                console.print(
                    f"{row['name']}: [bold]{result['status']}[/bold] "
                    f"({result['http_status']}, {result['response_time_ms']} ms)"
                )

        conn.commit()

    console.print(f"[green]Checked {len(rows)} APIs:[/green] {summary}")
    console.print("[dim]Next: php artisan apis:score --reindex[/dim]")


if __name__ == "__main__":
    app()
