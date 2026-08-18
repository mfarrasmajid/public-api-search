import { useState } from 'react'
import SearchBar from './components/SearchBar.jsx'
import Filters from './components/Filters.jsx'
import ResultList from './components/ResultList.jsx'
import ApiDetail from './components/ApiDetail.jsx'
import { useSearch } from './hooks/useSearch.js'

export default function App() {
  const search = useSearch('')
  const [selected, setSelected] = useState(null)

  return (
    <div className="app">
      <header className="header">
        <div className="brand">
          <h1>Public API Discovery Engine</h1>
          <p>Cari public API dengan satu query — metadata, dokumentasi, auth, dan quality score.</p>
        </div>
      </header>

      <main className="layout">
        <div className="main-col">
          <SearchBar value={search.query} onChange={search.updateQuery} loading={search.loading} />
          <ResultList
            data={search.data}
            loading={search.loading}
            error={search.error}
            onSelect={setSelected}
            onPage={search.setPage}
          />
        </div>

        <Filters
          facets={search.data?.facets}
          filters={search.filters}
          onChange={search.updateFilter}
          onReset={search.resetFilters}
          sort={search.sort}
          onSortChange={search.setSort}
        />
      </main>

      <ApiDetail slug={selected} onClose={() => setSelected(null)} />

      <footer className="footer">
        POC lokal · Laravel + PostgreSQL + OpenSearch · data dari direktori public API terbuka
      </footer>
    </div>
  )
}
