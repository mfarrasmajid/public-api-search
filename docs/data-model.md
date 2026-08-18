# Data Model

PostgreSQL adalah source of truth. Semua tabel dibuat oleh migration di
`backend/database/migrations/`.

## Diagram relasi

```
providers ──┐
            ├──< apis >──── api_endpoints
categories ─┘      │
                   ├──< api_health_checks
                   │
crawl_sources ──< crawl_jobs

search_queries   (telemetry, berdiri sendiri)
```

## `apis` — tabel utama

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint | PK |
| `name` | string | nama tampilan |
| `slug` | string unik | **kunci natural** untuk dedupe & URL detail |
| `description` | text | dipakai untuk full-text search |
| `provider_id`, `category_id` | FK nullable | dinormalisasi agar bisa difilter & di-facet |
| `website`, `documentation_url`, `base_url` | string | `documentation_url` dipakai untuk quality score |
| `authentication_type` | string | `none` / `apiKey` / `OAuth` / `bearer` / `unknown` |
| `https` | bool | |
| `cors` | string | `yes` / `no` / `unknown` |
| `status` | string | `active` / `deprecated` / `dead` / `unknown`; `dead` dikecualikan dari hasil search |
| `version`, `license`, `country` | string | |
| `source`, `source_url` | string | atribusi asal data (`seed`, `public-apis`, `apis-guru`, `manual`) |
| `tags` | json | kata kunci tambahan, termasuk padanan bahasa Indonesia |
| `openapi_url`, `has_openapi` | string/bool | diisi oleh parser OpenAPI |
| `quality_score` | tinyint | 0–100, dihitung ulang oleh `apis:score` |
| `last_checked_at` | timestamp | health check terakhir |
| `last_seen_at` | timestamp | terakhir kali terlihat di sumbernya (sinyal freshness) |
| `indexed_at` | timestamp | terakhir masuk index |

**Aturan dedupe:** `slug` (diturunkan dari nama). Import memakai `updateOrCreate`,
sehingga menjalankan ulang seeder atau crawler tidak menghasilkan duplikat.

## `api_endpoints`

Diisi oleh parser OpenAPI (phase 3). Unik pada `(api_id, method, path)`.
Kolom `parameters`, `request_schema`, `response_schema`, `example` bertipe JSON —
skema OpenAPI terlalu bervariasi untuk dipaksa masuk kolom relasional.

Endpoint ikut diindeks sebagai `endpoints_text`, jadi query seperti
`"api endpoint forecast daily"` bisa menemukan API lewat isi endpoint-nya.

## `api_health_checks`

Satu baris per pengecekan (riwayat, bukan status terkini saja) supaya uptime bisa
dihitung di kemudian hari. Status terkini diambil lewat relasi `latestHealthCheck`.

| Kolom | Keterangan |
|---|---|
| `status` | `healthy` / `degraded` / `unhealthy` / `unknown` |
| `http_status` | kode HTTP terakhir |
| `response_time_ms` | latensi |
| `dns_ok`, `tls_ok`, `tls_expires_at` | hasil pengecekan DNS & sertifikat |
| `checked_at` | waktu pengecekan (diindeks bersama `api_id`) |

## `crawl_sources` & `crawl_jobs`

`crawl_sources` menyimpan daftar sumber beserta batas kesopanannya
(`rate_limit_per_minute`, `respect_robots_txt`). `crawl_jobs` mencatat setiap eksekusi:
jumlah item ditemukan/dibuat/diperbarui/gagal, durasi, dan error — berguna untuk
mengetahui sumber mana yang mulai rusak.

## `search_queries`

Telemetry ringan: query, filter, jumlah hasil, durasi, driver. Sinyal paling berguna
di POC adalah **query dengan 0 hasil** — itu daftar kerja untuk menyetel relevansi.

```sql
SELECT query, count(*) AS n
  FROM search_queries
 WHERE total_hits = 0
 GROUP BY query
 ORDER BY n DESC
 LIMIT 20;
```

## Menambah kolom

1. Buat migration baru (jangan mengubah migration lama yang sudah dijalankan).
2. Tambahkan ke `Api::toSearchDocument()` bila perlu ikut dicari/difilter.
3. Tambahkan ke `IndexManager::mappings()`.
4. Tampilkan lewat `ApiResource` dan/atau `SearchController::presentHit()`.
5. Jalankan `php artisan search:reindex` (perubahan mapping tidak bisa in-place).

## Rencana phase 5 (pgvector)

Extension `vector` sudah aktif sejak volume dibuat (`infrastructure/docker/postgres/01-init.sql`),
jadi tinggal:

```php
// migration berikutnya
DB::statement('ALTER TABLE apis ADD COLUMN embedding vector(384)');
DB::statement('CREATE INDEX apis_embedding_idx ON apis USING hnsw (embedding vector_cosine_ops)');
```

384 dimensi mengikuti model multilingual ringan seperti
`sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2`, yang cukup dijalankan di CPU.
