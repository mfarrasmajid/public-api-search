# Troubleshooting

## Diagnosis cepat

```bash
docker compose ps                                  # semua service running/healthy?
curl http://localhost:8080/api/health               # backend + dependensinya
docker compose exec backend php artisan search:status
docker compose logs --tail=50 backend
```

---

## Instalasi & startup

| Gejala | Penyebab | Solusi |
|---|---|---|
| `bind: address already in use` | port dipakai aplikasi lain | ubah `APP_PORT`/`DB_PORT_HOST`/`OPENSEARCH_PORT_HOST` di `.env`, lalu `docker compose up -d` |
| `dependency failed to start: container apidisc-opensearch is unhealthy` | OpenSearch belum sempat siap | tunggu ~60 detik; kalau tetap gagal cek log dan RAM |
| opensearch exit 137 | kehabisan memori | `OPENSEARCH_HEAP=384m` di `.env`; tambah RAM Docker Desktop |
| `max virtual memory areas vm.max_map_count [65530] is too low` | batas kernel Linux | `sudo sysctl -w vm.max_map_count=262144` |
| backend restart terus | composer gagal / `.env` tidak ada | `docker compose logs backend`; masuk container lalu `composer install` manual |
| boot pertama sangat lama | composer + npm install | normal, 5–10 menit; pantau `docker compose logs -f` |

---

## Search

| Gejala | Penyebab | Solusi |
|---|---|---|
| Hasil selalu kosong | database kosong | `make seed` lalu `make reindex` |
| `"driver": "database"` di response | OpenSearch tidak terjangkau | `curl localhost:9200`; `docker compose logs opensearch`; setelah pulih `make reindex` |
| `"total": 0` padahal data ada | index kosong / alias belum ada | `php artisan search:status`, lalu `php artisan search:reindex` |
| Hasil tidak relevan | bobot atau sinonim | lihat [search-design.md](search-design.md) bagian 6 |
| Typo tidak ketemu | huruf pertama salah | `prefix_length: 1` memang mensyaratkan huruf pertama tepat |
| Query Indonesia tidak menemukan dokumen Inggris | belum ada di daftar sinonim | tambahkan di `IndexManager::settings()` lalu reindex; solusi permanennya phase 5 |
| Perubahan mapping tidak berlaku | mapping tidak bisa diubah in-place | `php artisan search:reindex` (index fisik baru dibuat) |

---

## Database

| Gejala | Solusi |
|---|---|
| `SQLSTATE[08006] connection refused` | `docker compose ps postgres`; tunggu healthcheck selesai |
| `relation "apis" does not exist` | `php artisan migrate` |
| Ingin mulai dari nol | `php artisan migrate:fresh --seed` lalu `search:reindex` (atau `make fresh`) |
| Ingin melihat data | `make psql`, lalu `\dt` dan `SELECT count(*) FROM apis;` |
| Volume rusak / ingin bersih total | `docker compose down -v` (semua data hilang) |

---

## Frontend

| Gejala | Solusi |
|---|---|
| 502 dari nginx di `/` | Vite belum siap → `docker compose logs -f frontend` |
| Halaman blank, error di console | cek `curl http://localhost:8080/api/health` |
| HMR tidak jalan | `VITE_HMR_CLIENT_PORT` harus sama dengan `APP_PORT` |
| Perubahan file tidak terdeteksi | restart container frontend (polling sudah aktif) |
| Butuh paket baru | `docker compose exec frontend npm install <paket>` (jangan dari host — `node_modules` ada di volume) |

---

## Crawler

| Gejala | Solusi |
|---|---|
| `PermissionError: Blocked by robots.txt` | perilaku benar; pilih sumber lain |
| Sangat lambat | rate limit disengaja; naikkan `CRAWLER_REQUESTS_PER_MINUTE` seperlunya |
| Hasil crawl tidak muncul di search | `php artisan search:reindex` |
| `no spec: X` saat perintah `openapi` | wajar, tidak semua API mempublikasikan spec |
| `connection refused` ke postgres | di dalam Docker host-nya `postgres`, dari host `127.0.0.1` |

---

## Performa

| Gejala | Solusi |
|---|---|
| Search lambat (>500 ms) | cek `took_ms`; bila driver `database`, OpenSearch sedang mati |
| Reindex lambat | normal untuk ribuan dokumen; naikkan `OPENSEARCH_BULK_SIZE` |
| Docker memakan RAM besar | matikan profile yang tidak dipakai; turunkan `OPENSEARCH_HEAP` |
| Disk penuh | `docker system prune -a` (hati-hati dengan `--volumes`) |

---

## Reset total

```bash
docker compose --profile crawler --profile workers --profile tools down -v
docker compose up -d
docker compose logs -f backend      # tunggu "Backend ready."
```
