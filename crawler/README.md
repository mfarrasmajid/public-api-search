# Crawler — Python 3.12

Service terpisah yang bertugas **menemukan dan memperkaya** metadata public API.
Berjalan on-demand (bukan daemon), menulis **hanya ke PostgreSQL**.

## Tanggung jawab

| Fase | Kemampuan | Perintah |
|---|---|---|
| 2 | Menarik API dari direktori publik & menormalkannya | `crawl` |
| 3 | Menemukan spec OpenAPI/Swagger, mengekstrak endpoint | `openapi` |
| 4 | Health check: DNS, HTTP, TLS, latency | `health` |
| — | Ekspor hasil ke JSON tanpa menyentuh database | `export` |

> **Crawler tidak pernah menulis ke OpenSearch.** Indexing tetap milik Laravel supaya
> hanya ada satu komponen yang tahu bentuk dokumen. Setelah crawl, jalankan
> `php artisan search:reindex`.

---

## 1. Tools yang perlu disiapkan

Dengan Docker: cukup `docker compose --profile crawler up -d`.

Tanpa Docker (opsional):

| Tool | Versi |
|---|---|
| Python | 3.11+ |
| pip / venv | bawaan Python |
| PostgreSQL yang bisa diakses | dari `docker compose up -d postgres` |

Library inti: `httpx`, `BeautifulSoup`, `pydantic`, `psycopg`, `PyYAML`, `typer`, `rich`.
Sengaja pendek — Scrapy baru dipertimbangkan kalau memang butuh crawling berskala besar.

---

## 2. Yang perlu dilakukan

### Jalur Docker (disarankan)

> **Crawler tidak ikut `docker compose up -d`.** Ia berada di profile terpisah
> agar stack Phase 1 tetap ringan, jadi container-nya baru dibuat ketika profile
> `crawler` diaktifkan. Kalau `docker compose ps` tidak menampilkan
> `apidisc-crawler`, itu sebabnya — bukan error.

```bash
# 1. Nyalakan service-nya (build image pertama kali: beberapa menit)
docker compose --profile crawler up -d

# 2. Pastikan sudah jalan
docker compose ps crawler          # STATUS harus "Up"

# 3. Pakai
docker compose --profile crawler exec crawler python -m crawler --help
```

Agar tidak perlu mengetik `--profile crawler` setiap kali, aktifkan permanen
lewat `.env` di root repo:

```bash
COMPOSE_PROFILES=crawler
```

Setelah itu `docker compose up -d` dan `docker compose exec crawler ...`
sudah otomatis menyertakan crawler.

### Jalur lokal

```bash
cd crawler
python -m venv .venv && source .venv/bin/activate
pip install -r requirements-dev.txt
cp .env.example .env      # arahkan DATABASE_URL ke 127.0.0.1:5432
export PYTHONPATH=src
python -m crawler --help
```

---

## 3. Perintah yang perlu dieksekusi

```bash
# Lihat sumber yang tersedia
docker compose --profile crawler exec crawler python -m crawler sources

# Cari tahu platform & endpoint API sebuah portal (wajib untuk portal pemerintah)
docker compose --profile crawler exec crawler python -m crawler probe https://data.go.id
docker compose --profile crawler exec crawler python -m crawler probe https://data.jakarta.go.id --all

# Uji parser dulu tanpa menyentuh database
docker compose --profile crawler exec crawler python -m crawler crawl public-apis --limit 20 --dry-run

# Crawl beneran (Phase 2 - target 1.000 API)
docker compose --profile crawler exec crawler python -m crawler crawl public-apis --limit 500
docker compose --profile crawler exec crawler python -m crawler crawl apis-guru --limit 300

# Direktori pemerintah Indonesia (portal CKAN)
# Portal open data sering pindah platform - probe dulu, lalu arahkan dengan --portal-url
docker compose --profile crawler exec crawler python -m crawler crawl data-jakarta --limit 200
docker compose --profile crawler exec crawler python -m crawler crawl data-go-id \
    --portal-url https://portal-yang-benar.go.id --limit 200

# Wajib setelah crawl: perbarui index
docker compose exec backend php artisan apis:score --reindex

# Phase 3 - temukan spec OpenAPI dan ekstrak endpoint
docker compose --profile crawler exec crawler python -m crawler openapi --limit 20

# Phase 4 - health check batch (paling lama tidak dicek didahulukan)
docker compose --profile crawler exec crawler python -m crawler health --limit 50
docker compose exec backend php artisan apis:score --reindex

# Ekspor ke JSON, lalu impor lewat backend
docker compose --profile crawler exec crawler python -m crawler export data/apis.json --source public-apis
docker compose exec backend php artisan apis:import ../crawler/data/apis.json --reindex
```

