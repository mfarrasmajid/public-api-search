from crawler.sources.ckan import DataGoIdSource, DataJakartaSource

# Shape of a real CKAN /api/3/action/package_search response, trimmed to the
# fields the parser reads.
PAYLOAD = {
    "success": True,
    "result": {
        "count": 5,
        "results": [
            {
                # 1. datastore-backed -> becomes a datastore_search endpoint
                "name": "jumlah-penduduk-menurut-provinsi",
                "title": "Jumlah Penduduk Menurut Provinsi",
                "notes": "Data jumlah penduduk Indonesia menurut provinsi tahun 2020-2024.",
                "license_title": "Creative Commons Attribution",
                "organization": {"title": "Badan Pusat Statistik"},
                "groups": [{"display_name": "Kependudukan"}],
                "tags": [{"display_name": "penduduk"}, {"name": "demografi"}],
                "resources": [
                    {"url": "https://data.go.id/files/penduduk.csv", "format": "CSV"},
                    {
                        "id": "abc-123",
                        "url": "https://data.go.id/files/penduduk.csv",
                        "format": "CSV",
                        "datastore_active": True,
                    },
                ],
            },
            {
                # 2. explicit API format
                "name": "titik-banjir-dki",
                "title": "Titik Banjir DKI Jakarta",
                "notes": "Lokasi titik banjir terkini.",
                "organization": {"title": "Dinas SDA"},
                "tags": [],
                "resources": [{"url": "https://geo.example.go.id/wfs?service=WFS", "format": "WFS"}],
            },
            {
                # 3. format is unhelpful but the URL is clearly an endpoint
                "name": "harga-pangan",
                "title": "Harga Pangan Harian",
                "notes": "",
                "organization": {"title": "Dinas Perdagangan"},
                "tags": [],
                "resources": [{"url": "https://pangan.example.go.id/api/v1/harga", "format": ""}],
            },
            {
                # 4. spreadsheet only -> must be dropped
                "name": "laporan-keuangan-2023",
                "title": "Laporan Keuangan 2023",
                "notes": "Laporan tahunan.",
                "organization": {"title": "Inspektorat"},
                "tags": [],
                "resources": [
                    {"url": "https://data.go.id/files/laporan.xlsx", "format": "XLSX"},
                    {"url": "https://data.go.id/files/laporan.pdf", "format": "PDF"},
                ],
            },
            {
                # 5. no resources at all -> must be dropped
                "name": "dataset-kosong",
                "title": "Dataset Kosong",
                "resources": [],
            },
        ],
    },
}


def parse(source_cls=DataGoIdSource, payload=PAYLOAD):
    return source_cls().parse(payload)


def test_only_datasets_with_a_callable_endpoint_are_kept():
    records = parse()

    assert [r.name for r in records] == [
        "Jumlah Penduduk Menurut Provinsi",
        "Titik Banjir DKI Jakarta",
        "Harga Pangan Harian",
    ]


def test_datastore_resource_becomes_a_queryable_endpoint():
    record = parse()[0]

    assert record.base_url == (
        "https://data.go.id/api/3/action/datastore_search?resource_id=abc-123"
    )
    # ...and not the plain CSV download that sits on the same dataset.
    assert not record.base_url.endswith(".csv")


def test_api_format_and_url_hint_resources_are_detected():
    wfs, harga = parse()[1], parse()[2]

    assert wfs.base_url == "https://geo.example.go.id/wfs?service=WFS"
    assert harga.base_url == "https://pangan.example.go.id/api/v1/harga"


def test_metadata_is_mapped_from_the_dataset():
    record = parse()[0]

    assert record.provider == "Badan Pusat Statistik"
    assert record.category == "Kependudukan"
    assert record.country == "Indonesia"
    assert record.authentication_type == "none"
    assert record.https is True
    assert record.license == "Creative Commons Attribution"
    assert record.documentation_url == "https://data.go.id/dataset/jumlah-penduduk-menurut-provinsi"
    assert "penduduk" in record.tags
    assert "pemerintah" in record.tags


def test_category_falls_back_to_government_without_groups():
    record = parse()[1]
    assert record.category == "Government"


def test_missing_description_is_synthesised_so_the_document_is_searchable():
    record = parse()[2]

    assert record.description
    assert "Harga Pangan Harian" in record.description
    assert "Dinas Perdagangan" in record.description


def test_slug_is_namespaced_per_portal_to_avoid_cross_portal_collisions():
    national = parse(DataGoIdSource)[0]
    jakarta = parse(DataJakartaSource)[0]

    assert national.slug == "data-go-id-jumlah-penduduk-menurut-provinsi"
    assert jakarta.slug == "data-jakarta-jumlah-penduduk-menurut-provinsi"
    assert national.slug != jakarta.slug


def test_failed_ckan_response_yields_nothing():
    assert parse(DataGoIdSource, {"success": False, "error": {"message": "boom"}}) == []
    assert parse(DataGoIdSource, {"success": True, "result": {"count": 0, "results": []}}) == []


def test_search_url_is_built_from_the_portal():
    assert DataGoIdSource().search_url == "https://data.go.id/api/3/action/package_search"
    assert DataJakartaSource().search_url == (
        "https://data.jakarta.go.id/api/3/action/package_search"
    )
