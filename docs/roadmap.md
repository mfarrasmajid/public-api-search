# Roadmap

Prinsip: jangan menambah infrastruktur sebelum ada kebutuhan nyata. Setiap fase harus
bisa dibuktikan di POC lokal.

---

## Phase 1 — Search POC ✅ selesai

**Target:** 100 API, search dasar berjalan.

Sudah ada:

- Docker Compose: PostgreSQL, OpenSearch, Redis, Laravel, nginx, React
- 119 API starter (17 di antaranya API Indonesia)
- `GET /api/search` dengan fuzzy matching, filter, facet, paginasi
- Alias switching untuk reindex tanpa downtime
- Quality score
- Fallback PostgreSQL saat OpenSearch mati
- UI: search, filter, detail
- Test: PHPUnit (9 lulus) + relevance test (butuh cluster)

---

## Phase 2 — Automated Discovery 🟡 kode siap

**Target:** 1.000 API di database.

Sudah ada: kerangka crawler, sumber `public-apis` & `apis-guru`, rate limiter, robots.txt,
upsert idempoten, pencatatan `crawl_jobs`.

Yang perlu dikerjakan:

- [ ] Jalankan crawl penuh, periksa kualitas hasil normalisasi
- [ ] Perbaiki dedupe untuk nama yang sama tapi API berbeda (saat ini saling menimpa lewat slug)
- [ ] Tambah sumber ketiga (mis. direktori API pemerintah Indonesia)
- [ ] Jadwalkan crawl mingguan lewat profile `workers`

**Selesai bila:** ≥1.000 API di database, crawl ulang tidak menghasilkan duplikat,
dan `crawl_jobs` mencatat setiap eksekusi.

---

## Phase 3 — OpenAPI Parser 🟡 kode siap

Sudah ada: probing path spec konvensional, parser JSON & YAML, ekstraksi endpoint +
parameter, `endpoints_text` ikut diindeks.

Yang perlu dikerjakan:

- [ ] Uji terhadap ratusan API nyata, catat pola yang gagal
- [ ] Dukung `$ref` lintas berkas
- [ ] Simpan `request_schema` / `response_schema` (kolom sudah ada, belum diisi)
- [ ] Jadikan endpoint bisa dicari secara langsung (mis. `GET /forecast`)

**Selesai bila:** ≥20% API punya endpoint hasil parsing, dan pencarian tingkat
endpoint memberi hasil masuk akal.

---

## Phase 4 — Health Checker 🟡 kode siap

Sudah ada: cek DNS, TLS (termasuk masa berlaku), HTTP dengan HEAD→GET, klasifikasi
status, penyimpanan riwayat, integrasi ke quality score.

Yang perlu dikerjakan:

- [ ] Penjadwalan berkala (queue Laravel atau cron di container crawler)
- [ ] Hitung uptime dari riwayat (7 hari / 30 hari)
- [ ] Tandai `status = dead` otomatis setelah N kegagalan berturut-turut
- [ ] Tampilkan tren di UI detail

**Selesai bila:** seluruh API dicek minimal harian dan uptime tampil di UI.

---

## Phase 5 — Semantic Search ⬜

**Target:** `"API untuk mengetahui cuaca besok"` menemukan *Weather Forecast API*
tanpa bergantung pada daftar sinonim.

Rencana:

1. Tambah kolom `apis.embedding vector(384)` (extension `vector` sudah aktif).
2. Worker Python membuat embedding memakai
   `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2` (cukup CPU).
3. Buat index HNSW untuk pencarian tetangga terdekat.
4. Gabungkan hasil keyword + vektor (Reciprocal Rank Fusion).

**Selesai bila:** query bahasa Indonesia yang tidak ada di daftar sinonim tetap
menemukan dokumen berbahasa Inggris yang relevan.

---

## Phase 6 — Intelligent Ranking ⬜

- Learning-to-rank sederhana memakai data klik dari `search_queries`
- Reranking hasil teratas
- Query understanding: mendeteksi maksud (`gratis` → filter auth=none,
  `Indonesia` → filter negara)
- Evaluasi relevansi berbasis metrik (NDCG@10) dengan judgement set kecil

---

## Phase 7 — Production ⬜

```
Docker Compose lokal → satu VPS → VPS terpisah per service → Kubernetes (hanya bila perlu)
```

Checklist sebelum go-live ada di [`../infrastructure/README.md`](../infrastructure/README.md) bagian 8.

---

## Yang sengaja belum dipakai

Kubernetes, Kafka, ClickHouse, Airflow, GPU, cluster multi-node, load balancer khusus.

Kapan barulah dipertimbangkan:

| Komponen | Pemicu |
|---|---|
| Airflow | pipeline ingestion sudah punya banyak dependensi, retry, dan monitoring kompleks |
| Kafka | throughput event benar-benar butuh streaming terdistribusi |
| ClickHouse | analitik/telemetry search berskala besar |
| Kubernetes | satu VPS sudah tidak cukup dan butuh orkestrasi multi-node sungguhan |
