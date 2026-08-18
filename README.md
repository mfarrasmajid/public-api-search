# Public API Discovery Engine

> Cari **public API** dengan satu query bahasa natural — lalu lihat metadata, dokumentasi,
> tipe autentikasi, status, dan quality score-nya.

```
"API untuk mendapatkan data gempa Indonesia"  →  BMKG Gempa Bumi, USGS Earthquake, ...
"API gratis untuk cek kurs USD ke IDR"        →  Frankfurter, ExchangeRate-API, ...
"wether forecats"  (typo)                     →  Open-Meteo, OpenWeatherMap, ...
```

---

## 1. Ini aplikasi apa?

Sebuah **search engine khusus public API**. Bukan sekadar daftar link: setiap API disimpan
beserta metadata yang cukup kaya (deskripsi, kategori, auth, HTTPS/CORS, endpoint, health,
quality score) sehingga mesin pencari bisa memahami *fungsi* API tersebut, bukan hanya namanya.

**Untuk apa?**

| Masalah | Yang ditawarkan aplikasi ini |
|---|---|
| Cari API biasanya lewat Google → hasilnya blog post & listicle usang | Index terstruktur yang bisa di-refresh otomatis |
| Direktori public API hanya bisa di-browse per kategori | Satu search box, ranking berdasarkan relevansi |
| Tidak tahu API-nya masih hidup atau sudah mati | Health checker + quality score |
| Query bahasa Indonesia tidak menemukan dokumen berbahasa Inggris | Synonym mapping sekarang, semantic search (pgvector) di fase berikutnya |

**Status saat ini: Phase 1 (Search POC) — lengkap dan bisa dijalankan.**
Dataset awal berisi **119 public API nyata** (17 di antaranya API Indonesia: BMKG, kurs BI,
wilayah, kode pos, hari libur, KAI, IndoDax, Midtrans, Xendit, dll).

---

## 2. Arsitektur

```
                      Browser
                         |
                 http://localhost:8080
                         |
                   +-----------+
                   |   Nginx   |   satu origin: / → UI, /api → backend
                   +-----+-----+
                    /          \
        +----------+            +-------------+
        |  Frontend |            |   Laravel   |
        |  React    |            |   Backend   |
        |  (Vite)   |            +------+------+
        +-----------+                   |
                          +-------------+--------------+
                          |             |              |
                     PostgreSQL     OpenSearch       Redis
                   (source of truth) (index+ranking) (cache/queue)
                          ^
                          |
                    Python Crawler  ── public API directories
                    (profile: crawler)   OpenAPI spec  ·  health check
```

Prinsip yang dipegang (sesuai konteks project):

- **Local-first**: semua jalan di satu mesin dengan Docker Compose, tanpa layanan berbayar.
- **PostgreSQL = source of truth**, OpenSearch hanya turunan yang bisa dibangun ulang kapan saja.
- **Modular tapi belum distributed**: tidak ada Kubernetes, Kafka, ClickHouse, atau GPU.
- **Siap dipindah**: service dipisah secara logis, jadi migrasi ke VPS/cloud tidak perlu redesign.

---

## 3. Struktur repository

```
public-api-search/
├── backend/          Laravel 11 — REST + Search API, indexer, quality score   → backend/README.md
├── crawler/          Python 3.12 — discovery, OpenAPI parser, health checker  → crawler/README.md
├── frontend/         React 18 + Vite — UI pencarian                           → frontend/README.md
├── infrastructure/   Dockerfile, konfigurasi nginx/opensearch/postgres        → infrastructure/README.md
├── docs/             Arsitektur, data model, desain search, roadmap           → docs/README.md
├── docker-compose.yml
├── Makefile          Shortcut perintah yang sering dipakai
└── .env.example
```

Setiap folder punya README sendiri berisi **tools yang perlu disiapkan**, **yang perlu dilakukan**,
dan **perintah yang perlu dieksekusi**.

---

## 4. Tools yang perlu disiapkan

**Wajib** (hanya ini):

| Tool | Versi minimum | Cek |
|---|---|---|
| Docker Engine | 24+ | `docker --version` |
| Docker Compose plugin | v2 | `docker compose version` |
| Git | 2.x | `git --version` |

Semua bahasa dan library (PHP, Composer, Node, Python) berjalan **di dalam container** —
tidak perlu diinstal di host.

