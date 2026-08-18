# Keamanan & Aspek Legal

Dokumen ini mengikat untuk semua kode di repo ini, terutama crawler.

## 1. Aturan crawling

**Boleh:**

- GET/HEAD ke endpoint yang memang didokumentasikan publik
- Membaca file direktori publik (README GitHub, `list.json` APIs.guru)
- Mencoba path spec yang konvensional: `/openapi.json`, `/swagger.json`, `/openapi.yaml`,
  `/swagger.yaml`, `/api-docs`
- Menghormati `robots.txt` (default aktif: `CRAWLER_RESPECT_ROBOTS=true`)
- Rate limit per domain (default 20 request/menit, dijaga `DomainRateLimiter`)

**Tidak boleh:**

| Larangan | Alasan |
|---|---|
| POST/PUT/PATCH/DELETE otomatis | bisa menimbulkan efek samping di sistem orang lain |
| Brute force / enumerasi endpoint | itu perilaku scanner, bukan crawler |
| Credential guessing atau bypass autentikasi | ilegal di banyak yurisdiksi |
| Scanning agresif / paralel tanpa batas | membebani penyedia, berujung diblokir |
| Menghapus atribusi & informasi lisensi | melanggar syarat sumber data |

Health checker hanya memakai `HEAD` lalu `GET` sebagai cadangan, dan tidak pernah
mengirim request yang mengubah state (`health_checker.SAFE_METHODS`).

## 2. Terms of Service & lisensi

- Simpan selalu `source` dan `source_url` untuk setiap record — itulah jejak atribusi.
- Direktori seperti public-apis dan APIs.guru punya lisensinya sendiri; patuhi saat
  mendistribusikan ulang datanya.
- Kolom `license` pada tabel `apis` merekam lisensi API itu sendiri bila diketahui.
- Jika penyedia meminta datanya dihapus, hapus baris terkait dan hentikan crawl
  domain tersebut (nonaktifkan `crawl_sources.enabled`).

## 3. Paparan jaringan

Di POC lokal, PostgreSQL/OpenSearch/Redis di-bind ke `127.0.0.1` saja. Untuk produksi:

| Komponen | Aturan |
|---|---|
| PostgreSQL | jangan pernah terekspos ke internet; hanya jaringan internal |
| OpenSearch | jangan pernah terekspos; **aktifkan kembali security plugin** (di POC dimatikan) |
| Redis | jangan pernah terekspos; pasang password |
| Backend | hanya lewat nginx + TLS |
| `APP_DEBUG` | wajib `false` di produksi |
| `CORS_ALLOWED_ORIGINS` | domain nyata, bukan `*` |

Checklist lengkap ada di [`../infrastructure/README.md`](../infrastructure/README.md) bagian 8.

## 4. Data pribadi

Aplikasi ini menyimpan metadata teknis API, bukan data pribadi. Yang perlu dijaga:

- Jangan menyimpan API key milik siapa pun di database — tidak ada kolomnya, dan
  memang tidak boleh ditambahkan.
- `search_queries` menyimpan teks query. Bila nanti ada login pengguna, pertimbangkan
  retensi (mis. hapus otomatis setelah 90 hari) sebelum mengaitkannya dengan identitas.

## 5. Bila menambah sumber baru

Sebelum menulis parser, jawab dulu:

1. Apakah sumbernya memang publik dan boleh dibaca mesin?
2. Apakah lisensinya mengizinkan penyimpanan ulang metadata?
3. Berapa rate limit yang wajar? (isi `crawl_sources.rate_limit_per_minute`)
4. Apakah `robots.txt` mengizinkan? (jangan mematikan pengecekannya)
5. Bagaimana atribusinya ditampilkan?
