# Arsitektur

## Peta service

```
                            Browser
                               │
                    http://localhost:8080
                               │
                        ┌──────▼──────┐
                        │    nginx    │
                        └──┬───────┬──┘
                 /api, /up │       │ /
                    ┌──────▼──┐  ┌─▼─────────┐
                    │ Laravel │  │  Vite     │
                    │ php-fpm │  │  React    │
                    └────┬────┘  └───────────┘
              ┌──────────┼───────────┐
        ┌─────▼────┐ ┌───▼──────┐ ┌──▼────┐
        │PostgreSQL│ │OpenSearch│ │ Redis │
        └─────▲────┘ └──────────┘ └───────┘
              │
        ┌─────┴──────┐
        │  Crawler   │──→ direktori public API, spec OpenAPI, health check
        │  (Python)  │
        └────────────┘
```

## Pembagian tanggung jawab

| Service | Menulis ke | Membaca dari | Catatan |
|---|---|---|---|
| Laravel | PostgreSQL, OpenSearch | PostgreSQL, OpenSearch, Redis | satu-satunya penulis OpenSearch |
| Crawler | PostgreSQL | internet, PostgreSQL | tidak pernah menyentuh OpenSearch |
| Frontend | — | HTTP API | tidak tahu apa-apa soal database |
| nginx | — | — | satu origin, menghindari masalah CORS |

Aturan pentingnya: **PostgreSQL adalah source of truth**. OpenSearch adalah turunan
yang boleh dihapus dan dibangun ulang kapan saja (`php artisan search:reindex`).
Konsekuensinya, tidak ada data yang hanya hidup di index.

## Alur request pencarian

```
GET /api/search?q=cuaca&auth=none
   │
   ├─ SearchRequest         validasi + normalisasi → SearchQueryData
   ├─ SearchService         pilih driver, catat telemetry
   │     ├─ OpenSearchDriver ── bool query (must/should/filter) + aggregations
   │     └─ DatabaseSearchDriver (fallback ILIKE, bila cluster mati)
   └─ SearchController      bentuk JSON response
```

Fallback ada supaya POC terdegradasi, bukan mati. Response selalu menyebut
`"driver"`, jadi ketahuan kapan hasil datang dari jalur cadangan.

## Alur ingestion

```
Sumber publik ──► Crawler ──► ApiRecord (pydantic) ──► PostgreSQL
                                                          │
                              php artisan search:reindex ─┘
                                        │
                                  index fisik baru
                                        │
                                  alias "apis" ditukar (atomik)
```

Detail alias switching ada di [search-design.md](search-design.md).

## Kenapa komponennya ini?

| Komponen | Alasan | Alternatif yang ditolak (untuk sekarang) |
|---|---|---|
| PostgreSQL | Workload utamanya CRUD + metadata relasional; punya jalur upgrade ke pgvector | ClickHouse — kolom analitik, bukan transaksional |
| OpenSearch | Full-text, fuzzy, faceting, boosting — semuanya siap pakai dan open source | Meilisearch/Typesense (lebih ringan tapi kalah fleksibel untuk ranking), pencarian SQL murni (tidak ada fuzzy/ranking serius) |
| Laravel | Ekosistem lengkap: ORM, queue, scheduler, testing, Filament untuk admin | — |
| Python (crawler) | Ekosistem parsing/HTTP paling nyaman, mudah dipisah jadi worker | Menaruh crawler di Laravel — mencampur tanggung jawab |
| Redis | Cache, queue, rate limiter, koordinasi job | Kafka — belum ada kebutuhan streaming |
| Docker Compose | Satu perintah untuk seluruh environment, mudah dipindah ke VPS | Kubernetes — belum ada kebutuhan nyata |

## Yang sengaja belum ada

Kubernetes, Kafka, ClickHouse, Airflow, GPU, cluster multi-node, load balancer khusus.
Semuanya baru masuk kalau ada alasan teknis yang nyata — bukan karena "nanti pasti butuh".

## Jalur evolusi

```
Docker Compose lokal
        │  (POC terbukti)
        ▼
Satu VPS (compose yang sama + TLS + secret sungguhan)
        │  (trafik/dataset tumbuh)
        ▼
VPS terpisah per service, OpenSearch di mesin sendiri
        │  (baru bila memang perlu)
        ▼
Kubernetes
```

Karena setiap service sudah punya batas yang jelas (image sendiri, konfigurasi lewat
environment variable, tanpa state di dalam container), langkah-langkah di atas tidak
memerlukan desain ulang.
