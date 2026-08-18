import ResultCard from './ResultCard.jsx'

export default function ResultList({ data, loading, error, onSelect, onPage }) {
  if (error) {
    return (
      <div className="state error">
        <strong>Gagal memuat hasil.</strong>
        <p>{error}</p>
        <p className="hint">Cek backend: <code>docker compose logs -f backend</code></p>
      </div>
    )
  }

  if (!data && loading) {
    return <div className="state">Mencari…</div>
  }

  if (!data) return null

  if (data.total === 0) {
    return (
      <div className="state">
        <strong>Tidak ada hasil untuk “{data.query}”.</strong>
        <p className="hint">
          Coba kata kunci lain, atau pastikan index sudah dibuat:
          <code>docker compose exec backend php artisan search:reindex</code>
        </p>
      </div>
    )
  }

  const lastPage = Math.ceil(data.total / data.per_page)

  return (
    <div className={loading ? 'results is-loading' : 'results'}>
      <div className="results-meta">
        <span><strong>{data.total}</strong> API ditemukan</span>
        <span className="muted">{data.took_ms} ms · driver: {data.driver}</span>
      </div>

      <ul className="cards">
        {data.results.map((hit) => (
          <ResultCard key={hit.slug} hit={hit} onSelect={onSelect} />
        ))}
      </ul>

      {lastPage > 1 && (
        <div className="pagination">
          <button type="button" disabled={data.page <= 1} onClick={() => onPage(data.page - 1)}>← Sebelumnya</button>
          <span>Halaman {data.page} / {lastPage}</span>
          <button type="button" disabled={data.page >= lastPage} onClick={() => onPage(data.page + 1)}>Berikutnya →</button>
        </div>
      )}
    </div>
  )
}
