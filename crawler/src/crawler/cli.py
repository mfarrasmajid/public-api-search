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

import typer
from rich.console import Console
from rich.table import Table

from . import db
from .config import settings
from .models import ApiRecord, CrawlStats
from .pipelines import health_checker, openapi_parser
from .sources import SOURCES
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


@app.command("crawl")
def crawl(
    source: str = typer.Argument(..., help=f"One of: {', '.join(SOURCES)}"),
    limit: int = typer.Option(0, help="Stop after N records (0 = no limit)"),
    dry_run: bool = typer.Option(False, "--dry-run", help="Parse and print, do not write to the database"),
) -> None:
    """Fetch a directory source and upsert the APIs into PostgreSQL."""
    if source not in SOURCES:
        raise typer.BadParameter(f"Unknown source '{source}'. Known: {', '.join(SOURCES)}")

    records = asyncio.run(_fetch(source, limit or None))
    console.print(f"[green]Parsed {len(records)} records from {source}[/green]")

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


async def _fetch(source: str, limit: int | None) -> list[ApiRecord]:
    limiter = DomainRateLimiter(settings.crawler_requests_per_minute)
    robots = RobotsCache(settings.crawler_user_agent)

    async with build_client() as client:
        return await SOURCES[source](client, limiter, robots).fetch(limit)


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
