"""APIs.guru - a directory that already points at OpenAPI specifications.

This is the highest value source for phase 3: every entry carries a spec URL,
so endpoints can be extracted without guessing.
"""

from __future__ import annotations

from urllib.parse import urlparse

from ..models import ApiRecord
from .base import Source


class ApisGuruSource(Source):
    slug = "apis-guru"
    name = "APIs.guru directory"
    url = "https://api.apis.guru/v2/list.json"

    async def fetch(self, limit: int | None = None) -> list[ApiRecord]:
        response = await self.get(self.url)
        return self.parse(response.json(), limit)

    def parse(self, payload: dict, limit: int | None = None) -> list[ApiRecord]:
        records: list[ApiRecord] = []

        for provider_key, entry in payload.items():
            versions = entry.get("versions", {})
            preferred = entry.get("preferred")
            version_data = versions.get(preferred) or next(iter(versions.values()), None)

            if not version_data:
                continue

            info = version_data.get("info", {})

            try:
                records.append(
                    ApiRecord(
                        name=info.get("title") or provider_key,
                        description=(info.get("description") or "").strip()[:500] or None,
                        category=self._category(info),
                        provider=info.get("x-providerName") or urlparse(f"//{provider_key}").path,
                        website=(info.get("contact") or {}).get("url"),
                        documentation_url=version_data.get("link") or version_data.get("swaggerUrl"),
                        openapi_url=version_data.get("swaggerUrl"),
                        has_openapi=True,
                        version=preferred,
                        license=(info.get("license") or {}).get("name"),
                        authentication_type="unknown",
                        https=True,
                        source="apis-guru",
                        source_url=self.url,
                        tags=self._tags(info),
                    )
                )
            except Exception:
                continue

            if limit and len(records) >= limit:
                break

        return records

    @staticmethod
    def _category(info: dict) -> str | None:
        categories = info.get("x-apisguru-categories") or []
        return categories[0].replace("_", " ").title() if categories else None

    @staticmethod
    def _tags(info: dict) -> list[str]:
        tags = [str(tag).lower() for tag in (info.get("x-apisguru-categories") or [])]
        if provider := info.get("x-providerName"):
            tags.append(str(provider).lower())
        return list(dict.fromkeys(tags))
