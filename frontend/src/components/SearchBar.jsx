export default function SearchBar({ value, onChange, loading }) {
  const examples = [
    'weather API Indonesia',
    'API gratis untuk cek kurs USD ke IDR',
    'API untuk mendapatkan data gempa Indonesia',
    'free stock API Indonesia',
  ]

  return (
    <div className="search-bar">
      <div className="search-input-wrap">
        <span className="search-icon" aria-hidden="true">⌕</span>
        <input
          type="search"
          className="search-input"
          value={value}
          autoFocus
          placeholder="Cari public API… mis. 'API cuaca besok' atau 'kurs USD ke IDR'"
          aria-label="Search public APIs"
          onChange={(event) => onChange(event.target.value)}
        />
        {loading && <span className="spinner" aria-label="Loading" />}
      </div>

      <div className="examples">
        <span>Coba:</span>
        {examples.map((example) => (
          <button key={example} type="button" className="chip" onClick={() => onChange(example)}>
            {example}
          </button>
        ))}
      </div>
    </div>
  )
}
