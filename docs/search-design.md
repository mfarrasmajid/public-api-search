# Desain Search

Semua yang menentukan hasil pencarian ada di tiga tempat:

| Berkas | Menentukan |
|---|---|
| `backend/app/Services/Indexing/IndexManager.php` | mapping, analyzer, sinonim (butuh reindex saat diubah) |
| `backend/config/opensearch.php` | field mana yang dicari dan bobotnya (tanpa reindex) |
| `backend/app/Services/Search/OpenSearchDriver.php` | struktur query, boost kualitas, filter, facet |

## 1. Analyzer

| Analyzer | Dipakai saat | Isi |
|---|---|---|
| `api_text` | indexing | standard tokenizer → lowercase → asciifolding → english stemmer |
| `api_text_search` | query | sama + **synonym filter** |
| `api_folded` | subfield `*.folded` | tanpa stemming, untuk pencocokan yang lebih literal |

Sinonim di-expand **saat query**, bukan saat indexing. Artinya menambah pasangan
sinonim baru cukup dengan mengubah `IndexManager::settings()` lalu reindex sekali —
dan selama pengembangan, koreksi daftar sinonim tidak merusak dokumen yang sudah ada.

Sinonim yang sudah ada (jembatan sementara ID↔EN sampai semantic search siap):

```
cuaca ↔ weather ↔ prakiraan ↔ forecast
gempa ↔ earthquake ↔ seismic
kurs ↔ exchange rate ↔ currency ↔ forex
saham ↔ stock          berita ↔ news
gratis ↔ free          peta ↔ map
sholat ↔ prayer        libur ↔ holiday
pembayaran ↔ payment   terjemahan ↔ translation
```

## 2. Struktur query

```
bool
├── must     multi_match (best_fields, fuzziness AUTO, tie_breaker 0.3)
├── should   match_phrase pada name (boost 8) dan description (boost 2)
│            + sinyal kualitas: https, has_openapi, auth=none, health=healthy
│            + function_score field_value_factor pada quality_score
├── filter   kategori, auth, https, cors, negara, openapi  (tidak memengaruhi skor)
└── must_not status = dead
```

Bobot field (`config/opensearch.php`):

```
name^6  ·  name.folded^4  ·  tags^4  ·  category^3
provider^2  ·  description^2  ·  description.folded  ·  endpoints_text
```

Alasan `name` paling tinggi: orang biasanya mencari nama atau konsep API, bukan
kalimat di deskripsinya. `tags` diberi bobot besar karena di situlah padanan
bahasa Indonesia disimpan.

Sinyal kualitas sengaja diberi boost kecil (0.5–0.8). Tugasnya memecah seri antar
hasil yang sama relevan — bukan mengangkat hasil yang tidak relevan.

## 3. Toleransi typo

`fuzziness: AUTO` + `prefix_length: 1`:

| Panjang term | Toleransi edit |
|---|---|
| 1–2 huruf | 0 |
| 3–5 huruf | 1 |
| ≥6 huruf | 2 |

`prefix_length: 1` berarti huruf pertama harus tepat — ini menjaga presisi sekaligus
performa. `minimum_should_match: "2<70%"` berarti: query 1–2 kata harus cocok semua,
query lebih panjang cukup 70% kata yang cocok.

Diuji di `tests/Feature/OpenSearchRelevanceTest.php` (`wether forecats` → Weather Forecast API).

## 4. Facet

Aggregation `terms` untuk `category.keyword`, `authentication_type`, `https`, `country`.
Facet dihitung dari **hasil query saat itu**, jadi angkanya ikut menyusut ketika filter
dipasang — inilah alasan UI memakai `facets` dari `/api/search`, bukan `/api/meta`.

## 5. Alias switching

```
search:reindex
   │
   ├─ buat index fisik baru: apis_20240817120000
   ├─ bulk index seluruh baris (chunk 250, dengan mapping baru)
   ├─ refresh
   ├─ tukar alias "apis" ke index baru  ← atomik, satu panggilan API
   └─ hapus index lama, sisakan 2 terakhir
```

Selama proses berjalan, pencarian tetap dilayani index lama. Kalau reindex gagal
di tengah jalan, alias tidak berpindah dan tidak ada yang rusak.

## 6. Cara menyetel relevansi

1. Kumpulkan query nyata dari tabel `search_queries` (terutama yang `total_hits = 0`).
2. Uji satu query sambil melihat penjelasan skornya:

```bash
curl -s "http://localhost:9200/apis/_search?explain=true" -H 'Content-Type: application/json' -d '{
  "query": { "multi_match": { "query": "cuaca besok", "fields": ["name^6","tags^4","description^2"], "fuzziness": "AUTO" } },
  "size": 3
}' | head -c 2000
```

3. Ubah satu variabel dalam satu waktu (bobot ATAU sinonim ATAU boost), lalu jalankan
   `php artisan test --filter=OpenSearchRelevance` agar perbaikan satu query tidak merusak yang lain.

## 7. Batas pendekatan ini

Keyword search tidak memahami maksud. `"API untuk mengetahui cuaca besok"` hanya cocok
karena kata *cuaca* ada di tags — bukan karena sistem paham konsep "prakiraan besok".
Untuk itu diperlukan phase 5:

```
                 Query
          ┌────────┴────────┐
     OpenSearch          pgvector
   (keyword, BM25)   (kemiripan makna)
          └────────┬────────┘
              gabung skor  (mis. Reciprocal Rank Fusion)
                   │
              hasil akhir
```

Rencananya: embedding multilingual (384 dimensi, CPU) disimpan di kolom `apis.embedding`,
lalu hasil kedua jalur digabung. Keyword search tetap dipertahankan — untuk pencarian
berbasis nama, ia masih yang paling akurat.
