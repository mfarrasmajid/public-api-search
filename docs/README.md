# Dokumentasi

Dokumen desain dan referensi teknis. Untuk cara install & pakai, lihat
[README root](../README.md).

| Dokumen | Isi |
|---|---|
| [architecture.md](architecture.md) | Peta service, alur request, alasan pemilihan komponen |
| [data-model.md](data-model.md) | Skema tabel, relasi, aturan dedupe |
| [search-design.md](search-design.md) | Mapping, analyzer, sinonim, bobot, strategi ranking |
| [api-contract.md](api-contract.md) | Kontrak endpoint HTTP (request & response) |
| [quality-score.md](quality-score.md) | Formula skor 0–100 dan cara menyetelnya |
| [security-and-legal.md](security-and-legal.md) | Aturan crawling, batasan, catatan lisensi |
| [roadmap.md](roadmap.md) | Fase 1–7 beserta kriteria selesai |
| [troubleshooting.md](troubleshooting.md) | Kumpulan masalah umum |

## Cara memakai dokumen ini

Saat menambah fitur, tentukan dulu empat hal berikut sebelum menulis kode
(prinsip yang dipakai project ini agar tidak over-engineering):

1. **Fitur apa** yang sedang dibuat, dan bagaimana cara membuktikannya di POC lokal.
2. **Service mana** yang bertanggung jawab (backend / crawler / frontend).
3. **Data apa** yang dibutuhkan, dan apakah skema saat ini sudah cukup.
4. **Cara paling sederhana** untuk mencapainya — infrastruktur baru adalah pilihan terakhir.
