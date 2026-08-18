# API Contract

Base URL lokal: `http://localhost:8080/api`
Semua response berformat JSON. Belum ada autentikasi di POC.

Sumber kebenaran: `backend/routes/api.php`. Perbarui dokumen ini bersamaan dengan rute.

---

## `GET /api/search`

Pencarian utama.

### Query parameter

| Parameter | Tipe | Default | Keterangan |
|---|---|---|---|
| `q` | string (≤200) | `""` | query bebas; kosong = telusuri semua |
| `category` | string | — | nama kategori persis, mis. `Weather` |
| `auth` | enum | — | `none` \| `apiKey` \| `OAuth` \| `bearer` \| `unknown` |
| `https` | bool | — | `1` = hanya HTTPS |
| `cors` | enum | — | `yes` \| `no` \| `unknown` |
| `country` | string | — | mis. `Indonesia` |
| `openapi` | bool | — | `1` = hanya yang punya spec OpenAPI |
| `sort` | enum | `relevance` | `relevance` \| `quality` \| `name` \| `updated` |
| `page` | int 1–100 | 1 | |
| `per_page` | int 1–50 | 20 | |

### Response `200`

```json
{
  "query": "weather indonesia",
  "total": 27,
  "page": 1,
  "per_page": 20,
  "took_ms": 12,
  "driver": "opensearch",
  "filters": { "https": true },
  "facets": {
    "categories":     [{ "value": "Weather", "count": 8 }],
    "authentication": [{ "value": "none", "count": 5 }],
    "https":          [{ "value": "true", "count": 8 }],
    "country":        [{ "value": "Indonesia", "count": 3 }]
  },
  "results": [
    {
      "name": "BMKG Cuaca Wilayah Indonesia",
      "slug": "bmkg-cuaca-wilayah-indonesia",
      "score": 24.13,
      "description": "Official Indonesian weather forecast per province...",
      "highlight": "Official Indonesian <mark>weather</mark> forecast...",
      "category": "Weather",
      "provider": "BMKG",
      "tags": ["weather", "cuaca", "indonesia"],
      "authentication": "none",
      "https": true,
      "cors": "unknown",
      "country": "Indonesia",
      "documentation_url": "https://data.bmkg.go.id/prakiraan-cuaca/",
      "base_url": "https://api.bmkg.go.id/publik/prakiraan-cuaca",
      "has_openapi": false,
      "quality_score": 64,
      "health_status": "unknown",
      "response_time_ms": null
    }
  ]
}
```

Catatan:

- `driver` bernilai `opensearch` atau `database`. `database` berarti cluster sedang
  tidak terjangkau dan backend memakai fallback — hasil tetap keluar, tapi tanpa
  ranking relevansi (`score` bernilai `null`).
- `highlight` bisa `null` bila tidak ada potongan yang cocok.

### Error `422`

```json
{
  "message": "The selected auth is invalid.",
  "errors": { "auth": ["The selected auth is invalid."] }
}
```

---

## `GET /api/apis`

Menelusuri katalog tanpa search engine (langsung dari PostgreSQL, terurut quality score).

| Parameter | Keterangan |
|---|---|
| `category` | **slug** kategori (berbeda dari `/search` yang memakai nama) |
| `auth` | tipe autentikasi |
| `https` | boolean |
| `per_page` | maksimum 100 |

Response memakai paginasi standar Laravel: `{ "data": [...], "links": {...}, "meta": {...} }`.

---

## `GET /api/apis/{slug}`

Detail satu API, termasuk endpoint dan health check terakhir.

```json
{
  "data": {
    "id": 33,
    "name": "BMKG Gempa Bumi",
    "slug": "bmkg-gempa-bumi",
    "description": "Official Indonesian earthquake data from BMKG...",
    "category": "Government",
    "provider": "BMKG",
    "tags": ["gempa", "earthquake", "indonesia"],
    "website": "https://data.bmkg.go.id",
    "documentation_url": "https://data.bmkg.go.id/gempabumi/",
    "base_url": "https://data.bmkg.go.id/DataMKG/TEWS",
    "authentication": "none",
    "https": true,
    "cors": "unknown",
    "country": "Indonesia",
    "status": "active",
    "license": "unknown",
    "has_openapi": false,
    "openapi_url": null,
    "quality_score": 64,
    "health": { "status": "healthy", "http_status": 200, "response_time_ms": 143, "checked_at": "..." },
    "endpoints": [{ "method": "GET", "path": "/gempaterkini.json", "description": "...", "parameters": [] }],
    "last_checked_at": "2026-08-17T10:00:00+00:00",
    "updated_at": "2026-08-17T10:00:00+00:00"
  }
}
```

`404` bila slug tidak ditemukan.

---

## `GET /api/meta`

Opsi filter global (dipakai untuk mengisi UI sebelum pencarian pertama).

```json
{
  "categories": [{ "name": "Weather", "slug": "weather", "count": 8 }],
  "authentication": [{ "value": "none", "count": 68 }],
  "countries": [{ "value": "Global", "count": 95 }],
  "total_apis": 119
}
```

---

## `GET /api/health`

Kesiapan backend dan dependensinya. `200` bila semua sehat, `503` bila ada yang gagal.

```json
{
  "ok": true,
  "checks": {
    "database":   { "ok": true, "apis": 119 },
    "opensearch": { "ok": true, "status": "green", "indexed_documents": 119 }
  }
}
```

`GET /up` (bawaan Laravel) tersedia sebagai liveness probe sederhana.

---

## Rencana versioning

POC menaruh endpoint langsung di `/api/*`. Saat kontrak mulai dipakai pihak luar,
pindahkan ke `/api/v1/*` dengan route group — bentuk response tidak perlu berubah.
