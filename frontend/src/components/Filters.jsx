const AUTH_OPTIONS = [
  { value: '', label: 'Semua' },
  { value: 'none', label: 'Tanpa auth' },
  { value: 'apiKey', label: 'API Key' },
  { value: 'OAuth', label: 'OAuth' },
  { value: 'bearer', label: 'Bearer' },
]

const SORT_OPTIONS = [
  { value: 'relevance', label: 'Relevansi' },
  { value: 'quality', label: 'Quality score' },
  { value: 'name', label: 'Nama (A-Z)' },
  { value: 'updated', label: 'Terbaru' },
]

export default function Filters({ facets, filters, onChange, onReset, sort, onSortChange }) {
  const categories = facets?.categories ?? []
  const countries = facets?.country ?? []
  const activeCount = Object.keys(filters).length

  return (
    <aside className="filters">
      <div className="filters-head">
        <h2>Filter</h2>
        {activeCount > 0 && (
          <button type="button" className="link" onClick={onReset}>
            Reset ({activeCount})
          </button>
        )}
      </div>

      <label className="field">
        <span>Urutkan</span>
        <select value={sort} onChange={(event) => onSortChange(event.target.value)}>
          {SORT_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </label>

      <label className="field">
        <span>Authentication</span>
        <select value={filters.auth ?? ''} onChange={(event) => onChange('auth', event.target.value)}>
          {AUTH_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </label>

      <label className="checkbox">
        <input
          type="checkbox"
          checked={filters.https === '1'}
          onChange={(event) => onChange('https', event.target.checked ? '1' : '')}
        />
        <span>HTTPS saja</span>
      </label>

      <label className="checkbox">
        <input
          type="checkbox"
          checked={filters.openapi === '1'}
          onChange={(event) => onChange('openapi', event.target.checked ? '1' : '')}
        />
        <span>Punya OpenAPI spec</span>
      </label>

      {categories.length > 0 && (
        <div className="facet">
          <h3>Kategori</h3>
          <ul>
            {categories.slice(0, 12).map((bucket) => {
              const active = filters.category === bucket.value
              return (
                <li key={bucket.value}>
                  <button
                    type="button"
                    className={active ? 'facet-item active' : 'facet-item'}
                    onClick={() => onChange('category', active ? '' : bucket.value)}
                  >
                    <span>{bucket.value}</span>
                    <span className="count">{bucket.count}</span>
                  </button>
                </li>
              )
            })}
          </ul>
        </div>
      )}

      {countries.length > 0 && (
        <div className="facet">
          <h3>Negara</h3>
          <ul>
            {countries.slice(0, 8).map((bucket) => {
              const active = filters.country === bucket.value
              return (
                <li key={bucket.value}>
                  <button
                    type="button"
                    className={active ? 'facet-item active' : 'facet-item'}
                    onClick={() => onChange('country', active ? '' : bucket.value)}
                  >
                    <span>{bucket.value}</span>
                    <span className="count">{bucket.count}</span>
                  </button>
                </li>
              )
            })}
          </ul>
        </div>
      )}
    </aside>
  )
}
