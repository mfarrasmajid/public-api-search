from crawler.sources.public_apis import PublicApisSource

MARKDOWN = """
### Weather

API | Description | Auth | HTTPS | CORS
|---|---|---|---|---|
| [Open-Meteo](https://open-meteo.com) | Free weather forecast API | No | Yes | Yes |
| [OpenWeatherMap](https://openweathermap.org/api) | Weather data | `apiKey` | Yes | Unknown |

### Finance

API | Description | Auth | HTTPS | CORS
|---|---|---|---|---|
| [Frankfurter](https://frankfurter.dev) | Exchange rates published by ECB | No | Yes | Yes |
| broken row without link |
"""


def test_parses_rows_with_their_category():
    records = PublicApisSource.parse(PublicApisSource, MARKDOWN)  # type: ignore[arg-type]

    assert len(records) == 3
    assert records[0].name == "Open-Meteo"
    assert records[0].category == "Weather"
    assert records[2].category == "Finance"


def test_normalises_authentication_and_flags():
    records = PublicApisSource.parse(PublicApisSource, MARKDOWN)  # type: ignore[arg-type]
    by_name = {record.name: record for record in records}

    assert by_name["Open-Meteo"].authentication_type == "none"
    assert by_name["OpenWeatherMap"].authentication_type == "apiKey"
    assert by_name["Open-Meteo"].https is True
    assert by_name["OpenWeatherMap"].cors == "unknown"


def test_slug_is_derived_from_name():
    records = PublicApisSource.parse(PublicApisSource, MARKDOWN)  # type: ignore[arg-type]
    assert records[0].slug == "open-meteo"


def test_malformed_rows_are_skipped_not_fatal():
    records = PublicApisSource.parse(PublicApisSource, MARKDOWN)  # type: ignore[arg-type]
    assert all(record.name for record in records)
