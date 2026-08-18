import { useEffect, useState } from 'react'
import { getApiDetail } from '../lib/api.js'

export default function ApiDetail({ slug, onClose }) {
  const [api, setApi] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    if (!slug) return undefined

    const controller = new AbortController()
    setApi(null)
    setError(null)

    getApiDetail(slug, controller.signal)
      .then((response) => setApi(response.data))
      .catch((err) => {
        if (err.name !== 'AbortError') setError(err.message)
      })

    return () => controller.abort()
  }, [slug])

  useEffect(() => {
    const onKey = (event) => event.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  if (!slug) return null

  return (
    <div className="drawer-backdrop" onClick={onClose} role="presentation">
      <aside className="drawer" onClick={(event) => event.stopPropagation()}>
        <button type="button" className="drawer-close" onClick={onClose} aria-label="Tutup">×</button>

        {error && <p className="state error">{error}</p>}
        {!api && !error && <p className="state">Memuat…</p>}

        {api && (
          <>
            <h2>{api.name}</h2>
            <p className="drawer-desc">{api.description}</p>

            <dl className="meta-grid">
              <div><dt>Kategori</dt><dd>{api.category ?? '-'}</dd></div>
              <div><dt>Provider</dt><dd>{api.provider ?? '-'}</dd></div>
              <div><dt>Authentication</dt><dd>{api.authentication}</dd></div>
              <div><dt>HTTPS</dt><dd>{api.https ? 'Ya' : 'Tidak'}</dd></div>
              <div><dt>CORS</dt><dd>{api.cors}</dd></div>
              <div><dt>Negara</dt><dd>{api.country ?? '-'}</dd></div>
              <div><dt>Quality score</dt><dd>{api.quality_score}/100</dd></div>
              <div><dt>Status</dt><dd>{api.status}</dd></div>
            </dl>

            {api.health && (
              <p className="health">
                Health: <strong>{api.health.status}</strong>
                {api.health.response_time_ms != null && ` · ${api.health.response_time_ms} ms`}
                {api.health.checked_at && ` · dicek ${new Date(api.health.checked_at).toLocaleString('id-ID')}`}
              </p>
            )}

            <div className="drawer-links">
              {api.documentation_url && <a href={api.documentation_url} target="_blank" rel="noreferrer noopener">Dokumentasi ↗</a>}
              {api.website && <a href={api.website} target="_blank" rel="noreferrer noopener">Website ↗</a>}
              {api.openapi_url && <a href={api.openapi_url} target="_blank" rel="noreferrer noopener">OpenAPI spec ↗</a>}
            </div>

            {api.base_url && (
              <div className="code-block">
                <span>Base URL</span>
                <code>{api.base_url}</code>
              </div>
            )}

            {api.tags?.length > 0 && (
              <div className="badges">
                {api.tags.map((tag) => <span key={tag} className="badge muted">{tag}</span>)}
              </div>
            )}

            {api.endpoints?.length > 0 && (
              <div className="endpoints">
                <h3>Endpoints ({api.endpoints.length})</h3>
                <ul>
                  {api.endpoints.map((endpoint) => (
                    <li key={`${endpoint.method}-${endpoint.path}`}>
                      <span className="method">{endpoint.method}</span>
                      <code>{endpoint.path}</code>
                      {endpoint.description && <p>{endpoint.description}</p>}
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </>
        )}
      </aside>
    </div>
  )
}