**Opsional, membantu saat development:**

| Tool | Kegunaan |
|---|---|
| `make` | Menjalankan shortcut di `Makefile` (kalau tidak ada, jalankan perintah `docker compose` langsung) |
| DBeaver / psql | Melihat isi PostgreSQL di `localhost:5432` |
| Postman / Insomnia / curl | Mencoba endpoint API |
| VS Code + ekstensi PHP Intelephense, ESLint, Python | Editing |

**Kebutuhan hardware** (OpenSearch adalah service paling berat):

```
CPU     : 4 core (8 lebih nyaman)
RAM     : 8 GB minimum, 16 GB disarankan
Disk    : ±10 GB untuk image + volume
```

Kalau RAM pas-pasan: turunkan `OPENSEARCH_HEAP=384m` di `.env`, dan jangan menyalakan
profile `tools`/`workers`.

---

## 5. Cara install

```bash
# 1. Clone
git clone https://github.com/mfarrasmajid/public-api-search.git
cd public-api-search

# 2. Siapkan environment (ubah port di sini kalau 8080/5432/9200 sudah terpakai)
cp .env.example .env

# 3. Jalankan seluruh stack
docker compose up -d          # atau: make up
```

Boot pertama memakan waktu **5–10 menit**: image di-pull, `composer install` dan `npm install`
dijalankan, lalu container backend otomatis melakukan:

1. `php artisan migrate` — membuat skema database
2. `php artisan db:seed` — memuat 119 API starter
3. `php artisan search:reindex` — membangun index OpenSearch

Pantau prosesnya:

```bash
docker compose logs -f backend      # tunggu sampai muncul "Backend ready."
docker compose ps                   # semua service harus "running"/"healthy"
```

### Verifikasi instalasi

```bash
# Kesehatan backend + dependensinya
curl http://localhost:8080/api/health

# Search pertama
curl "http://localhost:8080/api/search?q=weather" | head -c 500

# Status index
docker compose exec backend php artisan search:status
```

Lalu buka **<http://localhost:8080>** di browser.

---

## 6. Cara menggunakan

### Lewat UI

Buka <http://localhost:8080>, ketik query di search box. Yang bisa dilakukan:

- Mencari dengan bahasa Indonesia maupun Inggris (`cuaca`, `weather`, `kurs`, `exchange rate`)
- Typo tetap ketemu (`wether forecats` → Weather API)
- Filter kategori, authentication, HTTPS-only, punya OpenAPI, negara
- Urutkan berdasarkan relevansi / quality score / nama / terbaru
- Klik nama API untuk melihat detail: base URL, dokumentasi, endpoint, health, tags

### Lewat API

```bash
# Pencarian dasar
curl "http://localhost:8080/api/search?q=weather+indonesia"

# Dengan filter
curl "http://localhost:8080/api/search?q=stock&auth=none&https=1&sort=quality"

# Detail satu API
curl "http://localhost:8080/api/apis/bmkg-gempa-bumi"

# Opsi filter untuk UI
curl "http://localhost:8080/api/meta"
```

Contoh response `GET /api/search?q=weather+indonesia`:

```json
{
  "query": "weather indonesia",
  "total": 27,
  "page": 1,
  "took_ms": 12,
  "driver": "opensearch",
  "facets": { "categories": [{ "value": "Weather", "count": 8 }] },
  "results": [
    {
      "name": "BMKG Cuaca Wilayah Indonesia",
      "slug": "bmkg-cuaca-wilayah-indonesia",
      "score": 24.13,
      "category": "Weather",
      "authentication": "none",
      "https": true,
      "quality_score": 64,
      "documentation_url": "https://data.bmkg.go.id/prakiraan-cuaca/"
    }
  ]
}
```

Kontrak lengkap: [`docs/api-contract.md`](docs/api-contract.md).

### Perintah harian

```bash
make up              # start stack
make logs            # lihat log
make reindex         # index ulang setelah data berubah
make fresh           # reset database + seed + reindex (destruktif)
make test            # jalankan seluruh test suite
make down            # stop (data tetap ada)
make clean           # stop + hapus volume (semua data hilang)
```

Tanpa `make`, semua perintah setara ada di masing-masing README folder.

### Menambah data lebih banyak (Phase 2)

