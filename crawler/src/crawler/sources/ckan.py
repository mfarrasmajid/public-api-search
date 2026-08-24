"""CKAN open data portals - used by most Indonesian government data portals.

Satu Data Indonesia (data.go.id) and the provincial portals all run CKAN, so
one parser covers the whole family: point it at a portal base URL and it talks
to the standard `/api/3/action/package_search` endpoint.

IMPORTANT - a CKAN portal catalogues *datasets*, not APIs. Most resources are
CSV or XLSX downloads, which have no place in a public **API** search engine.
So a dataset is only kept when it actually exposes a callable endpoint:

  1. a resource backed by the CKAN datastore -> the queryable
     /api/3/action/datastore_search?resource_id=... endpoint, or
  2. a resource whose format is an API format (JSON, GeoJSON, WMS, WFS, ...), or
  3. a resource whose URL clearly points at an API path.

Everything else is skipped. Expect a large drop from "datasets found" to
"APIs kept" - that is the filter working, not a bug.
"""

from __future__ import annotations

from urllib.parse import urljoin, urlparse

from ..models import ApiRecord, slugify
from .base import Source

#: Resource formats that represent something callable rather than a file download.
API_FORMATS = {
    "api",
    "json",
    "geojson",
    "jsonl",
    "wms",
    "wfs",
    "rest",
    "arcgis geoservices rest api",
    "esri rest",
    "sparql",
    "odata",
}

#: URL fragments that betray an API endpoint when the format field is unhelpful.
API_URL_HINTS = ("/api/", "/rest/", "/wms", "/wfs", "/arcgis/", "/services/", "format=json")

#: CKAN caps package_search at 1000 rows per call.
PAGE_SIZE = 100


class CkanSource(Source):
    """Base class for any CKAN portal. Subclasses only set the portal details."""

    #: Base URL of the portal, e.g. https://data.go.id
    portal_url: str = ""
    country: str | None = None
    default_category: str = "Government"

    @property
    def search_url(self) -> str:
        return urljoin(self.portal_url.rstrip("/") + "/", "api/3/action/package_search")

    async def fetch(self, limit: int | None = None) -> list[ApiRecord]:
        records: list[ApiRecord] = []
        start = 0

        while True:
            # Always request a full page: most datasets are filtered out, so the
            # limit is applied to the records actually kept, not to rows asked for.
            response = await self.get(f"{self.search_url}?rows={PAGE_SIZE}&start={start}")
            payload = response.json()

            page = self.parse(payload, limit=None)
            records.extend(page)

            result = payload.get("result") or {}
            total = int(result.get("count") or 0)
            fetched = len(result.get("results") or [])

            start += fetched

            if fetched == 0 or start >= total:
                break

            if limit and len(records) >= limit:
                break

        return records[:limit] if limit else records

    def parse(self, payload: dict, limit: int | None = None) -> list[ApiRecord]:
        """Turn one package_search response into ApiRecords."""
        if not payload.get("success", True):
            return []

        datasets = (payload.get("result") or {}).get("results") or []
        records: list[ApiRecord] = []

        for dataset in datasets:
            if not isinstance(dataset, dict):
                continue

            endpoint = self._pick_api_resource(dataset)
            if endpoint is None:
                continue  # dataset has no callable endpoint - skip it

            try:
                records.append(self._to_record(dataset, endpoint))
            except Exception:
                continue

            if limit and len(records) >= limit:
                break

        return records

    # ------------------------------------------------------------------ mapping

    def _to_record(self, dataset: dict, endpoint: dict) -> ApiRecord:
        dataset_name = dataset.get("name") or slugify(dataset.get("title", ""))
        dataset_page = urljoin(self.portal_url.rstrip("/") + "/", f"dataset/{dataset_name}")
        url = endpoint["url"]

        return ApiRecord(
            name=(dataset.get("title") or dataset_name).strip(),
            # Prefix with the portal so two portals publishing "Jumlah Penduduk"
            # do not overwrite each other during upsert.
            slug=slugify(f"{self.slug}-{dataset_name}"),
            description=self._description(dataset),
            category=self._category(dataset),
            provider=(dataset.get("organization") or {}).get("title") or self.portal_name,
            website=dataset_page,
            documentation_url=dataset_page,
            base_url=url,
            # Open data portals serve public read endpoints without a key.
            authentication_type="none",
            https=urlparse(url).scheme == "https",
            cors="unknown",
            country=self.country,
            license=dataset.get("license_title") or None,
            source=self.slug,
            source_url=self.search_url,
            tags=self._tags(dataset, endpoint),
        )

    def _description(self, dataset: dict) -> str | None:
        notes = (dataset.get("notes") or "").strip()
        if notes:
            return notes[:500]

        # No abstract: build something searchable from the title and publisher
        # rather than leaving the document without any text to match on.
        org = (dataset.get("organization") or {}).get("title")
        title = dataset.get("title") or ""
        return f"Open data API dari {org or self.portal_name}: {title}".strip()[:500] or None

    def _category(self, dataset: dict) -> str:
        groups = dataset.get("groups") or []
        if groups and isinstance(groups[0], dict):
            label = groups[0].get("display_name") or groups[0].get("title") or groups[0].get("name")
            if label:
                return str(label).strip()

        return self.default_category

    def _tags(self, dataset: dict, endpoint: dict) -> list[str]:
        tags = [
            str(t.get("display_name") or t.get("name") or "").lower()
            for t in (dataset.get("tags") or [])
            if isinstance(t, dict)
        ]

        extra = ["open data", "pemerintah", "government"]
        if self.country:
            extra.append(self.country.lower())
        if org := (dataset.get("organization") or {}).get("title"):
            extra.append(str(org).lower())
        if fmt := endpoint.get("format"):
            extra.append(str(fmt).lower())

        return [t for t in dict.fromkeys([*tags, *extra]) if t][:12]

    # ------------------------------------------------------------- resource pick

    def _pick_api_resource(self, dataset: dict) -> dict | None:
        """Return the resource that represents a callable API, or None."""
        resources = [r for r in (dataset.get("resources") or []) if isinstance(r, dict) and r.get("url")]

        # 1. Datastore-backed resources are genuinely queryable over HTTP.
        for resource in resources:
            if resource.get("datastore_active") and resource.get("id"):
                datastore_url = urljoin(
                    self.portal_url.rstrip("/") + "/",
                    f"api/3/action/datastore_search?resource_id={resource['id']}",
                )
                return {"url": datastore_url, "format": "API (datastore)"}

        # 2. A resource explicitly published in an API format.
        for resource in resources:
            if str(resource.get("format") or "").strip().lower() in API_FORMATS:
                return {"url": resource["url"], "format": resource.get("format")}

        # 3. Last resort: the URL itself looks like an endpoint.
        for resource in resources:
            url = str(resource["url"]).lower()
            if any(hint in url for hint in API_URL_HINTS):
                return {"url": resource["url"], "format": resource.get("format") or "API"}

        return None


class DataGoIdSource(CkanSource):
    """Satu Data Indonesia - the national open data portal."""

    slug = "data-go-id"
    name = "Satu Data Indonesia (data.go.id)"
    portal_url = "https://data.go.id"
    url = "https://data.go.id/api/3/action/package_search"
    country = "Indonesia"


class DataJakartaSource(CkanSource):
    """Open data portal of the DKI Jakarta provincial government."""

    slug = "data-jakarta"
    name = "Open Data Jakarta (data.jakarta.go.id)"
    portal_url = "https://data.jakarta.go.id"
    url = "https://data.jakarta.go.id/api/3/action/package_search"
    country = "Indonesia"
