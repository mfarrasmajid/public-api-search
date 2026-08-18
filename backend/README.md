# Backend — Laravel 11

Service ini adalah **otak aplikasi**: pemilik data, penyedia REST/Search API, dan
satu-satunya komponen yang boleh menulis ke OpenSearch.

## Tanggung jawab

| Ya | Tidak |
|---|---|
| REST API & Search API untuk frontend | Crawling internet (itu tugas `crawler/`) |
| Menyimpan katalog API di PostgreSQL (source of truth) | Menyimpan state di OpenSearch (index selalu bisa dibangun ulang) |
| Membangun & memperbarui index OpenSearch | Rendering HTML (frontend terpisah) |
| Menghitung quality score | |
| Menyediakan metadata filter untuk UI | |

---

## 1. Tools yang perlu disiapkan

Kalau memakai Docker (**cara yang disarankan**), tidak ada yang perlu diinstal —
semua sudah ada di image `apidisc/backend:local`.

Kalau ingin menjalankan langsung di host (opsional, untuk IDE/debugging):

| Tool | Versi | Catatan |
|---|---|---|
| PHP | 8.2+ | ekstensi wajib: `pdo_pgsql`, `pgsql`, `intl`, `zip`, `bcmath`, `mbstring`, `curl` |
| Composer | 2.x | |
| PostgreSQL client | 14+ | opsional, untuk `psql` |

Cek ekstensi PHP: `php -m | grep -E 'pdo_pgsql|intl|zip'`

---

## 2. Yang perlu dilakukan (sekali di awal)

Melalui Docker, ketiga langkah di bawah **sudah otomatis** dijalankan oleh
`infrastructure/docker/php/entrypoint.sh` saat container pertama kali start.
Matikan dengan `AUTO_MIGRATE=false` / `AUTO_SEED=false` / `AUTO_INDEX=false` di `.env`
bila ingin mengendalikannya sendiri.

```bash
# 1. Dependency
docker compose exec backend composer install

# 2. Skema database
docker compose exec backend php artisan migrate

# 3. Data awal (119 API) + index
docker compose exec backend php artisan db:seed
docker compose exec backend php artisan search:reindex
```

