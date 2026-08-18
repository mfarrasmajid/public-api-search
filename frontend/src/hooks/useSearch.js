import { useCallback, useEffect, useRef, useState } from 'react'
import { searchApis } from '../lib/api.js'

const DEBOUNCE_MS = 250

/**
 * Owns search state: query, filters, paging and the in-flight request.
 * Requests are debounced and the previous one is aborted, so typing fast
 * never renders stale results.
 */
export function useSearch(initialQuery = '') {
  const [query, setQuery] = useState(initialQuery)
  const [filters, setFilters] = useState({})
  const [sort, setSort] = useState('relevance')
  const [page, setPage] = useState(1)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)

  const controllerRef = useRef(null)

  const run = useCallback(() => {
    controllerRef.current?.abort()
    const controller = new AbortController()
    controllerRef.current = controller

    setLoading(true)
    setError(null)

    searchApis({ q: query, sort, page, per_page: 20, ...filters }, controller.signal)
      .then((result) => setData(result))
      .catch((err) => {
        if (err.name !== 'AbortError') setError(err.message)
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false)
      })
  }, [query, filters, sort, page])

  useEffect(() => {
    const timer = setTimeout(run, DEBOUNCE_MS)
    return () => clearTimeout(timer)
  }, [run])

  // Any change to the query or filters puts the user back on page 1.
  const updateFilter = useCallback((key, value) => {
    setPage(1)
    setFilters((current) => {
      const next = { ...current }
      if (value === null || value === undefined || value === '') {
        delete next[key]
      } else {
        next[key] = value
      }
      return next
    })
  }, [])

  const updateQuery = useCallback((value) => {
    setPage(1)
    setQuery(value)
  }, [])

  const resetFilters = useCallback(() => {
    setPage(1)
    setFilters({})
  }, [])

  return {
    query, updateQuery,
    filters, updateFilter, resetFilters,
    sort, setSort,
    page, setPage,
    data, loading, error,
  }
}
