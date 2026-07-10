import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useParams, Link } from 'react-router-dom'
import { Search, BookOpen, FileText, ChevronRight } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

/**
 * Public, no-auth knowledge base browse page, scoped by the tenant's widget key.
 * Category tiles → sub-sections → published articles, with a search bar.
 */
export default function PublicKb() {
  const { key } = useParams()
  const [query, setQuery] = useState('')

  const { data: tree = [], isLoading, isError } = useQuery({
    queryKey: ['public-kb', key],
    queryFn: () => helpdeskApi.public.kbTree(key),
  })

  const { data: results = [] } = useQuery({
    queryKey: ['public-kb-search', key, query],
    queryFn: () => helpdeskApi.public.kbSearch(key, query),
    enabled: query.trim().length > 1,
  })

  const searching = query.trim().length > 1

  return (
    <div style={{ minHeight: '100vh', background: '#f8fafc' }}>
      <div className="max-w-4xl mx-auto px-5 py-12">
        <div className="text-center mb-8">
          <div className="inline-flex items-center justify-center w-14 h-14 rounded-3xl mb-3" style={{ background: 'linear-gradient(135deg,#06b6d4,#0891b2)' }}>
            <BookOpen size={26} className="text-white" />
          </div>
          <h1 className="text-3xl font-black text-slate-900">Help Center</h1>
          <p className="text-slate-500 mt-1">Search articles or browse by topic.</p>
          <div className="relative mt-5 max-w-xl mx-auto">
            <Search size={18} className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" />
            <input value={query} onChange={e => setQuery(e.target.value)} placeholder="Search articles…"
              className="w-full pl-11 pr-4 py-3.5 rounded-2xl text-sm outline-none border border-slate-200 bg-white focus:border-cyan-400" />
          </div>
        </div>

        {isError && <p className="text-center text-slate-500">This help center is not available.</p>}
        {isLoading && <div className="grid gap-5 md:grid-cols-2">{[1, 2, 3, 4].map(i => <div key={i} className="h-40 rounded-2xl bg-slate-100 animate-pulse" />)}</div>}

        {/* Search results */}
        {searching && (
          <div className="bg-white rounded-2xl border border-slate-200 divide-y divide-slate-100">
            {results.length === 0 && <p className="p-5 text-sm text-slate-500">No articles match “{query}”.</p>}
            {results.map(a => (
              <Link key={a.id} to={`/kb/a/${a.public_slug}`} className="flex items-center gap-2 px-5 py-3 hover:bg-slate-50 text-slate-700">
                <FileText size={15} className="text-slate-400" />
                <span className="flex-1 truncate text-sm">{a.title}</span>
                <ChevronRight size={15} className="text-slate-300" />
              </Link>
            ))}
          </div>
        )}

        {/* Category tiles */}
        {!searching && !isLoading && !isError && (
          <div className="grid gap-5 md:grid-cols-2">
            {tree.map(cat => (
              <section key={cat.id} className="bg-white rounded-2xl border border-slate-200 p-5">
                <h2 className="font-bold text-slate-900 mb-3">{cat.name}</h2>
                {(cat.subcategories || []).map(sub => (
                  <div key={sub.id} className="mb-3">
                    <p className="text-xs font-semibold uppercase tracking-wide text-slate-400 mb-1">{sub.name}</p>
                    <ul className="space-y-0.5">
                      {(sub.articles || []).map(a => (
                        <li key={a.id}>
                          <Link to={`/kb/a/${a.public_slug}`} className="flex items-center gap-2 px-2 py-1.5 rounded-lg text-sm text-slate-600 hover:bg-slate-50 hover:text-cyan-700">
                            <FileText size={14} className="text-slate-400" />
                            <span className="flex-1 truncate">{a.title}</span>
                          </Link>
                        </li>
                      ))}
                      {(sub.articles || []).length === 0 && <li className="text-xs text-slate-400 px-2">No published articles.</li>}
                    </ul>
                  </div>
                ))}
                {(cat.subcategories || []).length === 0 && <p className="text-xs text-slate-400">Coming soon.</p>}
              </section>
            ))}
            {tree.length === 0 && <p className="text-slate-500">No help topics yet.</p>}
          </div>
        )}
      </div>
    </div>
  )
}
