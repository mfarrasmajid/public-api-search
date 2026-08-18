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

```bash
# Crawler berada di profile terpisah agar tidak ikut menyala saat POC search saja
docker compose --profile crawler up -d
docker compose exec crawler python -m crawler --help
```

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
docker compose exec crawler python -m crawler sources

# Uji parser dulu tanpa menyentuh database
docker compose exec crawler python -m crawler crawl public-apis --limit 20 --dry-run

# Crawl beneran (Phase 2 - target 1.000 API)
docker compose exec crawler python -m crawler crawl public-apis --limit 500
docker compose exec crawler python -m crawler crawl apis-guru --limit 300

# Wajib setelah crawl: perbarui index
docker compose exec backend php artisan apis:score --reindex

# Phase 3 - temukan spec OpenAPI dan ekstrak endpoint
docker compose exec crawler python -m crawler openapi --limit 20

# Phase 4 - health check batch (paling lama tidak dicek didahulukan)
docker compose exec crawler python -m crawler health --limit 50
docker compose exec backend php artisan apis:score --reindex

# Ekspor ke JSON, lalu impor lewat backend
docker compose exec crawler python -m crawler export data/apis.json --source public-apis
docker compose exec backend php artisan apis:import ../crawler/data/apis.json --reindex
```

Alur kerja lengkap satu siklus:

```
crawl  →  openapi  →  health  →  apis:score --reindex
  |         |            |               |
 apis   endpoints   health_checks    quality_score + index
```

---

## 4. Struktur folder

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
│   │   └── apis_guru.py          direktori APIs.guru (sudah membawa URL spec)
│   ├── pipelines/
│   │   ├── openapi_parser.py     discovery spec + ekstraksi endpoint (JSON & YAML)
│   │   └── health_checker.py     DNS, TLS, HTTP; hanya GET/HEAD
│   └── utils/http.py             client bersama, DomainRateLimiter, RobotsCache
└── tests/                        pytest (tanpa jaringan, tanpa database)
```

---

## 5. Menambah sumber baru

1. Buat `src/crawler/sources/nama_sumber.py`, turunkan dari `Source`.
2. Implementasikan `fetch()` yang mengembalikan `list[ApiRecord]` — **jangan** menulis ke database di sini.
3. Daftarkan di `src/crawler/sources/__init__.py` (`SOURCES`).
4. Tambahkan baris di `backend/database/seeders/CrawlSourceSeeder.php` beserta rate limit yang wajar.
5. Tambahkan test parser dengan fixture statis (lihat `tests/test_public_apis_source.py`).

Semua request keluar wajib lewat `self.get(url)` supaya robots.txt dan rate limit ikut berlaku.

---

## 6. Aturan main (penting)

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

## 7. Testing & lint

```bash
docker compose exec crawler pytest -q
docker compose exec crawler ruff check src tests
docker compose exec crawler ruff check --fix src tests
```

Test sengaja tidak menyentuh jaringan maupun database: parser diuji dengan fixture,
`classify()` health checker diuji sebagai fungsi murni.

---

## 8. Troubleshooting

| Gejala | Solusi |
|---|---|
| `connection refused` ke postgres | `docker compose ps postgres`; pastikan `DATABASE_URL` memakai host `postgres` (di dalam Docker) atau `127.0.0.1` (di host) |
| `PermissionError: Blocked by robots.txt` | Perilaku benar. Jangan matikan untuk situs orang lain; pilih sumber lain |
| Crawl lambat | Memang disengaja (rate limit). Naikkan `CRAWLER_REQUESTS_PER_MINUTE` seperlunya dan tetap wajar |
| Duplikat API | Dedupe memakai `slug` dari nama; entri berbeda dengan nama sama akan saling menimpa — perbaiki di parser bila perlu |
| Hasil crawl tidak muncul di search | Belum di-index: `docker compose exec backend php artisan search:reindex` |
| `no spec: X` saat `openapi` | Wajar — banyak API tidak mempublikasikan spec di path konvensional |
