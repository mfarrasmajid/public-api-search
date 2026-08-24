"""Source contract: fetch remote data, yield normalised ApiRecords."""

from __future__ import annotations

from abc import ABC, abstractmethod

import httpx

from ..models import ApiRecord
from ..utils.http import DomainRateLimiter, RobotsCache


class Source(ABC):
    #: slug used on the CLI and in the crawl_sources table
    slug: str = ""
    name: str = ""
    url: str = ""

    def __init__(
        self,
        client: httpx.AsyncClient | None = None,
        limiter: DomainRateLimiter | None = None,
        robots: RobotsCache | None = None,
    ) -> None:
        # All three are optional so a source can be built for parsing alone -
        # `parse()` is pure and must not need HTTP plumbing to be tested.
        self.client = client
        self.limiter = limiter
        self.robots = robots

    async def get(self, url: str) -> httpx.Response:
        """Every outbound request goes through here: robots + rate limit."""
        if self.client is None or self.limiter is None or self.robots is None:
            raise RuntimeError(
                f"{type(self).__name__} was built without an HTTP client; "
                "it can only parse, not fetch."
            )

        if not await self.robots.allowed(self.client, url):
            raise PermissionError(f"Blocked by robots.txt: {url}")

        await self.limiter.acquire(url)
        response = await self.client.get(url)
        response.raise_for_status()
        return response

    @abstractmethod
    async def fetch(self, limit: int | None = None) -> list[ApiRecord]:
        """Return normalised records. Must not write to the database."""
