from .apis_guru import ApisGuruSource
from .base import Source
from .public_apis import PublicApisSource

SOURCES: dict[str, type[Source]] = {
    "public-apis": PublicApisSource,
    "apis-guru": ApisGuruSource,
}

__all__ = ["SOURCES", "Source", "PublicApisSource", "ApisGuruSource"]
