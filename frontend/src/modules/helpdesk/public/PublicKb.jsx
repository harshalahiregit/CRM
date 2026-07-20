import { useState, useMemo, useEffect } from 'react'
import { useQuery, useMutation } from '@tanstack/react-query'
import { useParams, Link, useSearchParams } from 'react-router-dom'
import {
  Search, BookOpen, FileText, ChevronRight, ChevronDown, Headphones,
  ThumbsUp, ThumbsDown, Check, Calendar, LogIn, UserPlus, LifeBuoy, Star,
} from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

/**
 * Public, no-auth Help Center, scoped by the tenant's widget key.
 *
 * Categories-first layout: a left rail lists every category (expandable to its
 * articles) and the selected article renders inline in the main pane, so the
 * whole help centre reads on one page. Accepts ?q= so a search started from an
 * article page (PublicArticle) carries over. Deep, shareable article links still
 * live at /kb/a/:slug (PublicArticle) — this page just keeps you in the browse
 * flow. Palette is the existing teal/cyan help-centre theme.
 */
export default function PublicKb() {
  const { key } = useParams()
  const [searchParams] = useSearchParams()
  const [query, setQuery] = useState(searchParams.get('q') || '')
  const [openCat, setOpenCat] = useState(null)     // expanded category id in the rail
  const [slug, setSlug] = useState(null)           // selected article's public_slug
  const [voted, setVoted] = useState(null)
  // Star rating (independent of the thumbs vote above).
  const [rating, setRating] = useState(0)
  const [hoverStar, setHoverStar] = useState(0)
  const [comment, setComment] = useState('')
  const [rated, setRated] = useState(false)

  const { data: tree = [], isLoading, isError } = useQuery({
    queryKey: ['public-kb', key],
    queryFn: () => helpdeskApi.public.kbTree(key),
  })

  const { data: results = [] } = useQuery({
    queryKey: ['public-kb-search', key, query],
    queryFn: () => helpdeskApi.public.kbSearch(key, query),
    enabled: query.trim().length > 1,
  })

  const { data: article, isFetching: articleLoading } = useQuery({
    queryKey: ['public-article', slug],
    queryFn: () => helpdeskApi.public.article(slug),
    enabled: Boolean(slug),
  })

  const vote = useMutation({
    mutationFn: (direction) => helpdeskApi.public.vote(slug, direction),
    onSuccess: (_r, direction) => setVoted(direction),
  })

  const rate = useMutation({
    mutationFn: () => helpdeskApi.public.submitFeedback(slug, { rating, comment: comment.trim() || null }),
    onSuccess: () => setRated(true),
  })

  const searching = query.trim().length > 1

  // Flatten each category's subcategories into one article list for the rail.
  const cats = useMemo(() => tree.map(c => ({
    ...c,
    articles: (c.subcategories || []).flatMap(s => s.articles || []),
  })), [tree])

  // On first load, open the first non-empty category and pick its first article.
  useEffect(() => {
    if (slug || openCat || !cats.length) return
    const first = cats.find(c => c.articles.length)
    if (first) { setOpenCat(first.id); setSlug(first.articles[0].public_slug) }
  }, [cats, slug, openCat])

  // Reset the feedback state whenever a different article is opened.
  const openArticle = (s) => {
    setSlug(s); setVoted(null); setQuery('')
    setRating(0); setHoverStar(0); setComment(''); setRated(false)
  }

  const helpfulPct = article && (article.thumbs_up + article.thumbs_down) > 0
    ? Math.round(article.thumbs_up / (article.thumbs_up + article.thumbs_down) * 100) : null
  const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : ''

  return (
    <div style={{ minHeight: '100vh', background: '#f6f8fc', fontFamily: "'Inter', system-ui, sans-serif" }}>
      <style>{`
        .hc-prose{font-size:16px;line-height:1.75;color:#334155}
        .hc-prose h2{font-size:1.4rem;font-weight:800;color:#0f172a;margin:1.8rem 0 .7rem;letter-spacing:-0.01em;line-height:1.3}
        .hc-prose h3{font-size:1.15rem;font-weight:700;color:#0f172a;margin:1.5rem 0 .5rem}
        .hc-prose p{margin:0 0 1.05rem}
        .hc-prose ul,.hc-prose ol{margin:0 0 1.05rem 1.4rem}
        .hc-prose li{margin:.4rem 0}
        .hc-prose a{color:#0891b2;text-decoration:underline;text-underline-offset:2px}
        .hc-prose strong{color:#0f172a;font-weight:700}
        .hc-prose code{background:#eef2f8;padding:.15rem .45rem;border-radius:6px;font-size:.9em;color:#0f172a;border:1px solid #e2e8f0}
        .hc-prose pre{background:#0f172a;padding:1rem;border-radius:12px;overflow:auto;color:#e2e8f0;margin:1.2rem 0}
        .hc-prose pre code{background:none;border:none;padding:0}
        .hc-prose blockquote{border-left:3px solid #06b6d4;padding:.4rem 0 .4rem 1rem;margin:1.3rem 0;color:#475569;background:#f0fdff;border-radius:0 8px 8px 0}
        .hc-prose img{max-width:100%;height:auto;border-radius:12px;margin:1.2rem 0;border:1px solid #e6ebf3}
        .hc-prose iframe,.hc-prose .ql-video{width:100%;aspect-ratio:16/9;height:auto;border:0;border-radius:12px;margin:1.2rem 0}
        .hc-layout{display:grid;grid-template-columns:1fr;gap:24px}
        .hc-rail{position:static}
        .hc-authbtn-full{display:none}
        @media(min-width:900px){
          .hc-layout{grid-template-columns:272px minmax(0,1fr);align-items:start}
          .hc-rail{position:sticky;top:88px}
        }
        @media(min-width:600px){.hc-authbtn-full{display:inline-flex}.hc-authbtn-icon{display:none}}
      `}</style>

      {/* Top nav — brand · search · auth */}
      <header style={{ background: '#fff', borderBottom: '1px solid #e6ebf3', position: 'sticky', top: 0, zIndex: 20 }}>
        <div style={{ maxWidth: 1180, margin: '0 auto', padding: '12px 24px', display: 'flex', alignItems: 'center', gap: 16 }}>
          <span style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <span style={{ width: 36, height: 36, borderRadius: 10, background: 'linear-gradient(135deg,#0e7490,#06b6d4)', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', boxShadow: '0 4px 12px rgba(6,182,212,0.3)', flexShrink: 0 }}>
              <Headphones size={18} style={{ color: '#fff' }} />
            </span>
            <span style={{ fontWeight: 800, color: '#0e7490', fontSize: 17, letterSpacing: '-0.01em' }}>Help Center</span>
          </span>

          <div style={{ flex: 1, maxWidth: 440, marginLeft: 'auto', position: 'relative' }}>
            <Search size={16} style={{ position: 'absolute', left: 14, top: '50%', transform: 'translateY(-50%)', color: '#94a3b8' }} />
            <input
              value={query}
              onChange={e => setQuery(e.target.value)}
              placeholder="Search the Help Center…"
              style={{ width: '100%', padding: '10px 14px 10px 40px', borderRadius: 10, border: '1px solid #e2e8f0', fontSize: 13.5, outline: 'none', color: '#1e293b', background: '#f8fafc' }}
            />
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexShrink: 0 }}>
            <Link to="/auth/login" style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 15px', borderRadius: 10, border: '1px solid #e2e8f0', color: '#0e7490', fontWeight: 700, fontSize: 13.5, textDecoration: 'none', background: '#fff' }}>
              <LogIn size={15} /><span className="hc-authbtn-full">Login</span>
            </Link>
            <Link to="/auth/register" style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 15px', borderRadius: 10, border: 'none', color: '#fff', fontWeight: 700, fontSize: 13.5, textDecoration: 'none', background: 'linear-gradient(135deg,#0e7490,#06b6d4)', boxShadow: '0 4px 12px rgba(6,182,212,0.25)' }}>
              <UserPlus size={15} /><span className="hc-authbtn-full">Sign up</span>
            </Link>
          </div>
        </div>
      </header>

      <div style={{ maxWidth: 1180, margin: '0 auto', padding: '28px 24px 64px' }}>
        {isError && (
          <div style={{ textAlign: 'center', padding: '48px 20px', background: '#fff', borderRadius: 16, border: '1px solid #e6ebf3', color: '#64748b' }}>
            This help center is not available.
          </div>
        )}

        {!isError && (
          <div className="hc-layout">
            {/* Category rail */}
            <aside className="hc-rail">
              <p style={{ fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#94a3b8', margin: '2px 0 12px 6px' }}>Categories</p>

              {isLoading && [1, 2, 3, 4].map(i => (
                <div key={i} style={{ height: 40, borderRadius: 10, background: '#eef2f8', marginBottom: 8, animation: 'pulse 1.5s infinite' }} />
              ))}

              <nav style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                {cats.map(cat => {
                  const open = openCat === cat.id
                  return (
                    <div key={cat.id}>
                      <button
                        onClick={() => setOpenCat(open ? null : cat.id)}
                        style={{
                          width: '100%', display: 'flex', alignItems: 'center', gap: 10,
                          padding: '10px 12px', borderRadius: 10, border: 'none', cursor: 'pointer',
                          background: open ? '#ecfeff' : 'transparent',
                          color: open ? '#0e7490' : '#334155', fontWeight: open ? 700 : 600,
                          fontSize: 14, textAlign: 'left', transition: 'background 0.15s',
                        }}
                        onMouseEnter={e => { if (!open) e.currentTarget.style.background = '#f6f8fc' }}
                        onMouseLeave={e => { if (!open) e.currentTarget.style.background = 'transparent' }}
                      >
                        <BookOpen size={16} style={{ color: open ? '#0891b2' : '#94a3b8', flexShrink: 0 }} />
                        <span style={{ flex: 1, minWidth: 0 }}>{cat.name}</span>
                        {cat.articles.length > 0 && (open
                          ? <ChevronDown size={15} style={{ color: '#0891b2', flexShrink: 0 }} />
                          : <ChevronRight size={15} style={{ color: '#cbd5e1', flexShrink: 0 }} />)}
                      </button>

                      {open && (
                        <ul style={{ listStyle: 'none', margin: '2px 0 6px', padding: '0 0 0 8px', borderLeft: '2px solid #e6ebf3', marginLeft: 20 }}>
                          {cat.articles.map(a => {
                            const active = a.public_slug === slug
                            return (
                              <li key={a.id}>
                                <button
                                  onClick={() => openArticle(a.public_slug)}
                                  style={{
                                    width: '100%', display: 'flex', alignItems: 'center', gap: 8,
                                    padding: '8px 10px', borderRadius: 8, border: 'none', cursor: 'pointer',
                                    background: active ? '#ecfeff' : 'transparent',
                                    color: active ? '#0e7490' : '#475569', fontWeight: active ? 700 : 500,
                                    fontSize: 13.5, textAlign: 'left', transition: 'background 0.15s',
                                  }}
                                  onMouseEnter={e => { if (!active) e.currentTarget.style.background = '#f6f8fc' }}
                                  onMouseLeave={e => { if (!active) e.currentTarget.style.background = 'transparent' }}
                                >
                                  <FileText size={13} style={{ color: active ? '#0891b2' : '#94a3b8', flexShrink: 0 }} />
                                  <span style={{ flex: 1, minWidth: 0 }}>{a.title}</span>
                                </button>
                              </li>
                            )
                          })}
                          {cat.articles.length === 0 && (
                            <li style={{ fontSize: 12.5, color: '#94a3b8', padding: '6px 10px' }}>Coming soon.</li>
                          )}
                        </ul>
                      )}
                    </div>
                  )
                })}
                {!isLoading && cats.length === 0 && (
                  <p style={{ fontSize: 13, color: '#94a3b8', padding: '8px 6px' }}>No help topics yet.</p>
                )}
              </nav>
            </aside>

            {/* Main pane */}
            <main style={{ background: '#fff', borderRadius: 20, border: '1px solid #e6ebf3', boxShadow: '0 4px 28px rgba(15,23,42,0.05)', padding: 'clamp(22px,3.5vw,40px)', minHeight: 420 }}>
              {searching ? (
                <SearchResults results={results} query={query} onOpen={openArticle} />
              ) : article ? (
                <article>
                  {/* Breadcrumb */}
                  <nav style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 16, fontSize: 13, color: '#64748b', flexWrap: 'wrap' }}>
                    <span style={{ color: '#0e7490', fontWeight: 600 }}>Help Center</span>
                    {article.category?.name && <><ChevronRight size={13} /><span>{article.category.name}</span></>}
                    {article.subcategory?.name && <><ChevronRight size={13} /><span>{article.subcategory.name}</span></>}
                  </nav>

                  <h1 style={{ fontSize: 'clamp(1.5rem,3vw,2rem)', fontWeight: 800, color: '#0f172a', letterSpacing: '-0.02em', lineHeight: 1.2, marginBottom: 12 }}>
                    {article.title}
                  </h1>

                  <div style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap', paddingBottom: 20, marginBottom: 24, borderBottom: '1px solid #e6ebf3' }}>
                    <span style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, color: '#64748b' }}><Calendar size={13} /> {fmtDate(article.updated_at)}</span>
                    {helpfulPct !== null && <span style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, color: '#16a34a', fontWeight: 600 }}><ThumbsUp size={12} /> {helpfulPct}% found this helpful</span>}
                    {article.total_ratings > 0 && <span style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, color: '#b45309', fontWeight: 600 }}><Star size={12} style={{ fill: '#f59e0b', color: '#f59e0b' }} /> {article.avg_rating} · {article.total_ratings} rating{article.total_ratings > 1 ? 's' : ''}</span>}
                    <Link to={`/kb/a/${article.public_slug}`} style={{ marginLeft: 'auto', fontSize: 13, fontWeight: 700, color: '#0891b2', textDecoration: 'none' }}>Open full page →</Link>
                  </div>

                  <div className="hc-prose" dangerouslySetInnerHTML={{ __html: article.content || '' }} />

                  {/* Feedback */}
                  <div style={{ marginTop: 40, paddingTop: 26, borderTop: '1px solid #e6ebf3' }}>
                    {voted ? (
                      <div style={{ display: 'inline-flex', alignItems: 'center', gap: 8, color: '#16a34a', fontWeight: 700 }}>
                        <span style={{ width: 28, height: 28, borderRadius: '50%', background: '#dcfce7', display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }}><Check size={15} style={{ color: '#16a34a' }} /></span>
                        Thanks for your feedback!
                      </div>
                    ) : (
                      <div style={{ display: 'flex', alignItems: 'center', gap: 14, flexWrap: 'wrap' }}>
                        <span style={{ fontSize: 15, fontWeight: 700, color: '#334155' }}>Was this article helpful?</span>
                        <div style={{ display: 'flex', gap: 10 }}>
                          <FeedbackBtn onClick={() => vote.mutate('up')} disabled={vote.isPending} icon={ThumbsUp} label={`Yes (${article.thumbs_up || 0})`} color="#16a34a" hoverBg="#f0fdf4" hoverBorder="#86efac" />
                          <FeedbackBtn onClick={() => vote.mutate('down')} disabled={vote.isPending} icon={ThumbsDown} label={`No (${article.thumbs_down || 0})`} color="#dc2626" hoverBg="#fef2f2" hoverBorder="#fca5a5" />
                        </div>
                      </div>
                    )}
                  </div>

                  {/* Star rating + comment — same as the full-page article, kept in sync here */}
                  <div style={{ marginTop: 22, padding: '22px 20px', borderRadius: 16, background: '#fffbeb', border: '1px solid #fde68a', textAlign: 'center' }}>
                    {rated ? (
                      <div style={{ display: 'inline-flex', alignItems: 'center', gap: 8, color: '#b45309', fontWeight: 700 }}>
                        <span style={{ width: 28, height: 28, borderRadius: '50%', background: '#fef3c7', display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }}><Star size={15} style={{ fill: '#f59e0b', color: '#f59e0b' }} /></span>
                        Thanks for rating this article!
                      </div>
                    ) : (
                      <>
                        <p style={{ fontSize: 15, fontWeight: 700, color: '#334155', marginBottom: 4 }}>Rate this article</p>
                        <p style={{ fontSize: 13, color: '#94a3b8', marginBottom: 14 }}>How helpful was this? Tap a star.</p>
                        <StarInput value={rating} hover={hoverStar} onHover={setHoverStar} onSelect={setRating} />
                        {rating > 0 && (
                          <div style={{ maxWidth: 460, margin: '16px auto 0' }}>
                            <textarea
                              value={comment}
                              onChange={e => setComment(e.target.value)}
                              rows={3}
                              maxLength={2000}
                              placeholder="Add a comment (optional)…"
                              style={{ width: '100%', padding: '10px 12px', borderRadius: 10, border: '1px solid #e2e8f0', fontSize: 14, outline: 'none', color: '#1e293b', background: '#fff', resize: 'vertical', fontFamily: 'inherit' }}
                            />
                            <button
                              onClick={() => rate.mutate()}
                              disabled={rate.isPending}
                              style={{ marginTop: 10, background: '#f59e0b', color: '#fff', fontWeight: 700, fontSize: 14, padding: '10px 22px', borderRadius: 10, border: 'none', cursor: 'pointer', opacity: rate.isPending ? 0.6 : 1 }}>
                              {rate.isPending ? 'Submitting…' : 'Submit rating'}
                            </button>
                            {rate.isError && <p style={{ fontSize: 13, color: '#dc2626', marginTop: 8 }}>{rate.error?.message}</p>}
                          </div>
                        )}
                      </>
                    )}
                  </div>
                </article>
              ) : articleLoading ? (
                <div style={{ height: 320, borderRadius: 16, background: '#eef2f8', animation: 'pulse 1.5s infinite' }} />
              ) : (
                <div style={{ textAlign: 'center', padding: '64px 20px' }}>
                  <span style={{ width: 56, height: 56, borderRadius: 16, background: '#ecfeff', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', marginBottom: 16 }}>
                    <LifeBuoy size={28} style={{ color: '#0891b2' }} />
                  </span>
                  <p style={{ fontSize: 17, fontWeight: 700, color: '#334155', marginBottom: 6 }}>How can we help?</p>
                  <p style={{ fontSize: 14, color: '#94a3b8' }}>Pick a category on the left or search to find an article.</p>
                </div>
              )}
            </main>
          </div>
        )}
      </div>
    </div>
  )
}

