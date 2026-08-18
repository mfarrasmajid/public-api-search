"""Normalised shapes shared by every source.

A source's only job is to return `ApiRecord` objects. Everything downstream
(dedupe, persistence, indexing) works on this one schema, so adding a source
never touches the pipeline.
"""

from __future__ import annotations

import re
from typing import Literal

from pydantic import BaseModel, Field, field_validator, model_validator

AuthType = Literal["none", "apiKey", "OAuth", "bearer", "unknown"]
CorsType = Literal["yes", "no", "unknown"]


def slugify(value: str) -> str:
    value = re.sub(r"[^a-z0-9]+", "-", value.lower())
    return value.strip("-")[:180]


class ApiRecord(BaseModel):
    name: str
    slug: str = ""
    description: str | None = None
    category: str | None = None
    provider: str | None = None
    website: str | None = None
    documentation_url: str | None = None
    base_url: str | None = None
    authentication_type: AuthType = "unknown"
    https: bool = True
    cors: CorsType = "unknown"
    country: str | None = None
    status: str = "active"
    license: str | None = None
    version: str | None = None
    source: str = "crawler"
    source_url: str | None = None
    openapi_url: str | None = None
    has_openapi: bool = False
    tags: list[str] = Field(default_factory=list)

    @model_validator(mode="after")
    def default_slug(self) -> ApiRecord:
        # Field validators do not run on defaults, so derive the slug here.
        if not self.slug:
            self.slug = slugify(self.name)
        return self

    @field_validator("authentication_type", mode="before")
    @classmethod
    def normalise_auth(cls, value) -> str:
        """Directories spell auth a dozen ways; collapse to our four values."""
        text = str(value or "").strip().lower()
        if text in ("", "none", "no", "-"):
            return "none"
        if "oauth" in text:
            return "OAuth"
        if "bearer" in text or "jwt" in text:
            return "bearer"
        if "key" in text or "token" in text:
            return "apiKey"
        return "unknown"

    @field_validator("cors", mode="before")
    @classmethod
    def normalise_cors(cls, value) -> str:
        text = str(value or "").strip().lower()
        if text in ("yes", "true", "1"):
            return "yes"
        if text in ("no", "false", "0"):
            return "no"
        return "unknown"


class EndpointRecord(BaseModel):
    method: str = "GET"
    path: str
    description: str | None = None
    operation_id: str | None = None
    parameters: list[dict] = Field(default_factory=list)


class CrawlStats(BaseModel):
    found: int = 0
    created: int = 0
    updated: int = 0
    failed: int = 0
