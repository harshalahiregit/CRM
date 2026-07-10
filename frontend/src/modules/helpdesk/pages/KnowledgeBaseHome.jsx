import { useState, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Search, BookOpen, FileText, ChevronRight } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

export default function KnowledgeBaseHome() {
  const [query, setQuery] = useState('')

  const { data: categories = [], isLoading, isError, error } = useQuery({
    queryKey: ['helpdesk-kb'],
    queryFn: helpdeskApi.kb.categories,
  })

  // Client-side search: keep a category if its name matches, or filter its
  // articles down to the ones whose title matches.
  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase()
    if (!q) return categories
    return categories
      .map(cat => {
        const nameHit = cat.name.toLowerCase().includes(q)
        const articles = (cat.articles || []).filter(a => a.title.toLowerCase().includes(q))
        if (nameHit) return cat
        if (articles.length) return { ...cat, articles }
        return null
      })
      .filter(Boolean)
  }, [categories, query])

  return (
    <div className="max-w-4xl mx-auto">
      {/* Hero + prominent search */}
      <div className="text-center mb-8">
        <div className="inline-flex items-center justify-center w-14 h-14 rounded-3xl mb-3"
          style={{ background: 'linear-gradient(135deg,#22d3ee,#0891b2)', boxShadow: '0 8px 24px rgba(6,182,212,0.4)' }}>
          <BookOpen size={26} className="text-white" />
        </div>
        <h1 className="font-black" style={{ fontSize: 'clamp(1.5rem,3vw,2rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
          How can we <span className="text-gradient">help?</span>
        </h1>
        <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>Search our knowledge base or browse by category.</p>

        <div className="relative mt-5 max-w-xl mx-auto">
          <Search size={18} className="absolute left-4 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            type="text"
            value={query}
            onChange={e => setQuery(e.target.value)}
            placeholder="Search articles…"
            className="w-full pl-11 pr-4 py-3.5 rounded-2xl text-sm outline-none border transition-colors focus:border-cyan-400"
            style={{ background: 'var(--bg-card)', borderColor: 'var(--border)', color: 'var(--text-h)' }}
          />
        </div>
      </div>

      {isLoading && (
        <div className="grid gap-5 md:grid-cols-2">
          {[1, 2, 3, 4].map(i => <div key={i} className="skeleton h-40 rounded-2xl" style={{ background: 'var(--border)' }} />)}
        </div>
      )}

      {isError && (
        <div className="p-6 rounded-2xl border" style={{ borderColor: 'rgba(239,68,68,0.3)', background: 'rgba(239,68,68,0.06)' }}>
          <p className="font-semibold text-red-400">Couldn’t load the knowledge base</p>
          <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>{error?.message}</p>
        </div>
      )}

      {!isLoading && !isError && filtered.length === 0 && (
        <p className="text-center text-sm py-10" style={{ color: 'var(--text-muted)' }}>
          {query ? `No articles match “${query}”.` : 'No knowledge base articles yet.'}
        </p>
      )}

      {/* OUTER LOOP: categories */}
      {!isLoading && !isError && (
        <div className="grid gap-5 md:grid-cols-2">
          {filtered.map(category => (
            <section key={category.id} className="rounded-2xl border p-4"
              style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
              <div className="flex items-center gap-2 mb-3 pb-3 border-b" style={{ borderColor: 'var(--border)' }}>
                <h2 className="font-semibold" style={{ color: 'var(--text-h)' }}>{category.name}</h2>
                <span className="ml-auto text-[11px]" style={{ color: 'var(--text-muted)' }}>
                  {(category.articles || []).length} articles
                </span>
              </div>

              {/* INNER LOOP: article links under the category */}
              <ul className="space-y-1">
                {(category.articles || []).map(article => (
                  <li key={article.id}>
                    <a
                      href={`/app/helpdesk/kb/${article.id}`}
                      onClick={e => e.preventDefault()}
                      className="w-full flex items-center gap-2 px-2 py-2 rounded-lg text-sm transition-colors hover:bg-white/[0.04] group"
                      style={{ color: 'var(--text-muted)' }}
                    >
                      <FileText size={14} className="shrink-0 opacity-60 group-hover:opacity-100" />
                      <span className="flex-1 truncate group-hover:text-cyan-300">{article.title}</span>
                      <ChevronRight size={14} className="opacity-40 group-hover:opacity-100" />
                    </a>
                  </li>
                ))}
                {(category.articles || []).length === 0 && (
                  <li className="px-2 py-2 text-xs" style={{ color: 'var(--text-muted)' }}>No articles in this category.</li>
                )}
              </ul>
            </section>
          ))}
        </div>
      )}
    </div>
  )
}