function SearchResults({ results, query, onOpen }) {
  return (
    <div>
      <p style={{ fontSize: 13, fontWeight: 700, color: '#64748b', marginBottom: 14 }}>
        {results.length} result{results.length === 1 ? '' : 's'} for &ldquo;{query.trim()}&rdquo;
      </p>
      {results.length === 0 ? (
        <p style={{ padding: '32px 0', color: '#94a3b8', textAlign: 'center', fontSize: 14 }}>No articles match your search.</p>
      ) : (
        <ul style={{ listStyle: 'none', padding: 0, margin: 0, display: 'flex', flexDirection: 'column', gap: 8 }}>
          {results.map(a => (
            <li key={a.id}>
              <button
                onClick={() => onOpen(a.public_slug)}
                style={{ width: '100%', display: 'flex', alignItems: 'center', gap: 12, padding: '13px 16px', borderRadius: 12, border: '1px solid #e6ebf3', background: '#fff', cursor: 'pointer', textAlign: 'left', transition: 'all 0.15s' }}
                onMouseEnter={e => { e.currentTarget.style.borderColor = '#67e8f9'; e.currentTarget.style.background = '#f0fdff' }}
                onMouseLeave={e => { e.currentTarget.style.borderColor = '#e6ebf3'; e.currentTarget.style.background = '#fff' }}
              >
                <FileText size={16} style={{ color: '#0891b2', flexShrink: 0 }} />
                <span style={{ flex: 1, fontSize: 14, fontWeight: 600, color: '#0f172a' }}>{a.title}</span>
                <ChevronRight size={15} style={{ color: '#cbd5e1' }} />
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function StarInput({ value, hover, onHover, onSelect }) {
  return (
    <div style={{ display: 'inline-flex', gap: 6, justifyContent: 'center' }} onMouseLeave={() => onHover(0)}>
      {[1, 2, 3, 4, 5].map(n => {
        const active = (hover || value) >= n
        return (
          <button key={n} type="button" onClick={() => onSelect(n)} onMouseEnter={() => onHover(n)}
            aria-label={`${n} star${n > 1 ? 's' : ''}`}
            style={{ background: 'none', border: 'none', cursor: 'pointer', padding: 2, lineHeight: 0 }}>
            <Star size={30} style={{ color: active ? '#f59e0b' : '#d1d5db', fill: active ? '#f59e0b' : 'none', transition: 'all 0.12s' }} />
          </button>
        )
      })}
    </div>
  )
}

function FeedbackBtn({ onClick, disabled, icon: Icon, label, color, hoverBg, hoverBorder }) {
  return (
    <button onClick={onClick} disabled={disabled}
      style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 18px', borderRadius: 10, border: '1px solid #e2e8f0', background: '#fff', fontSize: 14, fontWeight: 600, color: '#475569', cursor: 'pointer', transition: 'all 0.15s' }}
      onMouseEnter={e => { e.currentTarget.style.background = hoverBg; e.currentTarget.style.borderColor = hoverBorder; e.currentTarget.style.color = color }}
      onMouseLeave={e => { e.currentTarget.style.background = '#fff'; e.currentTarget.style.borderColor = '#e2e8f0'; e.currentTarget.style.color = '#475569' }}>
      <Icon size={15} /> {label}
    </button>
  )
}
