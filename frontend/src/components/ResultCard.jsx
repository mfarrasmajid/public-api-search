const AUTH_LABEL = {
  none: 'Tanpa auth',
  apiKey: 'API Key',
  OAuth: 'OAuth',
  bearer: 'Bearer',
  unknown: 'Auth ?',
}

function scoreClass(score) {
  if (score >= 75) return 'score good'
  if (score >= 50) return 'score ok'
  return 'score low'
}

export default function ResultCard({ hit, onSelect }) {
  return (
    <li className="card">
      <div className="card-head">
        <button type="button" className="card-title" onClick={() => onSelect(hit.slug)}>
          {hit.name}
        </button>
        <span className={scoreClass(hit.quality_score)} title="Quality score 0-100">
          {hit.quality_score}
        </span>
      </div>

      <p
        className="card-desc"
        dangerouslySetInnerHTML={{ __html: hit.highlight || hit.description || '' }}
      />

      <div className="badges">
        {hit.category && <span className="badge cat">{hit.category}</span>}
        <span className="badge">{AUTH_LABEL[hit.authentication] ?? hit.authentication}</span>
        <span className={hit.https ? 'badge ok' : 'badge warn'}>{hit.https ? 'HTTPS' : 'HTTP'}</span>
        {hit.cors === 'yes' && <span className="badge ok">CORS</span>}
        {hit.has_openapi && <span className="badge ok">OpenAPI</span>}
        {hit.country && <span className="badge muted">{hit.country}</span>}
        {hit.health_status === 'healthy' && <span className="badge ok">Operational</span>}
        {hit.response_time_ms != null && <span className="badge muted">{hit.response_time_ms} ms</span>}
        {hit.score != null && <span className="badge muted" title="Relevance score">≈ {hit.score}</span>}
      </div>

      <div className="card-links">
        {hit.documentation_url && (
          <a href={hit.documentation_url} target="_blank" rel="noreferrer noopener">Dokumentasi ↗</a>
        )}
        <button type="button" className="link" onClick={() => onSelect(hit.slug)}>Detail</button>
      </div>
    </li>
  )
}
