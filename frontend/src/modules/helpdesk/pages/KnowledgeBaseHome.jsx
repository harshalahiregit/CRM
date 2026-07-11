import { useState, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Search, ChevronRight, FileText, ThumbsUp, BookOpen, CreditCard, Wrench, Rocket, LifeBuoy, Settings2, Users, Zap } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

/* A clean, light, Freshdesk-style Help Center rendered on the existing KB engine.
   Self-contained light theme (help centers are light) so it reads as a real
   support portal regardless of the app's dark chrome. */

const CAT_ICONS = [BookOpen, CreditCard, Wrench, Rocket, LifeBuoy, Settings2, Users, Zap]
const CAT_TINTS = [
  { bg: '#eef4ff', fg: '#3b6fed' }, { bg: '#eafff4', fg: '#16a34a' },
  { bg: '#fff1ec', fg: '#ea6b3a' }, { bg: '#f3eefe', fg: '#7c3aed' },
  { bg: '#eafcff', fg: '#0891b2' }, { bg: '#fef6e7', fg: '#d97706' },
]

const normalize = (raw) => (Array.isArray(raw) ? raw : raw?.data || [])

export default function KnowledgeBaseHome() {
  const navigate = useNavigate()
  const [query, setQuery] = useState('')

  const { data: catsRaw = [], isLoading } = useQuery({ queryKey: ['kb-categories'], queryFn: helpdeskApi.kb.categories })
  const { data: artsRaw = [] } = useQuery({ queryKey: ['kb-articles-all'], queryFn: () => helpdeskApi.kb.articles() })

  const published = useMemo(() => normalize(artsRaw).filter(a => a.is_published), [artsRaw])
  const categories = normalize(catsRaw)

  // Group published articles under their category.
  const grouped = useMemo(() => {
    return categories.map(cat => ({
      ...cat,
      articles: published.filter(a => a.category_id === cat.id),
    })).filter(c => c.articles.length > 0)
  }, [categories, published])

  const popular = useMemo(() => [...published].sort((a, b) => (b.thumbs_up || 0) - (a.thumbs_up || 0)).slice(0, 5), [published])

  const q = query.trim().toLowerCase()
  const searchResults = q
    ? published.filter(a => a.title.toLowerCase().includes(q) || (a.excerpt || '').toLowerCase().includes(q))
    : null

  return (
    <div className="-m-4 md:-m-6" style={{ background: '#f4f6fb', minHeight: 'calc(100vh - 120px)', color: '#1a2b4a' }}>
      {/* Hero */}
      <div style={{ background: 'linear-gradient(135deg,#3b6fed 0%,#5b4bd6 100%)', padding: '48px 24px 64px' }}>
        <div className="max-w-3xl mx-auto text-center">
          <h1 style={{ color: '#fff', fontSize: 'clamp(1.6rem,3.4vw,2.4rem)', fontWeight: 800, letterSpacing: '-0.02em' }}>
            How can we help you?
          </h1>
          <p style={{ color: 'rgba(255,255,255,0.85)', marginTop: 8, fontSize: 15 }}>
            Search for answers or browse the topics below.
          </p>
          <div className="relative mt-6 max-w-2xl mx-auto">
            <Search size={20} style={{ position: 'absolute', left: 18, top: '50%', transform: 'translateY(-50%)', color: '#8a93a8' }} />
            <input
              value={query}
              onChange={e => setQuery(e.target.value)}
              placeholder="Search the knowledge base…"
              autoFocus
              style={{ width: '100%', padding: '16px 18px 16px 48px', borderRadius: 14, border: 'none', fontSize: 15, outline: 'none', color: '#1a2b4a', boxShadow: '0 12px 30px rgba(20,30,60,0.18)' }}
            />
          </div>
        </div>
      </div>

      <div className="max-w-5xl mx-auto px-4 md:px-6" style={{ marginTop: -32, paddingBottom: 48 }}>
        {/* SEARCH RESULTS */}
        {searchResults && (
          <Panel>
            <SectionTitle>{searchResults.length} result{searchResults.length !== 1 ? 's' : ''} for “{query}”</SectionTitle>
            {searchResults.length === 0 && <Empty>Nothing matched. Try different keywords.</Empty>}
            <ul>
              {searchResults.map(a => <ArticleRow key={a.id} article={a} onClick={() => navigate(`/app/helpdesk/knowledge-base/${a.id}`)} showCat />)}
            </ul>
          </Panel>
        )}

        {/* BROWSE (hidden while searching) */}
        {!searchResults && (
          <>
            {isLoading && <div className="grid gap-5 md:grid-cols-2">{[1, 2, 3, 4].map(i => <div key={i} style={{ height: 190, borderRadius: 16, background: '#e9edf5' }} className="animate-pulse" />)}</div>}

            {!isLoading && grouped.length === 0 && (
              <Panel><Empty>No published articles yet. Publish articles from KB Admin and they’ll appear here.</Empty></Panel>
            )}

            {!isLoading && grouped.length > 0 && (
              <>
                <div className="grid gap-5 md:grid-cols-2">
                  {grouped.map((cat, i) => {
                    const Icon = CAT_ICONS[i % CAT_ICONS.length]
                    const tint = CAT_TINTS[i % CAT_TINTS.length]
                    return (
                      <section key={cat.id} style={cardStyle}>
                        <div className="flex items-center gap-3 mb-3">
                          <span style={{ width: 44, height: 44, borderRadius: 12, background: tint.bg, color: tint.fg, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                            <Icon size={22} />
                          </span>
                          <div>
                            <h2 style={{ fontWeight: 700, fontSize: 16, color: '#16233d' }}>{cat.name}</h2>
                            <p style={{ fontSize: 12.5, color: '#7a879e' }}>{cat.articles.length} article{cat.articles.length !== 1 ? 's' : ''}</p>
                          </div>
                        </div>
                        <ul>
                          {cat.articles.slice(0, 4).map(a => (
                            <ArticleRow key={a.id} article={a} onClick={() => navigate(`/app/helpdesk/knowledge-base/${a.id}`)} compact />
                          ))}
                        </ul>
                        {cat.articles.length > 4 && (
                          <button onClick={() => setQuery(cat.name)} style={{ marginTop: 8, fontSize: 13, fontWeight: 600, color: '#3b6fed' }}>
                            See all {cat.articles.length} →
                          </button>
                        )}
                      </section>
                    )
                  })}
                </div>

                {/* Popular */}
                {popular.length > 0 && (
                  <Panel className="mt-6">
                    <SectionTitle>Popular articles</SectionTitle>
                    <ul>
                      {popular.map(a => <ArticleRow key={a.id} article={a} onClick={() => navigate(`/app/helpdesk/knowledge-base/${a.id}`)} showThumbs showCat />)}
                    </ul>
                  </Panel>
                )}
              </>
            )}
          </>
        )}
      </div>
    </div>
  )
}

/* ── Small building blocks ─────────────────────────────────── */
const cardStyle = { background: '#fff', border: '1px solid #e7eaf2', borderRadius: 16, padding: 20, boxShadow: '0 1px 2px rgba(20,30,60,0.04)' }

function Panel({ children, className = '' }) {
  return <div className={className} style={cardStyle}>{children}</div>
}
function SectionTitle({ children }) {
  return <h2 style={{ fontWeight: 700, fontSize: 15, marginBottom: 12, color: '#1a2b4a' }}>{children}</h2>
}
function Empty({ children }) {
  return <p style={{ fontSize: 14, color: '#7a879e', padding: '16px 0' }}>{children}</p>
}

function ArticleRow({ article, onClick, compact, showThumbs, showCat }) {
  return (
    <li>
      <button onClick={onClick}
        className="group"
        style={{ width: '100%', display: 'flex', alignItems: 'center', gap: 10, padding: compact ? '7px 8px' : '11px 8px', borderRadius: 10, textAlign: 'left', color: '#38455f' }}
        onMouseEnter={e => e.currentTarget.style.background = '#f2f5fb'}
        onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
        <FileText size={15} style={{ color: '#9aa4ba', flexShrink: 0 }} />
        <span style={{ flex: 1, fontSize: 14, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
          {article.title}
          {showCat && article.category?.name && <span style={{ color: '#9aa4ba', fontSize: 12 }}> · {article.category.name}</span>}
        </span>
        {showThumbs && (article.thumbs_up > 0) && (
          <span style={{ display: 'flex', alignItems: 'center', gap: 3, fontSize: 12, color: '#16a34a' }}><ThumbsUp size={12} />{article.thumbs_up}</span>
        )}
        <ChevronRight size={15} style={{ color: '#c2cbdc' }} />
      </button>
    </li>
  )
}
