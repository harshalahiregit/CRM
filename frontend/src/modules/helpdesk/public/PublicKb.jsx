import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useParams, Link, useSearchParams } from 'react-router-dom'
import { Search, BookOpen, FileText, ChevronRight, Headphones } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

/**
 * Public, no-auth knowledge base browse page, scoped by tenant's widget key.
 * Accepts ?q= so a search started from an article page (PublicArticle) carries
 * over to the home.
 */
export default function PublicKb() {
  const { key } = useParams()
  const [searchParams] = useSearchParams()
  const [query, setQuery] = useState(searchParams.get('q') || '')

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
    <div style={{ minHeight: '100vh', background: '#f0f4ff', fontFamily: 'Inter, system-ui, sans-serif' }}>
      {/* Hero */}
      <div
        style={{
          background: 'linear-gradient(135deg, #0e7490 0%, #0891b2 50%, #06b6d4 100%)',
          padding: '56px 24px 80px',
          position: 'relative',
          overflow: 'hidden',
        }}
      >
        {/* Decorative circles */}
        <div style={{
          position: 'absolute', top: -80, right: -80, width: 320, height: 320,
          borderRadius: '50%', background: 'rgba(255,255,255,0.05)', pointerEvents: 'none',
        }} />
        <div style={{
          position: 'absolute', bottom: -60, left: '20%', width: 200, height: 200,
          borderRadius: '50%', background: 'rgba(255,255,255,0.04)', pointerEvents: 'none',
        }} />

        <div style={{ maxWidth: 640, margin: '0 auto', textAlign: 'center', position: 'relative', zIndex: 1 }}>
          <div style={{
            display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
            width: 64, height: 64, borderRadius: 20, marginBottom: 18,
            background: 'rgba(255,255,255,0.15)', backdropFilter: 'blur(10px)',
            border: '1px solid rgba(255,255,255,0.2)',
          }}>
            <Headphones size={30} style={{ color: '#fff' }} />
          </div>
          <h1 style={{
            color: '#fff', fontSize: 'clamp(1.8rem, 4vw, 2.8rem)',
            fontWeight: 900, letterSpacing: '-0.025em', lineHeight: 1.15,
          }}>
            Help Center
          </h1>
          <p style={{ color: 'rgba(255,255,255,0.75)', marginTop: 10, fontSize: 15.5 }}>
            Search articles or browse by topic
          </p>

          {/* Search */}
          <div style={{ position: 'relative', marginTop: 24, maxWidth: 520, margin: '24px auto 0' }}>
            <Search size={18} style={{
              position: 'absolute', left: 18, top: '50%',
              transform: 'translateY(-50%)', color: '#94a3b8',
            }} />
            <input
              value={query}
              onChange={e => setQuery(e.target.value)}
              placeholder="Search articles…"
              style={{
                width: '100%', padding: '16px 18px 16px 50px',
                borderRadius: 16, border: 'none', fontSize: 15, outline: 'none',
                color: '#1e293b', boxShadow: '0 16px 40px rgba(0,0,0,0.2)',
                background: '#fff',
              }}
            />
          </div>
        </div>
      </div>

      <div style={{ maxWidth: 900, margin: '0 auto', padding: '0 24px 56px', marginTop: -40 }}>

        {isError && (
          <div style={{
            textAlign: 'center', padding: '40px 20px', background: '#fff',
            borderRadius: 16, border: '1px solid #e2e8f0', color: '#64748b',
          }}>
            This help center is not available.
          </div>
        )}

        {isLoading && (
          <div style={{ display: 'grid', gap: 20, gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))' }}>
            {[1, 2, 3, 4].map(i => (
              <div key={i} style={{ height: 180, borderRadius: 16, background: '#e2e8f0', animation: 'pulse 1.5s infinite' }} />
            ))}
          </div>
        )}

        {/* Search results */}
        {searching && (
          <div style={{ background: '#fff', borderRadius: 16, border: '1px solid #e2e8f0', overflow: 'hidden', boxShadow: '0 4px 20px rgba(0,0,0,0.06)' }}>
            {results.length === 0 && (
              <p style={{ padding: '24px 20px', color: '#94a3b8', textAlign: 'center', fontSize: 14 }}>
                No articles match &ldquo;{query}&rdquo;
              </p>
            )}
            {results.map(a => (
              <Link
                key={a.id}
                to={`/kb/a/${a.public_slug}`}
                style={{
                  display: 'flex', alignItems: 'center', gap: 12,
                  padding: '14px 20px', color: '#334155', textDecoration: 'none',
                  borderBottom: '1px solid #f1f5f9',
                  transition: 'background 0.15s',
                }}
                onMouseEnter={e => e.currentTarget.style.background = '#f8faff'}
                onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
              >
                <FileText size={16} style={{ color: '#94a3b8', flexShrink: 0 }} />
                <span style={{ flex: 1, fontSize: 14, fontWeight: 500 }}>{a.title}</span>
                <ChevronRight size={15} style={{ color: '#cbd5e1' }} />
              </Link>
            ))}
          </div>
        )}

        {/* Category tiles */}
        {!searching && !isLoading && !isError && (
          <div style={{ display: 'grid', gap: 20, gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))' }}>
            {tree.map((cat, i) => {
              const COLORS = [
                { bg: '#ecfeff', fg: '#0891b2' },
                { bg: '#f0fdf4', fg: '#16a34a' },
                { bg: '#fff7ed', fg: '#ea580c' },
                { bg: '#faf5ff', fg: '#7c3aed' },
                { bg: '#eef4ff', fg: '#2563eb' },
              ]
              const c = COLORS[i % COLORS.length]
              return (
                <section key={cat.id} style={{
                  background: '#fff', borderRadius: 16,
                  border: '1px solid #e2e8f0', padding: 20,
                  boxShadow: '0 2px 8px rgba(0,0,0,0.04)',
                }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16 }}>
                    <div style={{
                      width: 42, height: 42, borderRadius: 12, display: 'flex',
                      alignItems: 'center', justifyContent: 'center',
                      background: c.bg, flexShrink: 0,
                    }}>
                      <BookOpen size={20} style={{ color: c.fg }} />
                    </div>
                    <div>
                      <h2 style={{ fontWeight: 700, fontSize: 16, color: '#0f172a', margin: 0 }}>{cat.name}</h2>
                    </div>
                  </div>

                  {(cat.subcategories || []).map(sub => (
                    <div key={sub.id} style={{ marginBottom: 12 }}>
                      <p style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.06em', color: '#94a3b8', marginBottom: 6 }}>
                        {sub.name}
                      </p>
                      <ul style={{ listStyle: 'none', padding: 0, margin: 0 }}>
                        {(sub.articles || []).map(a => (
                          <li key={a.id}>
                            <Link
                              to={`/kb/a/${a.public_slug}`}
                              style={{
                                display: 'flex', alignItems: 'center', gap: 8,
                                padding: '7px 8px', borderRadius: 10, fontSize: 14,
                                color: '#334155', textDecoration: 'none',
                                transition: 'background 0.15s',
                              }}
                              onMouseEnter={e => { e.currentTarget.style.background = '#f8faff'; e.currentTarget.style.color = c.fg }}
                              onMouseLeave={e => { e.currentTarget.style.background = 'transparent'; e.currentTarget.style.color = '#334155' }}
                            >
                              <FileText size={14} style={{ color: '#94a3b8', flexShrink: 0 }} />
                              <span style={{ flex: 1 }}>{a.title}</span>
                            </Link>
                          </li>
                        ))}
                        {(sub.articles || []).length === 0 && (
                          <li style={{ fontSize: 12, color: '#94a3b8', paddingLeft: 8 }}>No published articles.</li>
                        )}
                      </ul>
                    </div>
                  ))}

                  {(cat.subcategories || []).length === 0 && (
                    <p style={{ fontSize: 13, color: '#94a3b8' }}>Coming soon.</p>
                  )}
                </section>
              )
            })}
            {tree.length === 0 && (
              <p style={{ gridColumn: '1/-1', textAlign: 'center', color: '#94a3b8', padding: '40px 0' }}>
                No help topics available yet.
              </p>
            )}
          </div>
        )}
      </div>
    </div>
  )
}
