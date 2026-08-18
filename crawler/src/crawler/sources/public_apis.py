"""public-apis/public-apis - a markdown table of ~1400 free APIs.

Parsing markdown is brittle by nature, so every row is defensive: a broken
row is skipped, never crashes the run.
"""

from __future__ import annotations

import re

from ..models import ApiRecord
from .base import Source

ROW = re.compile(r"^\|\s*\[(?P<name>[^\]]+)\]\((?P<url>[^)]+)\)\s*\|(?P<rest>.*)\|\s*$")
HEADING = re.compile(r"^###\s+(?P<category>.+?)\s*$")


class PublicApisSource(Source):
    slug = "public-apis"
    name = "public-apis (GitHub)"
    url = "https://raw.githubusercontent.com/public-apis/public-apis/master/README.md"

    async def fetch(self, limit: int | None = None) -> list[ApiRecord]:
        response = await self.get(self.url)
        return self.parse(response.text, limit)

    def parse(self, markdown: str, limit: int | None = None) -> list[ApiRecord]:
        records: list[ApiRecord] = []
        category: str | None = None

        for line in markdown.splitlines():
            heading = HEADING.match(line.strip())
            if heading:
                category = heading.group("category")
                continue

            match = ROW.match(line.strip())
            if not match or category is None:
                continue

            cells = [cell.strip() for cell in match.group("rest").split("|")]
            if len(cells) < 4:
                continue

            description, auth, https, cors = cells[0], cells[1], cells[2], cells[3]

            if description.lower() in ("description", "---"):
                continue

            try:
                records.append(
                    ApiRecord(
                        name=match.group("name").strip(),
                        description=description or None,
                        category=category,
                        documentation_url=match.group("url").strip(),
                        website=match.group("url").strip(),
                        authentication_type=auth,
                        https=https.strip().lower() in ("yes", "true"),
                        cors=cors,
                        source="public-apis",
                        source_url=self.url,
                        tags=self._tags(category, description),
                    )
                )
            except Exception:
                continue

            if limit and len(records) >= limit:
                break

        return records

    @staticmethod
    def _tags(category: str, description: str) -> list[str]:
        words = re.findall(r"[a-zA-Z]{4,}", description.lower())
        return list(dict.fromkeys([category.lower(), *words[:5]]))