Alur kerja lengkap satu siklus:

```
crawl  →  openapi  →  health  →  apis:score --reindex
  |         |            |               |
 apis   endpoints   health_checks    quality_score + index
```

---

## 4. Sumber yang tersedia

| Slug | Portal | Cakupan | Catatan |
|---|---|---|---|
| `public-apis` | github.com/public-apis/public-apis | global, ~1.400 API | satu file README di-parse lokal |
| `apis-guru` | apis.guru | global, ribuan API | tiap entri sudah membawa URL spec OpenAPI |
| `data-go-id` | Satu Data Indonesia | **API pemerintah Indonesia** | ⚠️ portal sudah pindah platform — lihat catatan di bawah |
| `data-jakarta` | Open Data Jakarta | **API Pemprov DKI Jakarta** | portal CKAN, dipaginasi, belum diverifikasi |

### Tentang portal CKAN (`data-go-id`, `data-jakarta`)

Portal open data pemerintah mengatalogkan **dataset**, bukan API — mayoritas isinya
berkas CSV/XLSX yang tidak layak masuk mesin pencari *API*. Karena itu `ckan.py`
hanya menyimpan dataset yang benar-benar punya endpoint yang bisa dipanggil:

1. resource yang ditopang **CKAN datastore** → dipetakan ke endpoint
   `/api/3/action/datastore_search?resource_id=...` yang memang bisa di-query;
2. resource dengan format API (`JSON`, `GeoJSON`, `WMS`, `WFS`, `OData`, …);
3. resource yang URL-nya jelas menunjuk endpoint (mengandung `/api/`, `/wfs`, dst).

Sisanya dibuang. **Wajar kalau dari 1.000 dataset hanya puluhan yang tersimpan** —
itu filternya bekerja, bukan error. Selalu jalankan `--dry-run` dulu untuk melihat
proporsinya:

```bash
docker compose --profile crawler exec crawler python -m crawler crawl data-go-id --limit 50 --dry-run
```

Slug disimpan dengan awalan nama portal (`data-go-id-<nama-dataset>`) supaya dua
portal yang menerbitkan dataset dengan judul sama tidak saling menimpa.

> ### ⚠️ data.go.id sudah tidak memakai CKAN
>
> Dikonfirmasi lewat percobaan nyata: `https://data.go.id/api/3/action/package_search`
> menjawab **HTTP 404**. Portal Satu Data Indonesia sudah pindah platform, jadi
> slug `data-go-id` **tidak akan langsung berfungsi** dengan URL bawaannya.
>
> Cari endpoint aslinya dengan perintah `probe`, lalu arahkan crawler ke sana:
>
> ```bash
> python -m crawler probe https://data.go.id
> python -m crawler crawl data-go-id --portal-url <URL_YANG_BENAR> --limit 50 --dry-run
> ```
>
> Kalau `probe` melaporkan platform yang belum punya parser (mis. OpenDataSoft
> atau Socrata), kirimkan tabel hasilnya — parser barunya perlu ditulis mengikuti
> bentuk respons portal tersebut. `data.jakarta.go.id` juga belum diverifikasi;
> jalankan `probe` untuknya sebelum crawl.

### Menambah portal CKAN lain

Portal pemerintah daerah lain umumnya juga CKAN. Cukup turunkan kelasnya:

```python
class DataBandungSource(CkanSource):
    slug = "data-bandung"
    name = "Open Data Bandung"
    portal_url = "https://data.bandung.go.id"
    url = "https://data.bandung.go.id/api/3/action/package_search"
    country = "Indonesia"
```

