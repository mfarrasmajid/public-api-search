// Thin fetch wrapper. Everything the UI knows about the backend lives here,
// so swapping the transport later touches one file.

const BASE_URL = import.meta.env.VITE_API_BASE_URL || ''

async function request(path, params = {}, signal) {
  const url = new URL(`${BASE_URL}/api${path}`, window.location.origin)

  Object.entries(params).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') {
      url.searchParams.set(key, value)
    }
  })

  const response = await fetch(url, { signal, headers: { Accept: 'application/json' } })

  if (!response.ok) {
    const body = await response.json().catch(() => ({}))
    throw new Error(body.message || `Request failed with status ${response.status}`)
  }

  return response.json()
}

export const searchApis = (params, signal) => request('/search', params, signal)
export const getApiDetail = (slug, signal) => request(`/apis/${slug}`, {}, signal)
export const getMeta = (signal) => request('/meta', {}, signal)
