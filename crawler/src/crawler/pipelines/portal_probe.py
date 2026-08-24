"""Work out what software an open data portal runs, and where its API lives.

Open data portals move. data.go.id used to answer CKAN calls at
/api/3/action/package_search and now returns 404 there, so hardcoding a path
per portal is guesswork that breaks silently.

This probes a portal with the documented entry points of the common platforms
and reports which ones actually answer with usable JSON. Read-only GETs to
published API paths, rate limited like every other request in this crawler.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from urllib.parse import urljoin

import httpx

#: (platform, path, marker keys that confirm the platform)
CANDIDATES: list[tuple[str, str, tuple[str, ...]]] = [
    ("CKAN", "api/3/action/package_search?rows=1", ("result", "success")),
    ("CKAN (legacy)", "api/action/package_search?rows=1", ("result", "success")),
    ("CKAN", "api/3/action/package_list", ("result", "success")),
    ("DCAT / data.json", "data.json", ("dataset", "conformsTo")),
    ("DCAT / data.json", "api/data.json", ("dataset", "conformsTo")),
    ("OpenDataSoft", "api/explore/v2.1/catalog/datasets?limit=1", ("results", "total_count")),
    ("OpenDataSoft (v2)", "api/v2/catalog/datasets?rows=1", ("datasets", "total_count")),
    ("Socrata", "api/catalog/v1?limit=1", ("results", "resultSetSize")),
    ("ArcGIS Hub", "api/v3/datasets?page[size]=1", ("data", "meta")),
    ("Generic REST", "api/v1/datasets?limit=1", ()),
    ("Generic REST", "api/datasets?limit=1", ()),
    ("Generic REST", "api/dataset?limit=1", ()),
    ("Generic REST", "api/v1/dataset?limit=1", ()),
]


@dataclass
class ProbeResult:
    url: str
    platform: str
    status: int | None
    content_type: str = ""
    is_json: bool = False
    matched_markers: list[str] = field(default_factory=list)
    sample_keys: list[str] = field(default_factory=list)
    error: str | None = None

    @property
    def usable(self) -> bool:
        """A path worth pointing a parser at: JSON, 200, and structurally sane."""
        return self.status == 200 and self.is_json and (bool(self.matched_markers) or bool(self.sample_keys))

    @property
    def confirmed(self) -> bool:
        """Markers matched, so the platform is identified rather than guessed."""
        return self.usable and bool(self.matched_markers)


async def probe_portal(client: httpx.AsyncClient, portal_url: str, limiter=None) -> list[ProbeResult]:
    """Try every candidate entry point against one portal."""
    base = portal_url.rstrip("/") + "/"
    results: list[ProbeResult] = []

    for platform, path, markers in CANDIDATES:
        url = urljoin(base, path)

        if limiter is not None:
            await limiter.acquire(url)

        result = ProbeResult(url=url, platform=platform, status=None)

        try:
            response = await client.get(url)
        except Exception as exc:
            result.error = f"{type(exc).__name__}: {exc}"
            results.append(result)
            continue

        result.status = response.status_code
        result.content_type = response.headers.get("content-type", "").split(";")[0]

        if response.status_code != 200:
            results.append(result)
            continue

        try:
            payload = response.json()
        except Exception:
            result.error = "response is not JSON"
            results.append(result)
            continue

        result.is_json = True

        if isinstance(payload, dict):
            result.sample_keys = sorted(payload.keys())[:8]
            result.matched_markers = [m for m in markers if m in payload]
        elif isinstance(payload, list):
            result.sample_keys = ["<array>"]

        results.append(result)

    return results


def summarise(results: list[ProbeResult]) -> str:
    """One-line verdict for the CLI."""
    confirmed = [r for r in results if r.confirmed]
    if confirmed:
        best = confirmed[0]
        return f"Terdeteksi {best.platform}: {best.url}"

    usable = [r for r in results if r.usable]
    if usable:
        return f"Ada endpoint JSON tapi platform tidak dikenali: {usable[0].url}"

    return "Tidak ada endpoint API yang dikenali di portal ini."