```bash
docker compose --profile crawler up -d
docker compose exec crawler python -m crawler crawl public-apis --limit 500
docker compose exec backend php artisan search:reindex
```

Detail: [`crawler/README.md`](crawler/README.md).

---

## 7. Port yang dipakai

| Service | URL | Catatan |
|---|---|---|
| UI + API (nginx) | http://localhost:8080 | satu-satunya port yang perlu dibuka |
| Vite dev server | http://localhost:5173 | akses langsung, untuk debugging |
| PostgreSQL | localhost:5432 | bind ke 127.0.0.1 saja |
| OpenSearch | http://localhost:9200 | bind ke 127.0.0.1 saja |
| Redis | localhost:6379 | bind ke 127.0.0.1 saja |
| OpenSearch Dashboards | http://localhost:5601 | hanya dengan `--profile tools` |

Ubah lewat `.env` bila bentrok, misalnya `APP_PORT=8090`.

---

## 8. Roadmap

| Fase | Isi | Status |
|---|---|---|
| 1 | Search POC: Docker Compose, Laravel, PostgreSQL, OpenSearch, UI | ✅ selesai |
| 2 | Python crawler + normalizer, target 1.000 API | 🟡 kode siap, tinggal dijalankan & diperluas |
| 3 | OpenAPI/Swagger discovery + endpoint extraction | 🟡 parser siap, perlu diuji lebih luas |
| 4 | Health checker: status, latency, TLS, riwayat | 🟡 checker siap, penjadwalan belum otomatis |
| 5 | Semantic search: pgvector + multilingual Sentence Transformers | ⬜ belum |
| 6 | Intelligent ranking: hybrid, reranking, query understanding | ⬜ belum |
| 7 | Production: VPS → (Kubernetes hanya jika benar-benar perlu) | ⬜ belum |

Rincian tiap fase beserta kriteria "selesai": [`docs/roadmap.md`](docs/roadmap.md).

---

## 9. Definition of Done Phase 1

| # | Kriteria | Status |
|---|---|---|
| 1 | Docker Compose menjalankan seluruh service | ✅ |
| 2 | PostgreSQL berisi ≥100 API | ✅ 119 API |
| 3 | Data bisa di-index ke OpenSearch | ✅ `search:reindex` |
| 4 | Laravel menyediakan endpoint search | ✅ `GET /api/search` |
| 5 | Query `weather` menghasilkan API weather di ranking atas | ✅ diuji di `OpenSearchRelevanceTest` |
| 6 | Typo sederhana tetap menghasilkan hasil relevan | ✅ `fuzziness: AUTO` |
| 7 | Filter kategori/auth/HTTPS | ✅ UI + API |
| 8 | Detail API bisa dibuka | ✅ drawer detail + `GET /api/apis/{slug}` |
| 9 | Data bisa di-update dan di-index ulang | ✅ alias switching tanpa downtime |
| 10 | Berjalan lokal tanpa layanan berbayar | ✅ seluruhnya open source |

---

## 10. Troubleshooting singkat

| Gejala | Penyebab & solusi |
|---|---|
| `port is already allocated` | Ubah `APP_PORT`/`DB_PORT_HOST`/`OPENSEARCH_PORT_HOST` di `.env`, lalu `docker compose up -d` |
| Container `opensearch` restart terus | RAM kurang atau `vm.max_map_count` rendah → `sudo sysctl -w vm.max_map_count=262144` |
| Search jalan tapi `"driver": "database"` | OpenSearch belum siap/kosong → `make index-status`, lalu `make reindex` |
| Hasil search kosong | Belum di-seed → `make seed && make reindex` |
| UI blank / 502 | Vite belum selesai `npm install` → `docker compose logs -f frontend` |

Daftar lengkap: [`docs/troubleshooting.md`](docs/troubleshooting.md).

---

## 11. Lisensi & etika

Kode ini berlisensi MIT (lihat [`LICENSE`](LICENSE)).

Metadata API berasal dari direktori publik yang terbuka. Crawler menghormati `robots.txt`,
menerapkan rate limit per domain, dan **tidak pernah** melakukan brute force endpoint,
credential guessing, atau request non-idempoten (POST/PUT/DELETE) ke API pihak ketiga.
Baca [`docs/security-and-legal.md`](docs/security-and-legal.md) sebelum menaikkan
rate limit atau menambah sumber baru.
