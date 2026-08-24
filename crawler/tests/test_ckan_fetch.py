"""Pagination behaviour of CkanSource.fetch(), exercised over a mocked portal.

The live portals could not be reached from the development environment, so the
transport is faked here with respx while the request/response contract follows
the CKAN 3 action API.
"""

import httpx
import pytest
import respx

from crawler.sources.ckan import DataGoIdSource
from crawler.utils.http import DomainRateLimiter, RobotsCache

SEARCH = "https://data.go.id/api/3/action/package_search"


def dataset(index: int) -> dict:
    return {
        "name": f"dataset-{index}",
        "title": f"Dataset {index}",
        "notes": "contoh",
        "organization": {"title": "Kementerian Contoh"},
        "tags": [],
        "resources": [{"url": f"https://api.example.go.id/api/v1/d{index}", "format": "JSON"}],
    }


def page(datasets: list[dict], count: int) -> httpx.Response:
    return httpx.Response(200, json={"success": True, "result": {"count": count, "results": datasets}})


def build_source() -> DataGoIdSource:
    return DataGoIdSource(
        client=httpx.AsyncClient(),
        # No throttling in tests, otherwise each page would cost 3 seconds.
        limiter=DomainRateLimiter(requests_per_minute=100000),
        robots=RobotsCache("test-agent"),
    )


@pytest.mark.asyncio
@respx.mock
async def test_fetch_follows_pagination_until_count_is_reached():
    respx.get("https://data.go.id/robots.txt").mock(return_value=httpx.Response(404))
    route = respx.get(url__startswith=SEARCH)
    route.side_effect = [
        page([dataset(i) for i in range(100)], count=150),
        page([dataset(i) for i in range(100, 150)], count=150),
    ]

    records = await build_source().fetch()

    assert len(records) == 150
    assert route.call_count == 2
    assert "start=0" in str(route.calls[0].request.url)
    assert "start=100" in str(route.calls[1].request.url)


@pytest.mark.asyncio
@respx.mock
async def test_fetch_stops_at_the_requested_limit():
    respx.get("https://data.go.id/robots.txt").mock(return_value=httpx.Response(404))
    respx.get(url__startswith=SEARCH).mock(
        return_value=page([dataset(i) for i in range(100)], count=10_000)
    )

    records = await build_source().fetch(limit=30)

    assert len(records) == 30


@pytest.mark.asyncio
@respx.mock
async def test_fetch_stops_on_an_empty_page_instead_of_looping_forever():
    respx.get("https://data.go.id/robots.txt").mock(return_value=httpx.Response(404))
    route = respx.get(url__startswith=SEARCH)
    # A portal that reports a big count but returns nothing must not spin.
    route.side_effect = [page([], count=9999)]

    records = await build_source().fetch()

    assert records == []
    assert route.call_count == 1


@pytest.mark.asyncio
@respx.mock
async def test_robots_txt_is_honoured():
    respx.get("https://data.go.id/robots.txt").mock(
        return_value=httpx.Response(200, text="User-agent: *\nDisallow: /api/")
    )

    with pytest.raises(PermissionError):
        await build_source().fetch()


@pytest.mark.asyncio
async def test_a_source_without_a_client_cannot_fetch():
    with pytest.raises(RuntimeError, match="only parse"):
        await DataGoIdSource().fetch()
