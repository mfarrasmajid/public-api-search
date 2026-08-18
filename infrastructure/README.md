# Infrastructure — Docker

Semua image, konfigurasi service, dan orkestrasi lokal.

> `docker-compose.yml` sengaja diletakkan di **root repository**, bukan di folder ini,
> supaya target `docker compose up -d` bisa dijalankan langsung setelah clone.
> Folder ini berisi Dockerfile dan file konfigurasi yang dirujuk compose.

---

## 1. Tools yang perlu disiapkan

| Tool | Versi minimum | Verifikasi |
|---|---|---|
| Docker Engine | 24+ | `docker --version` |
| Docker Compose plugin | v2 | `docker compose version` |
| make | opsional | `make --version` |

Khusus Linux, OpenSearch butuh:

```bash
sudo sysctl -w vm.max_map_count=262144
# permanen:
echo 'vm.max_map_count=262144' | sudo tee -a /etc/sysctl.conf
```

Docker Desktop (macOS/Windows): alokasikan minimal **6 GB RAM** di Settings → Resources.

---

## 2. Yang perlu dilakukan

```bash
cp .env.example .env     # dari root repo — ubah port bila bentrok
docker compose build     # opsional; `up` akan build otomatis bila perlu
docker compose up -d
```

---

## 3. Perintah yang perlu dieksekusi

```bash
docker compose up -d                    # phase 1: postgres, opensearch, redis, backend, nginx, frontend
docker compose --profile crawler up -d  # + crawler Python
docker compose --profile workers up -d  # + queue worker & scheduler
docker compose --profile tools up -d    # + OpenSearch Dashboards (localhost:5601)

docker compose ps                       # status + healthcheck
docker compose logs -f backend          # log satu service
docker compose restart backend
docker compose build --no-cache backend # rebuild image dari nol
docker compose down                     # stop, data tetap
docker compose down -v                  # stop + hapus volume (SEMUA DATA HILANG)
docker stats                            # pemakaian RAM/CPU per container
```

---

## 4. Isi folder

```
infrastructure/docker/
├── php/
│   ├── Dockerfile          php:8.3-fpm-alpine + pdo_pgsql, intl, zip, redis, opcache
│   └── entrypoint.sh       tunggu dependency → composer install → migrate → seed → reindex
├── nginx/conf.d/
│   └── default.conf        /api & /up → php-fpm, sisanya → Vite (termasuk websocket HMR)
├── frontend/
│   ├── Dockerfile          multi-stage: dev (Vite) | build | prod (nginx statis)
│   └── nginx-spa.conf      konfigurasi stage prod
├── crawler/
│   └── Dockerfile          python:3.12-slim + requirements-dev
├── opensearch/
│   └── opensearch.yml      referensi konfigurasi single-node
└── postgres/
    └── 01-init.sql         mengaktifkan extension vector & pg_trgm saat volume dibuat
```

---

## 5. Peta service

| Service | Image | Profile | Port (host) | Bergantung pada |
|---|---|---|---|---|
| `postgres` | pgvector/pgvector:pg16 | default | 5432 | — |
| `opensearch` | opensearchproject/opensearch:2.17.1 | default | 9200 | — |
| `redis` | redis:7-alpine | default | 6379 | — |
| `backend` | build php/ | default | — (via nginx) | postgres, opensearch |
| `nginx` | nginx:1.27-alpine | default | **8080** | backend, frontend |
| `frontend` | build frontend/ (dev) | default | 5173 | — |
| `crawler` | build crawler/ | `crawler` | — | postgres |
| `queue` | image backend | `workers` | — | backend |
| `scheduler` | image backend | `workers` | — | backend |
| `opensearch-dashboards` | opensearch-dashboards:2.17.1 | `tools` | 5601 | opensearch |

Volume: `postgres-data`, `opensearch-data`, `redis-data`, `backend-vendor`, `frontend-node-modules`.

---

## 6. Keputusan desain

**Kenapa image `pgvector/pgvector:pg16`, bukan `postgres:16`?**
Isinya PostgreSQL biasa plus extension `vector`. Phase 5 (semantic search) jadi tidak
perlu ganti image — cukup satu migration untuk menambah kolom embedding.

**Kenapa security plugin OpenSearch dimatikan?**
Supaya POC lokal tidak berurusan dengan sertifikat demo. Aman selama port hanya
di-bind ke `127.0.0.1`. **Jangan** dipakai apa adanya di VPS — lihat bagian 8.

**Kenapa `backend-vendor` dan `frontend-node-modules` jadi named volume?**
Direktori dependency dipisahkan dari bind mount agar tidak bentrok dengan isi host
dan agar I/O tetap cepat di macOS/Windows.

**Kenapa nginx ikut mem-bind mount `./backend`?**
nginx menyajikan `public/` secara langsung dan meneruskan `.php` ke php-fpm lewat
FastCGI, jadi kedua container harus melihat path file yang sama.

**Kenapa crawler ada di profile terpisah?**
POC search (phase 1) tidak membutuhkannya. Profile menjaga stack default tetap ringan.

---

## 7. Tuning sumber daya

`.env`:

```bash
OPENSEARCH_HEAP=384m     # laptop 8 GB
OPENSEARCH_HEAP=512m     # default
OPENSEARCH_HEAP=1g       # >10.000 API
```

Menjalankan sebagian stack saja saat RAM terbatas:

```bash
docker compose up -d postgres backend nginx frontend   # tanpa OpenSearch
# search otomatis memakai fallback PostgreSQL (driver: database)
```

---

## 8. Menuju VPS / produksi

Yang **wajib** diubah sebelum dipublikasikan ke internet:

1. Hapus publikasi port `postgres`, `opensearch`, dan `redis` — biarkan hanya di jaringan internal Docker.
2. Aktifkan kembali security plugin OpenSearch, buat user + password, set `OPENSEARCH_SCHEME=https`.
3. `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` baru, password database yang kuat.
4. `CORS_ALLOWED_ORIGINS` diisi domain nyata, bukan `*`.
5. Frontend memakai stage `prod` (bundel statis) — bukan Vite dev server.
6. `AUTO_MIGRATE`/`AUTO_SEED`/`AUTO_INDEX=false`; jalankan migrasi lewat proses deploy.
7. TLS di depan nginx (Caddy/Traefik/certbot).
8. Backup volume `postgres-data` secara terjadwal (OpenSearch tidak perlu di-backup — bisa dibangun ulang).

Kubernetes **tidak** diperlukan pada tahap ini; satu VPS 4 vCPU/8 GB masih cukup.

---

## 9. Troubleshooting

| Gejala | Solusi |
|---|---|
| `bind: address already in use` | Ubah port di `.env` (`APP_PORT`, `DB_PORT_HOST`, …) |
| opensearch exit code 137 | Kehabisan memori → turunkan `OPENSEARCH_HEAP`, tambah RAM Docker |
| `max virtual memory areas ... too low` | `sudo sysctl -w vm.max_map_count=262144` |
| Container backend restart terus | `docker compose logs backend`; biasanya composer gagal atau `.env` hilang |
| Perubahan Dockerfile tidak terpakai | `docker compose build --no-cache <service> && docker compose up -d` |
| Disk penuh | `docker system prune -a --volumes` (hati-hati: menghapus volume) |
| `dependency failed to start: container is unhealthy` | Cek service penyebabnya; OpenSearch butuh sampai ~60 detik saat boot pertama |
