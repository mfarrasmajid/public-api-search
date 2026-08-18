# Frontend — React 18 + Vite

UI pencarian. Sengaja sederhana: satu halaman, fokus pada *search experience*,
tanpa state management library, tanpa UI framework, tanpa router.

---

## 1. Tools yang perlu disiapkan

Dengan Docker: tidak ada — container `frontend` sudah menjalankan Vite dev server.

Tanpa Docker (opsional):

| Tool | Versi |
|---|---|
| Node.js | 20+ (dipakai 22 di image) |
| npm | 10+ |

---

## 2. Yang perlu dilakukan

### Jalur Docker (disarankan)

Sudah otomatis oleh `docker compose up -d`. Buka <http://localhost:8080>.
Hot reload aktif: menyimpan file di `frontend/src` langsung terlihat di browser.

### Jalur lokal

```bash
cd frontend
npm install
cp .env.example .env          # isi VITE_API_BASE_URL=http://localhost:8080
npm run dev                   # http://localhost:5173
```

Tanpa `VITE_API_BASE_URL`, request diarahkan ke origin yang sama (`/api/...`) —
benar saat diakses lewat nginx di port 8080, tapi saat mengakses Vite langsung
di 5173 permintaan `/api` diteruskan oleh proxy Vite (lihat `vite.config.js`).

---

## 3. Perintah yang perlu dieksekusi

```bash
# Docker
docker compose logs -f frontend
docker compose exec frontend npm install <paket>
docker compose restart frontend

# Lokal
npm run dev        # dev server + HMR
npm run build      # bundel produksi ke dist/
npm run preview    # cek hasil build
```

Build image produksi (nginx statis, tanpa Node saat runtime):

```bash
docker build -f ../infrastructure/docker/frontend/Dockerfile --target prod -t apidisc/frontend:prod .
```

---

## 4. Struktur folder

```
frontend/
├── index.html
├── vite.config.js            port, HMR di balik proxy, proxy /api
└── src/
    ├── main.jsx              entry point
    ├── App.jsx               layout: search bar + hasil + filter + drawer detail
    ├── components/
    │   ├── SearchBar.jsx     input + contoh query siap klik
    │   ├── Filters.jsx       facet kategori/negara, auth, HTTPS, OpenAPI, sorting
    │   ├── ResultList.jsx    daftar hasil, empty state, paginasi
    │   ├── ResultCard.jsx    satu kartu API: badge, skor, highlight
    │   └── ApiDetail.jsx     drawer detail (metadata, health, endpoint)
    ├── hooks/useSearch.js    state query/filter/paging, debounce, abort request
    ├── lib/api.js            satu-satunya tempat yang tahu bentuk endpoint backend
    └── styles/app.css        tema gelap, CSS variables, responsif
```

---

## 5. Cara kerja pencarian di UI

```
ketik  →  debounce 250 ms  →  AbortController membatalkan request sebelumnya
       →  GET /api/search?q=...&filters  →  render hasil + facet
```

- Setiap perubahan query/filter mengembalikan halaman ke 1.
- Selama request berjalan, hasil lama tetap tampil dengan opacity lebih rendah
  (`.results.is-loading`) supaya UI tidak "berkedip".
- `highlight` dari OpenSearch dirender sebagai `<mark>` di deskripsi.
- Badge `driver: database` pada baris meta artinya OpenSearch sedang tidak aktif
  dan backend memakai fallback — hasil tetap muncul, tapi ranking apa adanya.

---

## 6. Kontrak dengan backend

Hanya tiga endpoint yang dipakai (detail: [`../docs/api-contract.md`](../docs/api-contract.md)):

| Fungsi di `lib/api.js` | Endpoint |
|---|---|
| `searchApis(params)` | `GET /api/search` |
| `getApiDetail(slug)` | `GET /api/apis/{slug}` |
| `getMeta()` | `GET /api/meta` |

Facet untuk sidebar diambil dari response `/api/search` (`facets`), bukan dari `/api/meta`,
supaya jumlahnya ikut menyesuaikan hasil pencarian.

---

## 7. Menambah fitur

| Ingin | Sentuh file |
|---|---|
| Filter baru | `Filters.jsx` (UI) → dikirim otomatis oleh `useSearch` → pastikan backend menerimanya di `SearchRequest` |
| Field baru di kartu hasil | `ResultCard.jsx` + pastikan field ada di response `SearchController::presentHit()` |
| Halaman detail ber-URL sendiri | tambahkan `react-router-dom`, ganti drawer dengan route `/api/:slug` |
| Ganti tema/warna | CSS variables di `:root` pada `styles/app.css` |

---

## 8. Troubleshooting

| Gejala | Solusi |
|---|---|
| Halaman blank / 502 dari nginx | Vite masih `npm install` → `docker compose logs -f frontend` |
| HMR tidak jalan | Pastikan `VITE_HMR_CLIENT_PORT` sama dengan `APP_PORT` di `.env` |
| `Failed to fetch` di console | Backend belum siap → `curl http://localhost:8080/api/health` |
| Perubahan file tidak terdeteksi (WSL/macOS) | `usePolling: true` sudah aktif di `vite.config.js`; restart container frontend |
| `node_modules` bentrok host vs container | Volume `frontend-node-modules` memang memisahkannya. Install paket lewat `docker compose exec frontend npm install <paket>` |
