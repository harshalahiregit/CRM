import { useState, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import {
  Search, ChevronRight, FileText, ThumbsUp, ArrowRight,
  BookOpen, CreditCard, Wrench, Rocket, LifeBuoy, Settings2, Users, Zap,
} from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'
import RaiseTicketModal from '../components/RaiseTicketModal'

/* Professional, theme-aware Knowledge Base home built on the existing KB engine.
   Reading typography via .font-display headings + generous sizes. */

const CAT_ICONS = [BookOpen, CreditCard, Wrench, Rocket, LifeBuoy, Settings2, Users, Zap]
const CAT_TINTS = [
  { fg: 'var(--color-support-500)' }, { fg: 'var(--color-success-500)' },
  { fg: 'var(--color-warning-500)' }, { fg: 'var(--color-primary-500)' },
  { fg: 'var(--color-info-500)' }, { fg: 'var(--color-knowledge-500)' },
]
const tint = i => CAT_TINTS[i % CAT_TINTS.length]
const normalize = (raw) => (Array.isArray(raw) ? raw : raw?.data || [])
const readTime = (html) => Math.max(1, Math.round((html || '').replace(/<[^>]*>/g, ' ').split(/\s+/).length / 200))

export default function KnowledgeBaseHome() {
  const navigate = useNavigate()
  const [query, setQuery] = useState('')
  const [raiseOpen, setRaiseOpen] = useState(false)

  const { data: catsRaw = [], isLoading } = useQuery({ queryKey: ['kb-categories'], queryFn: helpdeskApi.kb.categories })
  const { data: artsRaw = [] } = useQuery({ queryKey: ['kb-articles-all'], queryFn: () => helpdeskApi.kb.articles() })

  const published = useMemo(() => normalize(artsRaw).filter(a => a.is_published), [artsRaw])
  const categories = normalize(catsRaw)

  const grouped = useMemo(() => categories.map(cat => ({
    ...cat, articles: published.filter(a => a.category_id === cat.id),
  })).filter(c => c.articles.length > 0), [categories, published])

  const popular = useMemo(() => [...published].sort((a, b) => (b.thumbs_up || 0) - (a.thumbs_up || 0)).slice(0, 5), [published])

  const q = query.trim().toLowerCase()
  const results = q
    ? published.filter(a => a.title.toLowerCase().includes(q) || (a.excerpt || '').toLowerCase().includes(q)).slice(0, 6)
    : null

  const open = (a) => navigate(`/app/helpdesk/knowledge-base/${a.id}`)

  return (
    <div className="pb-10">
      {/* ── Hero ── */}
      <div className="relative overflow-hidden rounded-3xl px-6 py-12 md:py-16"
        style={{ background: 'linear-gradient(135deg, var(--color-support-600) 0%, var(--color-primary-600) 100%)' }}>
        <div className="absolute rounded-full" style={{ width: 360, height: 360, top: -120, right: -80, background: 'rgba(255,255,255,0.07)' }} />
        <div className="absolute rounded-full" style={{ width: 220, height: 220, bottom: -100, left: '15%', background: 'rgba(255,255,255,0.05)' }} />

        <div className="relative max-w-2xl mx-auto text-center">
          <h1 className="font-display font-extrabold text-white" style={{ fontSize: 'clamp(1.9rem,4vw,3rem)', letterSpacing: '-0.03em', lineHeight: 1.1 }}>
            How can we help?
          </h1>
          <p className="mt-3 text-white/80" style={{ fontSize: 17 }}>
            Search our knowledge base or browse topics below.
          </p>
          <button onClick={() => setRaiseOpen(true)}
            className="mt-4 inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold transition-transform hover:scale-[1.03]"
            style={{ background: '#fff', color: 'var(--color-support-600)' }}>
            <LifeBuoy size={15} /> Can't find it? Raise a ticket
          </button>

          {/* Search + live dropdown */}
          <div className="relative mt-7 max-w-xl mx-auto text-left">
            <Search size={20} style={{ position: 'absolute', left: 18, top: 19, color: '#8a93a8' }} />
            <input value={query} onChange={e => setQuery(e.target.value)} placeholder="Search for answers…" autoFocus
              style={{ width: '100%', padding: '17px 18px 17px 50px', borderRadius: 16, border: 'none', fontSize: 16, outline: 'none', color: '#16233d', background: '#fff', boxShadow: '0 18px 44px rgba(15,23,42,0.22)' }} />
            {results && (
              <div className="absolute left-0 right-0 mt-2 rounded-2xl overflow-hidden z-20"
                style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}>
                {results.length === 0 && <p className="px-4 py-4 text-sm" style={{ color: 'var(--text-muted)' }}>No articles match “{query}”.</p>}
                {results.map(a => (
                  <button key={a.id} onClick={() => open(a)} className="w-full flex items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-[var(--bg-input)]">
                    <FileText size={16} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                    <div className="min-w-0">
                      <p className="text-sm font-semibold truncate" style={{ color: 'var(--text-h)' }}>{a.title}</p>
                      {a.excerpt && <p className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>{a.excerpt}</p>}
                    </div>
                    <ChevronRight size={15} style={{ color: 'var(--text-muted)', marginLeft: 'auto' }} />
                  </button>
                ))}
              </div>
            )}
          </div>

          {/* Stats */}
          <div className="mt-6 flex items-center justify-center gap-6 text-white/85 text-sm font-semibold">
            <span>{published.length} articles</span>
            <span style={{ opacity: 0.4 }}>•</span>
            <span>{grouped.length} categories</span>
          </div>
        </div>
      </div>

      {/* ── Category grid ── */}
      <div className="max-w-5xl mx-auto px-1 mt-8">
        {isLoading && (
          <div className="grid gap-5 md:grid-cols-2">
            {[1, 2, 3, 4].map(i => <div key={i} style={{ height: 210, borderRadius: 20, background: 'var(--bg-card)', border: '1px solid var(--border)' }} className="animate-pulse" />)}
          </div>
        )}

        {!isLoading && grouped.length === 0 && (
          <div className="text-center py-16" style={{ color: 'var(--text-muted)' }}>
            No published articles yet. Publish articles from KB Admin and they’ll appear here.
          </div>
        )}

        {!isLoading && grouped.length > 0 && (
          <>
            <h2 className="font-display font-bold mb-5" style={{ fontSize: 22, color: 'var(--text-h)' }}>Browse by topic</h2>
            <div className="grid gap-5 md:grid-cols-2">
              {grouped.map((cat, i) => {
                const Icon = CAT_ICONS[i % CAT_ICONS.length]
                const t = tint(i)
                return (
                  <section key={cat.id} className="rounded-2xl p-6 transition-transform"
                    style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
                    <div className="flex items-center gap-3 mb-4">
                      <span className="w-12 h-12 rounded-2xl flex items-center justify-center shrink-0"
                        style={{ background: `color-mix(in srgb, ${t.fg} 15%, transparent)`, color: t.fg }}>
                        <Icon size={24} />
                      </span>
                      <div className="min-w-0">
                        <h3 className="font-display font-bold truncate" style={{ fontSize: 18, color: 'var(--text-h)' }}>{cat.name}</h3>
                        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>{cat.articles.length} article{cat.articles.length !== 1 ? 's' : ''}</p>
                      </div>
                    </div>
                    <ul className="space-y-0.5">
                      {cat.articles.slice(0, 4).map(a => (
                        <li key={a.id}>
                          <button onClick={() => open(a)} className="w-full flex items-center gap-2.5 px-2.5 py-2.5 rounded-xl text-left transition-colors group"
                            onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
                            onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                            <FileText size={15} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                            <span className="flex-1 truncate" style={{ fontSize: 15, color: 'var(--text-body)' }}>{a.title}</span>
                            <ChevronRight size={15} style={{ color: 'var(--text-muted)', opacity: 0.5 }} className="group-hover:translate-x-0.5 transition-transform" />
                          </button>
                        </li>
                      ))}
                    </ul>
                    {cat.articles.length > 4 && (
                      <button onClick={() => open(cat.articles[0])} className="mt-3 inline-flex items-center gap-1 text-sm font-bold" style={{ color: t.fg }}>
                        View all {cat.articles.length} <ArrowRight size={14} />
                      </button>
                    )}
                  </section>
                )
              })}
            </div>

            {/* ── Popular ── */}
            {popular.length > 0 && (
              <div className="mt-8 rounded-2xl p-6" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
                <h2 className="font-display font-bold mb-4" style={{ fontSize: 18, color: 'var(--text-h)' }}>Most helpful articles</h2>
                <ul className="grid md:grid-cols-2 gap-1">
                  {popular.map((a, idx) => (
                    <li key={a.id}>
                      <button onClick={() => open(a)} className="w-full flex items-center gap-3 px-3 py-3 rounded-xl text-left transition-colors"
                        onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
                        onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                        <span className="font-display font-extrabold shrink-0" style={{ fontSize: 18, color: 'var(--text-muted)', opacity: 0.5, width: 22 }}>{idx + 1}</span>
                        <div className="min-w-0 flex-1">
                          <p className="font-semibold truncate" style={{ fontSize: 15, color: 'var(--text-h)' }}>{a.title}</p>
                          <p className="text-xs flex items-center gap-2.5 mt-0.5" style={{ color: 'var(--text-muted)' }}>
                            <span className="inline-flex items-center gap-1" style={{ color: 'var(--color-success-500)' }}><ThumbsUp size={11} />{a.thumbs_up || 0}</span>
                            <span>{readTime(a.content)} min read</span>
                          </p>
                        </div>
                        <ChevronRight size={15} style={{ color: 'var(--text-muted)', opacity: 0.5 }} />
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </>
        )}
      </div>

      <RaiseTicketModal open={raiseOpen} onClose={() => setRaiseOpen(false)} source="internal" />
    </div>
  )
}