Setup manual di host (tanpa Docker):

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# arahkan DB_HOST/OPENSEARCH_HOST ke 127.0.0.1 di .env
php artisan migrate --seed
php artisan search:reindex
php artisan serve            # http://127.0.0.1:8000
```

---

## 3. Perintah yang perlu dieksekusi

Semua contoh memakai prefix Docker; hapus prefix bila jalan di host.

### Data & index

```bash
docker compose exec backend php artisan db:seed              # muat dataset starter (idempoten)
docker compose exec backend php artisan search:reindex       # bangun ulang index + tukar alias
docker compose exec backend php artisan search:status        # cluster health, jumlah dokumen
docker compose exec backend php artisan apis:score --reindex # hitung ulang quality score
docker compose exec backend php artisan apis:count           # jumlah baris di tabel apis
docker compose exec backend php artisan apis:import storage/apis.json --reindex
docker compose exec backend php artisan migrate:fresh --seed # reset total (destruktif)
```

### Development

```bash
docker compose exec backend php artisan test                 # seluruh test
docker compose exec backend php artisan test --filter=Search # satu grup
docker compose exec backend php artisan route:list
docker compose exec backend php artisan tinker
docker compose logs -f backend
```

### Kapan perlu re-index?

| Kejadian | Perlu `search:reindex`? |
|---|---|
| Menambah/mengubah API lewat seeder, importer, atau crawler | ✅ ya |
| Mengubah mapping/analyzer di `IndexManager` | ✅ ya (mapping tidak bisa diubah in-place) |
| Mengubah bobot field di `config/opensearch.php` | ❌ tidak (dipakai saat query) |
| Mengubah daftar sinonim | ❌ tidak (sinonim di-expand saat search) |

---

## 4. Struktur folder

```
backend/
├── app/
│   ├── Console/Commands/        artisan: search:reindex, search:status, apis:score, apis:import, apis:count
│   ├── Http/
│   │   ├── Controllers/Api/     SearchController, ApiCatalogController, MetaController
│   │   ├── Requests/            SearchRequest — validasi + normalisasi query string
│   │   └── Resources/           ApiResource — bentuk JSON untuk detail API
│   ├── Models/                  Api, Provider, Category, ApiEndpoint, ApiHealthCheck, CrawlSource, CrawlJob
│   ├── Providers/               AppServiceProvider — wiring OpenSearch client & driver
│   └── Services/
│       ├── Indexing/            IndexManager (mapping+alias), ApiIndexer (bulk)
│       ├── Search/              SearchService, OpenSearchDriver, DatabaseSearchDriver
│       ├── ApiImporter.php      satu jalur upsert untuk semua sumber data
│       ├── QualityScorer.php    formula skor 0..100
│       └── OpenSearchClientFactory.php
├── config/opensearch.php        host, alias, bobot field, fuzziness, fallback
├── database/
│   ├── migrations/              skema apis, endpoints, health checks, crawl, telemetry
│   └── seeders/data/apis.seed.json   119 API starter
├── routes/api.php               kontrak publik
└── tests/                       Feature + Unit
```

---

## 5. Konsep penting

### Alias switching (re-index tanpa downtime)

Aplikasi selalu query ke **alias** `apis`. `search:reindex` membuat index fisik baru
(`apis_20240101120000`), mengisinya, baru menukar alias secara atomik lalu menghapus index lama
(menyisakan 2 terakhir). Search tidak pernah kosong di tengah proses.

### Dua driver search

`SearchService` memakai `OpenSearchDriver`. Jika cluster tidak terjangkau, ia otomatis
jatuh ke `DatabaseSearchDriver` (ILIKE di PostgreSQL) dan menandai response dengan
`"driver": "database"`. Tujuannya: POC **degradasi**, bukan mati — dan test bisa jalan
tanpa cluster. Matikan dengan `SEARCH_FALLBACK_TO_DATABASE=false`.

### Bobot relevansi

Diatur di `config/opensearch.php` (`name^6`, `tags^4`, `category^3`, `description^2`, …).
Sinyal kualitas (HTTPS, OpenAPI, tanpa auth, health) hanya diberi boost kecil —
tugasnya memecah seri antar hasil yang sama relevan, bukan menaikkan hasil tidak relevan.

### Quality score

`QualityScorer` memakai bobot: dokumentasi 20, availability 25, HTTPS 10, auth 10,
OpenAPI 10, kecepatan 10, popularitas 10, freshness 5. Popularitas masih netral (0.5)
karena belum ada data klik. Lihat [`../docs/quality-score.md`](../docs/quality-score.md).

---

## 6. Testing

```bash
docker compose exec backend php artisan test
```

- `tests/Feature/SearchApiTest.php` — kontrak endpoint, filter, validasi, telemetry
  (memakai SQLite in-memory + driver database, tidak butuh cluster)
- `tests/Feature/OpenSearchRelevanceTest.php` — ranking, typo, query Indonesia, facet
  (otomatis **skip** bila OpenSearch tidak terjangkau)
- `tests/Unit/ApiImporterTest.php` — idempotensi upsert, normalisasi auth
- `tests/Unit/QualityScorerTest.php` — urutan skor

---

## 7. Menambah fitur

| Ingin | Sentuh file |
|---|---|
| Menambah field baru pada API | migration → `Api::toSearchDocument()` → `IndexManager::mappings()` → `ApiResource` → reindex |
| Mengubah ranking | `config/opensearch.php` (bobot) dan `OpenSearchDriver::buildQuery()` |
| Menambah filter | `SearchRequest::rules()` → `SearchQueryData` → `OpenSearchDriver::buildFilters()` → `DatabaseSearchDriver` |
| Menambah sinonim ID↔EN | `IndexManager::settings()` → `api_synonyms` (tanpa reindex) |
| Menambah sumber data | `crawler/` (lihat README-nya), backend cukup `apis:import` |

---

## 8. Admin panel (opsional, belum terpasang)

Filament sengaja **belum** dipasang agar `composer install` tetap ringan di POC.
Bila dibutuhkan:

```bash
docker compose exec backend composer require filament/filament:"^3.2"
docker compose exec backend php artisan filament:install --panels
docker compose exec backend php artisan make:filament-resource Api --generate
docker compose exec backend php artisan make:filament-user
```

Setelah mengedit data lewat Filament, jalankan `search:reindex` (atau panggil
`ApiIndexer::indexOne()` di event model) agar index ikut ter-update.

---

## 9. Troubleshooting

| Gejala | Solusi |
|---|---|
| `SQLSTATE[08006] connection refused` | PostgreSQL belum siap → `docker compose ps`, tunggu healthcheck |
| `No alive nodes found in your cluster` | OpenSearch belum siap → `curl localhost:9200`, `make index-status` |
| Response selalu `"driver": "database"` | Sama seperti di atas; cek `docker compose logs opensearch` |
| `search:reindex` sukses tapi hasil kosong | Cek jumlah baris: `php artisan apis:count`; kalau 0 → `db:seed` |
| `Permission denied` di `storage/` | `docker compose exec backend chmod -R ug+rw storage bootstrap/cache` |
| Perubahan `config/` tidak terbaca | `php artisan config:clear` |
