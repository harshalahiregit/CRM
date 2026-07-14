import { useState, useMemo } from 'react'
import { useQuery, useMutation } from '@tanstack/react-query'
import { useParams, useNavigate, Link } from 'react-router-dom'
import {
  ThumbsUp, ThumbsDown, Headphones, ChevronRight, Calendar, Clock,
  Check, Printer, Link2, List, ArrowLeft, LifeBuoy, Search, FileText, ArrowRight,
} from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

/**
 * Public, no-auth article page — the shareable link minted on publish.
 * A real help-centre article: branded nav with search, breadcrumb, auto table of
 * contents, reading time, print / copy-link, feedback, related articles, and a
 * support CTA. Links back to the Help Center home (PublicKb) when the tenant has
 * a widget key, so the article is never a dead-end.
 */
export default function PublicArticle() {
  const { slug } = useParams()
  const navigate = useNavigate()
  const [voted, setVoted] = useState(null)
  const [copied, setCopied] = useState(false)
  const [q, setQ] = useState('')

  const { data: article, isLoading, isError } = useQuery({
    queryKey: ['public-article', slug],
    queryFn: () => helpdeskApi.public.article(slug),
  })

  const vote = useMutation({
    mutationFn: (direction) => helpdeskApi.public.vote(slug, direction),
    onSuccess: (_r, direction) => setVoted(direction),
  })

  const homeHref = article?.kb_key ? `/kb/${article.kb_key}` : null
  const goHome = () => (homeHref ? navigate(homeHref) : history.back())
  const submitSearch = (e) => {
    e.preventDefault()
    if (homeHref) navigate(`${homeHref}?q=${encodeURIComponent(q.trim())}`)
  }

  // Parse content → inject heading ids + build a table of contents.
  const { html, toc, minutes } = useMemo(() => {
    if (!article?.content) return { html: '', toc: [], minutes: 1 }
    const doc = new DOMParser().parseFromString(article.content, 'text/html')
    const toc = []
    doc.querySelectorAll('h2, h3').forEach((h, i) => {
      h.id = `sec-${i}`
      toc.push({ id: `sec-${i}`, text: h.textContent, level: h.tagName === 'H2' ? 2 : 3 })
    })
    const words = doc.body.textContent.trim().split(/\s+/).length
    return { html: doc.body.innerHTML, toc, minutes: Math.max(1, Math.round(words / 200)) }
  }, [article?.content])

  const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : ''
  const copyLink = () => { navigator.clipboard?.writeText(window.location.href); setCopied(true); setTimeout(() => setCopied(false), 1600) }
  const scrollTo = id => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })

  if (isLoading) return <Shell onSearch={submitSearch} q={q} setQ={setQ} homeHref={homeHref}><div style={{ height: 320, borderRadius: 16, background: '#e8edf6', animation: 'pulse 1.5s infinite' }} /></Shell>
  if (isError || !article) return (
    <Shell onSearch={submitSearch} q={q} setQ={setQ} homeHref={homeHref}>
      <div style={{ textAlign: 'center', padding: '48px 0' }}>
        <p style={{ fontWeight: 700, color: '#334155', marginBottom: 8, fontSize: 18 }}>Article not available</p>
        <p style={{ fontSize: 14, color: '#94a3b8' }}>This article may have been removed or the link is incorrect.</p>
      </div>
    </Shell>
  )

  const helpfulPct = (article.thumbs_up + article.thumbs_down) > 0
    ? Math.round(article.thumbs_up / (article.thumbs_up + article.thumbs_down) * 100) : null
  const related = article.related || []

  return (
    <Shell onSearch={submitSearch} q={q} setQ={setQ} homeHref={homeHref}>
      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0,1fr)', gap: 32 }} className="pub-grid">
        {/* Main column */}
        <article>
          {/* Breadcrumb */}
          <nav style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 18, fontSize: 13.5, color: '#64748b', flexWrap: 'wrap' }}>
            {homeHref
              ? <Link to={homeHref} style={{ color: '#0e7490', fontWeight: 600, textDecoration: 'none' }}>Help Center</Link>
              : <span style={{ color: '#0e7490', fontWeight: 600 }}>Help Center</span>}
            {article.category?.name && <><ChevronRight size={13} /><span>{article.category.name}</span></>}
            {article.subcategory?.name && <><ChevronRight size={13} /><span>{article.subcategory.name}</span></>}
          </nav>

          {/* Title */}
          <h1 style={{ fontSize: 'clamp(1.7rem,3.2vw,2.3rem)', fontWeight: 800, color: '#0f172a', letterSpacing: '-0.025em', lineHeight: 1.2, marginBottom: 14 }}>
            {article.title}
          </h1>

          {/* Meta + options bar */}
          <div style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap', paddingBottom: 22, marginBottom: 26, borderBottom: '1px solid #e6ebf3' }}>
            <span style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, color: '#64748b' }}><Calendar size={13} /> {fmtDate(article.updated_at)}</span>
            <span style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, color: '#64748b' }}><Clock size={13} /> {minutes} min read</span>
            {helpfulPct !== null && <span style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, color: '#16a34a', fontWeight: 600 }}><ThumbsUp size={12} /> {helpfulPct}% found this helpful</span>}
            <div style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
              <OptBtn icon={copied ? Check : Link2} label={copied ? 'Copied' : 'Copy link'} onClick={copyLink} active={copied} />
              <OptBtn icon={Printer} label="Print" onClick={() => window.print()} />
            </div>
          </div>

          {/* Mobile TOC */}
          {toc.length > 1 && (
            <details className="pub-toc-mobile" style={{ marginBottom: 24, background: '#f6f8fc', border: '1px solid #e6ebf3', borderRadius: 12, padding: '12px 16px' }}>
              <summary style={{ cursor: 'pointer', fontWeight: 700, fontSize: 13.5, color: '#334155', display: 'flex', alignItems: 'center', gap: 8 }}><List size={14} /> On this page</summary>
              <ul style={{ listStyle: 'none', padding: '10px 0 0', margin: 0 }}>
                {toc.map(t => <li key={t.id} style={{ paddingLeft: t.level === 3 ? 14 : 0, margin: '6px 0' }}><a onClick={() => scrollTo(t.id)} style={{ fontSize: 14, color: '#475569', cursor: 'pointer' }}>{t.text}</a></li>)}
              </ul>
            </details>
          )}

          {/* Content */}
          <div className="pub-prose" dangerouslySetInnerHTML={{ __html: html }} />

          {/* Feedback */}
          <div style={{ marginTop: 44, paddingTop: 28, borderTop: '1px solid #e6ebf3', textAlign: 'center' }}>
            {voted ? (
              <div style={{ display: 'inline-flex', alignItems: 'center', gap: 8, color: '#16a34a', fontWeight: 700 }}>
                <span style={{ width: 30, height: 30, borderRadius: '50%', background: '#dcfce7', display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }}><Check size={16} style={{ color: '#16a34a' }} /></span>
                Thanks for your feedback!
              </div>
            ) : (
              <>
                <p style={{ fontSize: 15.5, fontWeight: 700, color: '#334155', marginBottom: 16 }}>Was this article helpful?</p>
                <div style={{ display: 'flex', gap: 12, justifyContent: 'center' }}>
                  <VoteBtn onClick={() => vote.mutate('up')} disabled={vote.isPending} icon={ThumbsUp} label={`Yes (${article.thumbs_up || 0})`} color="#16a34a" hoverBg="#f0fdf4" hoverBorder="#86efac" />
                  <VoteBtn onClick={() => vote.mutate('down')} disabled={vote.isPending} icon={ThumbsDown} label={`No (${article.thumbs_down || 0})`} color="#dc2626" hoverBg="#fef2f2" hoverBorder="#fca5a5" />
                </div>
              </>
            )}
          </div>

          {/* Related articles */}
          {related.length > 0 && (
            <div style={{ marginTop: 40 }}>
              <p style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.05em', color: '#94a3b8', marginBottom: 12 }}>
                <FileText size={14} /> Related articles
              </p>
              <div style={{ display: 'grid', gap: 10 }}>
                {related.map(r => (
                  <Link key={r.id} to={`/kb/a/${r.public_slug}`}
                    style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '14px 16px', borderRadius: 12, border: '1px solid #e6ebf3', background: '#fff', textDecoration: 'none', color: '#0f172a', fontWeight: 600, fontSize: 14.5, transition: 'all 0.15s' }}
                    onMouseEnter={e => { e.currentTarget.style.borderColor = '#67e8f9'; e.currentTarget.style.background = '#f0fdff' }}
                    onMouseLeave={e => { e.currentTarget.style.borderColor = '#e6ebf3'; e.currentTarget.style.background = '#fff' }}>
                    <FileText size={15} style={{ color: '#0891b2', flexShrink: 0 }} />
                    <span style={{ flex: 1 }}>{r.title}</span>
                    <ArrowRight size={15} style={{ color: '#94a3b8' }} />
                  </Link>
                ))}
              </div>
            </div>
          )}

          {/* Support CTA */}
          <div style={{ marginTop: 32, borderRadius: 16, padding: '24px 28px', background: 'linear-gradient(135deg,#0e7490,#06b6d4)', display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' }}>
            <span style={{ width: 46, height: 46, borderRadius: 12, background: 'rgba(255,255,255,0.18)', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}><LifeBuoy size={24} style={{ color: '#fff' }} /></span>
            <div style={{ flex: 1, minWidth: 180 }}>
              <p style={{ color: '#fff', fontWeight: 700, fontSize: 16 }}>Still need help?</p>
              <p style={{ color: 'rgba(255,255,255,0.85)', fontSize: 14 }}>Browse more articles or reach our support team.</p>
            </div>
            <button onClick={goHome} style={{ background: '#fff', color: '#0e7490', fontWeight: 700, fontSize: 14, padding: '11px 20px', borderRadius: 10, border: 'none', cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6 }}>
              <ArrowLeft size={15} /> Back to Help Center
            </button>
          </div>
        </article>

        {/* Sticky TOC (desktop) */}
        {toc.length > 1 && (
          <aside className="pub-toc-desktop">
            <div style={{ position: 'sticky', top: 24 }}>
              <p style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.06em', color: '#94a3b8', marginBottom: 12 }}><List size={13} /> On this page</p>
              <ul style={{ listStyle: 'none', padding: 0, margin: 0, borderLeft: '2px solid #e6ebf3' }}>
                {toc.map(t => (
                  <li key={t.id}>
                    <a onClick={() => scrollTo(t.id)} style={{ display: 'block', padding: '6px 0 6px 14px', marginLeft: -2, borderLeft: '2px solid transparent', fontSize: 13.5, color: '#64748b', cursor: 'pointer', paddingLeft: t.level === 3 ? 26 : 14 }}
                      onMouseEnter={e => { e.currentTarget.style.color = '#0891b2'; e.currentTarget.style.borderLeftColor = '#0891b2' }}
                      onMouseLeave={e => { e.currentTarget.style.color = '#64748b'; e.currentTarget.style.borderLeftColor = 'transparent' }}>
                      {t.text}
                    </a>
                  </li>
                ))}
              </ul>
            </div>
          </aside>
        )}
      </div>
    </Shell>
  )
}

function OptBtn({ icon: Icon, label, onClick, active }) {
  return (
    <button onClick={onClick} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 13px', borderRadius: 9, border: '1px solid #e2e8f0', background: active ? '#f0fdf4' : '#fff', color: active ? '#16a34a' : '#475569', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}>
      <Icon size={14} /> {label}
    </button>
  )
}

function VoteBtn({ onClick, disabled, icon: Icon, label, color, hoverBg, hoverBorder }) {
  return (
    <button onClick={onClick} disabled={disabled}
      style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '11px 24px', borderRadius: 12, border: '1px solid #e2e8f0', background: '#fff', fontSize: 14.5, fontWeight: 600, color: '#475569', cursor: 'pointer', transition: 'all 0.15s' }}
      onMouseEnter={e => { e.currentTarget.style.background = hoverBg; e.currentTarget.style.borderColor = hoverBorder; e.currentTarget.style.color = color }}
      onMouseLeave={e => { e.currentTarget.style.background = '#fff'; e.currentTarget.style.borderColor = '#e2e8f0'; e.currentTarget.style.color = '#475569' }}>
      <Icon size={16} /> {label}
    </button>
  )
}

