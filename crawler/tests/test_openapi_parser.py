import json

from crawler.pipelines.openapi_parser import extract_endpoints, parse_spec, spec_metadata

SPEC = {
    "openapi": "3.0.0",
    "info": {"title": "Weather API", "version": "1.2.0", "license": {"name": "MIT"}},
    "servers": [{"url": "https://api.example.com/v1"}],
    "paths": {
        "/weather": {
            "parameters": [{"name": "lang", "in": "query", "required": False}],
            "get": {
                "summary": "Get current weather",
                "operationId": "getWeather",
                "parameters": [{"name": "city", "in": "query", "required": True}],
            },
        },
        "/forecast": {"get": {"description": "7 day forecast"}},
    },
}


def test_parses_json_and_yaml():
    assert parse_spec(json.dumps(SPEC)) is not None
    assert parse_spec("openapi: 3.0.0\npaths: {}\n") is not None
    assert parse_spec("just some html") is None
    assert parse_spec('{"not": "a spec"}') is None


def test_extracts_endpoints_with_merged_parameters():
    endpoints = extract_endpoints(SPEC)
    by_path = {endpoint.path: endpoint for endpoint in endpoints}

    assert len(endpoints) == 2
    assert by_path["/weather"].method == "GET"
    assert by_path["/weather"].description == "Get current weather"
    assert {p["name"] for p in by_path["/weather"].parameters} == {"lang", "city"}
    assert by_path["/forecast"].description == "7 day forecast"


def test_metadata_reads_server_url():
    metadata = spec_metadata(SPEC)
    assert metadata["base_url"] == "https://api.example.com/v1"
    assert metadata["version"] == "1.2.0"
    assert metadata["license"] == "MIT"


def test_swagger_2_host_is_converted_to_base_url():
    metadata = spec_metadata(
        {"swagger": "2.0", "host": "api.old.com", "basePath": "/v2", "schemes": ["https"]}
    )
    assert metadata["base_url"] == "https://api.old.com/v2"
