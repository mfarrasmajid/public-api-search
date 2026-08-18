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

    def __init__(self, client: httpx.AsyncClient, limiter: DomainRateLimiter, robots: RobotsCache) -> None:
        self.client = client
        self.limiter = limiter
        self.robots = robots

    async def get(self, url: str) -> httpx.Response:
        """Every outbound request goes through here: robots + rate limit."""
        if not await self.robots.allowed(self.client, url):
            raise PermissionError(f"Blocked by robots.txt: {url}")

        await self.limiter.acquire(url)
        response = await self.client.get(url)
        response.raise_for_status()
        return response

    @abstractmethod
    async def fetch(self, limit: int | None = None) -> list[ApiRecord]:
        """Return normalised records. Must not write to the database."""
