# Quality Score

Skor 0–100 per API. Dihitung oleh `backend/app/Services/QualityScorer.php`,
disimpan di kolom `apis.quality_score`, dan ikut dipakai sebagai sinyal ranking.

## Bobot

| Komponen | Bobot | Cara dinilai |
|---|---|---|
| Documentation | 20 | punya `documentation_url` (0.5) + deskripsi >40 karakter (0.3) + punya endpoint hasil parsing (0.2) |
| Availability | 25 | health check terakhir: healthy 1.0 · degraded 0.6 · unhealthy 0.0 · belum pernah dicek 0.5 |
| HTTPS | 10 | 1.0 bila HTTPS |
| Authentication | 10 | none 1.0 · apiKey 0.7 · bearer 0.6 · OAuth 0.4 · unknown 0.5 |
| OpenAPI | 10 | 1.0 bila punya spec |
| Response speed | 10 | <300 ms 1.0 · <800 ms 0.7 · <2000 ms 0.4 · selebihnya 0.1 · belum dicek 0.5 |
| Popularity | 10 | **netral 0.5** — belum ada data |
| Freshness | 5 | `last_seen_at` <30 hari 1.0 · <180 hari 0.6 · selebihnya 0.2 |

Skor = Σ (nilai komponen × bobot), dibulatkan, dibatasi 0–100.

## Keputusan yang perlu diketahui

**API yang belum pernah dicek tidak dihukum.** Availability dan response speed diberi
nilai netral 0.5, bukan 0. Kalau tidak, API baru hasil crawl akan selalu tenggelam
hanya karena health checker belum sempat menyentuhnya.

**Popularity masih 0.5 untuk semua.** Belum ada data klik maupun bintang GitHub.
Dibiarkan eksplisit di kode agar jelas bahwa komponen ini belum aktif — bukan
diam-diam mendistorsi peringkat.

**Auth yang lebih mudah dipakai mendapat skor lebih tinggi.** Ini opini produk:
untuk orang yang sedang mencari API, "bisa langsung dicoba" memang lebih berharga.

## Menjalankan

```bash
docker compose exec backend php artisan apis:score            # hitung ulang saja
docker compose exec backend php artisan apis:score --reindex  # + perbarui index
```

Dijadwalkan otomatis tiap hari pukul 02:00 bila profile `workers` menyala
(lihat `backend/routes/console.php`).

Jalankan ulang setiap kali: selesai crawl, selesai health check, atau setelah
mengubah bobot.

## Cara menyetel

Formula ini **tebakan pertama**, dan memang harus dievaluasi dengan data nyata.
Cara memeriksa apakah masuk akal:

```sql
-- Apakah API yang bagus benar-benar berada di atas?
SELECT name, quality_score, authentication_type, https, has_openapi
  FROM apis ORDER BY quality_score DESC LIMIT 20;

-- Apakah distribusinya menumpuk di satu titik? (kalau ya, skornya tidak informatif)
SELECT width_bucket(quality_score, 0, 100, 10) AS bucket, count(*)
  FROM apis GROUP BY bucket ORDER BY bucket;
```

Kalau semua API menumpuk di 55–70, artinya komponen yang membedakan (health, OpenAPI)
belum terisi — jalankan health checker dan parser OpenAPI dulu sebelum menyentuh bobot.

## Tampilan di UI

```
BMKG Cuaca Wilayah Indonesia            64
Weather · Tanpa auth · HTTPS · Indonesia
```

Warna badge: hijau ≥75, kuning 50–74, merah <50 (`ResultCard.jsx`).