lalu daftarkan di `sources/__init__.py` dan `CrawlSourceSeeder.php`.

---

## 5. Struktur folder

```
crawler/
├── src/crawler/
│   ├── cli.py                    entry point Typer (semua perintah di atas)
│   ├── config.py                 Settings dari environment variable
│   ├── models.py                 ApiRecord, EndpointRecord — skema normalisasi bersama
│   ├── db.py                     upsert ke PostgreSQL, crawl_jobs, health checks
│   ├── sources/
│   │   ├── base.py               kontrak Source + gerbang robots/rate-limit
│   │   ├── public_apis.py        parser markdown public-apis/public-apis
│   │   ├── apis_guru.py          direktori APIs.guru (sudah membawa URL spec)
│   │   └── ckan.py               portal CKAN: data.go.id & data.jakarta.go.id
│   ├── pipelines/
│   │   ├── openapi_parser.py     discovery spec + ekstraksi endpoint (JSON & YAML)
│   │   └── health_checker.py     DNS, TLS, HTTP; hanya GET/HEAD
│   └── utils/http.py             client bersama, DomainRateLimiter, RobotsCache
└── tests/                        pytest (tanpa jaringan, tanpa database)
```

---

## 6. Menambah sumber baru

1. Buat `src/crawler/sources/nama_sumber.py`, turunkan dari `Source`.
2. Implementasikan `fetch()` yang mengembalikan `list[ApiRecord]` — **jangan** menulis ke database di sini.
3. Daftarkan di `src/crawler/sources/__init__.py` (`SOURCES`).
4. Tambahkan baris di `backend/database/seeders/CrawlSourceSeeder.php` beserta rate limit yang wajar.
5. Tambahkan test parser dengan fixture statis (lihat `tests/test_public_apis_source.py`).

Semua request keluar wajib lewat `self.get(url)` supaya robots.txt dan rate limit ikut berlaku.

---

## 7. Aturan main (penting)

| Boleh | Tidak boleh |
|---|---|
| GET/HEAD ke endpoint yang didokumentasikan | POST/PUT/PATCH/DELETE otomatis |
| Probe path spec konvensional (`/openapi.json`, `/swagger.json`, …) | Brute force / enumerasi endpoint |
| Menghormati `robots.txt` (default aktif) | Mematikan robots untuk situs pihak ketiga |
| Rate limit per domain (default 20 req/menit) | Scanning agresif atau paralel tanpa batas |
| Menyimpan atribusi `source` & `source_url` | Menghapus informasi lisensi |

Konfigurasi lewat environment: `CRAWLER_REQUESTS_PER_MINUTE`, `CRAWLER_RESPECT_ROBOTS`,
`CRAWLER_MAX_CONCURRENCY`, `CRAWLER_USER_AGENT`.
Baca [`../docs/security-and-legal.md`](../docs/security-and-legal.md) sebelum menaikkannya.

---

## 8. Testing & lint

```bash
docker compose --profile crawler exec crawler pytest -q
docker compose --profile crawler exec crawler ruff check src tests
docker compose --profile crawler exec crawler ruff check --fix src tests
```

Test sengaja tidak menyentuh jaringan maupun database: parser diuji dengan fixture,
`classify()` health checker diuji sebagai fungsi murni.

---

## 9. Troubleshooting

| Gejala | Solusi |
|---|---|
| `connection refused` ke postgres | `docker compose ps postgres`; pastikan `DATABASE_URL` memakai host `postgres` (di dalam Docker) atau `127.0.0.1` (di host) |
| `PermissionError: Blocked by robots.txt` | Perilaku benar. Jangan matikan untuk situs orang lain; pilih sumber lain |
| Crawl lambat | Memang disengaja (rate limit). Naikkan `CRAWLER_REQUESTS_PER_MINUTE` seperlunya dan tetap wajar |
| Duplikat API | Dedupe memakai `slug` dari nama; entri berbeda dengan nama sama akan saling menimpa — perbaiki di parser bila perlu |
| Hasil crawl tidak muncul di search | Belum di-index: `docker compose exec backend php artisan search:reindex` |
| `no spec: X` saat `openapi` | Wajar — banyak API tidak mempublikasikan spec di path konvensional |
