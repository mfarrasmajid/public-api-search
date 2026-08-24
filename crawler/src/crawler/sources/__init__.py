from .apis_guru import ApisGuruSource
from .base import Source
from .ckan import CkanSource, DataGoIdSource, DataJakartaSource
from .public_apis import PublicApisSource

SOURCES: dict[str, type[Source]] = {
    "public-apis": PublicApisSource,
    "apis-guru": ApisGuruSource,
    "data-go-id": DataGoIdSource,
    "data-jakarta": DataJakartaSource,
}

__all__ = [
    "SOURCES",
    "Source",
    "PublicApisSource",
    "ApisGuruSource",
    "CkanSource",
    "DataGoIdSource",
    "DataJakartaSource",
]