function Shell({ children, onSearch, q, setQ, homeHref }) {
  return (
    <div style={{ minHeight: '100vh', background: '#f6f8fc', fontFamily: "'Inter', system-ui, sans-serif" }}>
      <style>{`
        .pub-prose{font-size:16.5px;line-height:1.78;color:#334155}
        .pub-prose h2{font-size:1.5rem;font-weight:800;color:#0f172a;margin:2rem 0 .75rem;letter-spacing:-0.01em;line-height:1.3;font-family:'Plus Jakarta Sans','Inter',sans-serif;scroll-margin-top:88px}
        .pub-prose h3{font-size:1.2rem;font-weight:700;color:#0f172a;margin:1.6rem 0 .5rem;font-family:'Plus Jakarta Sans','Inter',sans-serif;scroll-margin-top:88px}
        .pub-prose p{margin:0 0 1.1rem}
        .pub-prose ul,.pub-prose ol{margin:0 0 1.1rem 1.4rem}
        .pub-prose li{margin:.45rem 0}
        .pub-prose a{color:#0891b2;text-decoration:underline;text-underline-offset:2px}
        .pub-prose strong{color:#0f172a;font-weight:700}
        .pub-prose code{background:#eef2f8;padding:.15rem .45rem;border-radius:6px;font-size:.9em;color:#0f172a;border:1px solid #e2e8f0}
        .pub-prose pre{background:#0f172a;padding:1rem;border-radius:12px;overflow:auto;color:#e2e8f0;margin:1.25rem 0}
        .pub-prose pre code{background:none;border:none;padding:0}
        .pub-prose blockquote{border-left:3px solid #06b6d4;padding:.4rem 0 .4rem 1rem;margin:1.4rem 0;color:#475569;background:#f0fdff;border-radius:0 8px 8px 0}
        .pub-prose img{max-width:100%;height:auto;border-radius:12px;margin:1.25rem 0;border:1px solid #e6ebf3}
        .pub-prose iframe,.pub-prose .ql-video{width:100%;aspect-ratio:16/9;height:auto;border:0;border-radius:12px;margin:1.25rem 0}
        .pub-toc-desktop{display:none}
        .pub-hsearch{display:none}
        @media(min-width:720px){.pub-hsearch{display:flex}}
        @media(min-width:960px){.pub-grid{grid-template-columns:minmax(0,1fr) 220px !important}.pub-toc-desktop{display:block}.pub-toc-mobile{display:none}}
        @media print{.pub-toc-desktop,.pub-toc-mobile,.pub-nav{display:none !important}}
      `}</style>

      {/* Top nav — branded, with search + browse-all */}
      <header className="pub-nav" style={{ background: '#fff', borderBottom: '1px solid #e6ebf3', position: 'sticky', top: 0, zIndex: 10 }}>
        <div style={{ maxWidth: 1080, margin: '0 auto', padding: '12px 24px', display: 'flex', alignItems: 'center', gap: 16 }}>
          <BrandLink homeHref={homeHref} />

          <form onSubmit={onSearch} className="pub-hsearch" style={{ flex: 1, maxWidth: 420, position: 'relative', marginLeft: 'auto' }}>
            <Search size={16} style={{ position: 'absolute', left: 14, top: '50%', transform: 'translateY(-50%)', color: '#94a3b8' }} />
            <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search the Help Center…"
              style={{ width: '100%', padding: '9px 14px 9px 40px', borderRadius: 10, border: '1px solid #e2e8f0', fontSize: 13.5, outline: 'none', color: '#1e293b', background: '#f8fafc' }} />
          </form>

          {homeHref
            ? <Link to={homeHref} style={{ marginLeft: 'auto', fontSize: 13.5, fontWeight: 700, color: '#0891b2', textDecoration: 'none', whiteSpace: 'nowrap' }}>All articles →</Link>
            : <button onClick={() => history.back()} style={{ marginLeft: 'auto', fontSize: 13.5, fontWeight: 700, color: '#0891b2', background: 'none', border: 'none', cursor: 'pointer', whiteSpace: 'nowrap' }}>← Back</button>}
        </div>
      </header>

      {/* Article card */}
      <div style={{ maxWidth: 1080, margin: '0 auto', padding: '32px 24px 64px' }}>
        <div style={{ background: '#fff', borderRadius: 20, border: '1px solid #e6ebf3', padding: 'clamp(24px,4vw,44px)', boxShadow: '0 4px 28px rgba(15,23,42,0.06)' }}>
          {children}
        </div>
      </div>
    </div>
  )
}

function BrandLink({ homeHref }) {
  const inner = (
    <>
      <span style={{ width: 34, height: 34, borderRadius: 9, background: 'linear-gradient(135deg,#0e7490,#06b6d4)', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', boxShadow: '0 4px 12px rgba(6,182,212,0.3)' }}>
        <Headphones size={17} style={{ color: '#fff' }} />
      </span>
      <span style={{ fontWeight: 800, color: '#0e7490', fontSize: 16, letterSpacing: '-0.01em', fontFamily: "'Plus Jakarta Sans',sans-serif" }}>Help Center</span>
    </>
  )
  const style = { display: 'flex', alignItems: 'center', gap: 10, textDecoration: 'none' }
  return homeHref ? <Link to={homeHref} style={style}>{inner}</Link> : <span style={style}>{inner}</span>
}
