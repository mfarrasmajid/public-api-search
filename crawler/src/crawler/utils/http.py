"""Shared HTTP client: one user agent, one timeout policy, one rate limiter."""

from __future__ import annotations

import asyncio
import time
from collections import defaultdict
from urllib.parse import urlparse
from urllib.robotparser import RobotFileParser

import httpx

from ..config import settings


class DomainRateLimiter:
    """Per-domain token spacing.

    Being polite is not optional: a directory crawl that hammers one host is
    the fastest way to get the project blocked. See docs/security-and-legal.md.
    """

    def __init__(self, requests_per_minute: int) -> None:
        self._min_interval = 60.0 / max(requests_per_minute, 1)
        self._last_call: dict[str, float] = defaultdict(float)
        self._locks: dict[str, asyncio.Lock] = defaultdict(asyncio.Lock)

    async def acquire(self, url: str) -> None:
        domain = urlparse(url).netloc

        async with self._locks[domain]:
            elapsed = time.monotonic() - self._last_call[domain]
            if elapsed < self._min_interval:
                await asyncio.sleep(self._min_interval - elapsed)
            self._last_call[domain] = time.monotonic()


class RobotsCache:
    """Fetches and caches robots.txt per domain (fail-open on error)."""

    def __init__(self, user_agent: str) -> None:
        self._user_agent = user_agent
        self._cache: dict[str, RobotFileParser | None] = {}

    async def allowed(self, client: httpx.AsyncClient, url: str) -> bool:
        if not settings.crawler_respect_robots:
            return True

        parsed = urlparse(url)
        origin = f"{parsed.scheme}://{parsed.netloc}"

        if origin not in self._cache:
            self._cache[origin] = await self._load(client, origin)

        parser = self._cache[origin]
        if parser is None:
            return True  # no robots.txt reachable -> treat as allowed

        return parser.can_fetch(self._user_agent, url)

    async def _load(self, client: httpx.AsyncClient, origin: str) -> RobotFileParser | None:
        try:
            response = await client.get(f"{origin}/robots.txt", timeout=10)
            if response.status_code >= 400:
                return None
            parser = RobotFileParser()
            parser.parse(response.text.splitlines())
            return parser
        except Exception:
            return None


def build_client(**kwargs) -> httpx.AsyncClient:
    return httpx.AsyncClient(
        headers={"User-Agent": settings.crawler_user_agent, "Accept": "application/json, text/html"},
        timeout=settings.crawler_timeout_seconds,
        follow_redirects=True,
        **kwargs,
    )
