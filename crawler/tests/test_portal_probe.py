"""Portal probing, modelled on what data.go.id actually did: 404 on CKAN."""

import httpx
import pytest
import respx

from crawler.pipelines.portal_probe import probe_portal, summarise

PORTAL = "https://portal.example.go.id"


def build_client() -> httpx.AsyncClient:
    return httpx.AsyncClient()


@pytest.mark.asyncio
@respx.mock
async def test_detects_a_ckan_portal():
    # respx matches routes in registration order, so the specific one goes first.
    respx.get(url__startswith=f"{PORTAL}/api/3/action/package_search").mock(
        return_value=httpx.Response(200, json={"success": True, "result": {"count": 3, "results": []}})
    )
    respx.get(url__startswith=PORTAL).mock(return_value=httpx.Response(404))

    results = await probe_portal(build_client(), PORTAL)
    confirmed = [r for r in results if r.confirmed]

    assert confirmed
    assert confirmed[0].platform == "CKAN"
    assert confirmed[0].url == f"{PORTAL}/api/3/action/package_search?rows=1"
    assert "CKAN" in summarise(results)


@pytest.mark.asyncio
@respx.mock
async def test_detects_a_non_ckan_portal():
    """data.go.id's failure mode: CKAN paths 404, another platform answers."""
    respx.get(url__startswith=f"{PORTAL}/api/explore/v2.1/catalog/datasets").mock(
        return_value=httpx.Response(200, json={"total_count": 42, "results": []})
    )
    respx.get(url__startswith=PORTAL).mock(return_value=httpx.Response(404))

    results = await probe_portal(build_client(), PORTAL)
    confirmed = [r for r in results if r.confirmed]

    assert confirmed[0].platform == "OpenDataSoft"
    assert [r for r in results if r.platform == "CKAN" and r.status == 404]


@pytest.mark.asyncio
@respx.mock
async def test_html_response_is_not_mistaken_for_an_api():
    respx.get(url__startswith=PORTAL).mock(
        return_value=httpx.Response(200, text="<html><body>Portal</body></html>")
    )

    results = await probe_portal(build_client(), PORTAL)

    assert not any(r.usable for r in results)
    assert "Tidak ada endpoint API" in summarise(results)


@pytest.mark.asyncio
@respx.mock
async def test_json_without_known_markers_is_reported_as_unrecognised():
    respx.get(url__startswith=f"{PORTAL}/api/v1/datasets").mock(
        return_value=httpx.Response(200, json={"items": [], "page": 1})
    )
    respx.get(url__startswith=PORTAL).mock(return_value=httpx.Response(404))

    results = await probe_portal(build_client(), PORTAL)
    usable = [r for r in results if r.usable]

    assert usable and not usable[0].confirmed
    assert usable[0].sample_keys == ["items", "page"]
    assert "tidak dikenali" in summarise(results)


@pytest.mark.asyncio
@respx.mock
async def test_network_errors_are_captured_not_raised():
    respx.get(url__startswith=PORTAL).mock(side_effect=httpx.ConnectError("dns fail"))

    results = await probe_portal(build_client(), PORTAL)

    assert all(r.error and r.status is None for r in results)
    assert not any(r.usable for r in results)
