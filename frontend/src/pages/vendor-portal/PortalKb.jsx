import { useEffect, useMemo, useState } from 'react'
import { Search, X, Loader2, BookOpen, ChevronRight } from 'lucide-react'

/**
 * Shared Knowledge Base page for both vendor portals. Read-only: browse the
 * tenant's published articles, search, and read one in a modal. The two portals
 * pass their own loaders (`loadList`, `loadArticle`) — the shapes are identical.
 */
export default function PortalKb({ loadList, loadArticle }) {
  const [items, setItems] = useState(null)
  const [q, setQ] = useState('')
  const [openSlug, setOpenSlug] = useState(null)

  useEffect(() => { loadList().then(d => setItems(Array.isArray(d) ? d : (d?.data || []))).catch(() => setItems([])) }, [])

  const groups = useMemo(() => {
    const list = (items || []).filter(a => {
      if (!q.trim()) return true
      const s = `${a.title} ${a.excerpt || ''}`.toLowerCase()
      return s.includes(q.trim().toLowerCase())
    })
    const by = {}
    list.forEach(a => { const k = a.category || 'General'; (by[k] ||= []).push(a) })
    return Object.entries(by).sort((a, b) => a[0].localeCompare(b[0]))
  }, [items, q])

  return (
    <div style={{ maxWidth: 900, margin: '0 auto' }}>
      <style>{CSS}</style>
      <div className="kb-head">
        <h2 style={{ fontSize: 18, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>Knowledge Base</h2>
        <div className="kb-search">
          <Search size={15} style={{ opacity: 0.6 }} />
          <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search articles…" />
        </div>
      </div>

      {items === null ? <Center><Loader2 className="kb-spin" size={22} /></Center>
        : groups.length === 0 ? <Empty q={q} />
        : groups.map(([cat, arts]) => (
          <div key={cat} style={{ marginBottom: 22 }}>
            <div className="kb-cat">{cat}</div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(260px,1fr))', gap: 12 }}>
              {arts.map(a => (
                <button key={a.id} className="kb-card" onClick={() => setOpenSlug(a.slug)}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <BookOpen size={15} style={{ color: 'var(--portal-purple,#7c3aed)' }} />
                    <span style={{ fontWeight: 700, color: 'var(--text-h)', flex: 1 }}>{a.title}</span>
                    <ChevronRight size={14} style={{ opacity: 0.4 }} />
                  </div>
                  {a.excerpt && <div style={{ marginTop: 6, fontSize: 12.5, color: 'var(--text-muted)', lineHeight: 1.5, display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>{a.excerpt}</div>}
                </button>
              ))}
            </div>
          </div>
        ))}

      {openSlug && <ArticleModal loadArticle={loadArticle} slug={openSlug} onClose={() => setOpenSlug(null)} />}
    </div>
  )
}

function ArticleModal({ loadArticle, slug, onClose }) {
  const [art, setArt] = useState(null)
  const [loading, setLoading] = useState(true)
  useEffect(() => { let live = true; loadArticle(slug).then(d => live && setArt(d?.data || d)).finally(() => live && setLoading(false)); return () => { live = false } }, [slug])

  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.55)', zIndex: 60, display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', overflowY: 'auto' }}>
      <div onClick={e => e.stopPropagation()} className="kb-modal">
        <div className="kb-modal-head">
          <BookOpen size={17} />
          <strong style={{ color: 'var(--text-h)', flex: 1 }}>{art?.title || 'Article'}</strong>
          <button onClick={onClose} className="kb-icon"><X size={16} /></button>
        </div>
        <div style={{ padding: 20 }}>
          {loading ? <Center><Loader2 className="kb-spin" size={22} /></Center>
            : !art ? <p style={{ color: 'var(--text-muted)' }}>Could not load this article.</p>
            : <div className="kb-content" dangerouslySetInnerHTML={{ __html: art.content || `<p>${art.excerpt || ''}</p>` }} />}
        </div>
      </div>
    </div>
  )
}

function Center({ children }) { return <div style={{ display: 'flex', justifyContent: 'center', padding: 60 }}>{children}</div> }
function Empty({ q }) { return <div style={{ textAlign: 'center', color: 'var(--text-muted)', padding: 48, fontSize: 14 }}>{q ? 'No articles match your search.' : 'No articles published yet.'}</div> }

const CSS = `
.kb-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; gap: 12px; flex-wrap: wrap; }
.kb-search { display: flex; align-items: center; gap: 7px; background: var(--bg-input, rgba(255,255,255,0.05)); border: 1px solid var(--border, rgba(255,255,255,0.12)); border-radius: 9px; padding: 7px 11px; min-width: 220px; }
.kb-search input { background: transparent; border: none; outline: none; color: var(--text-h); font-size: 13px; width: 100%; }
.kb-cat { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); margin-bottom: 10px; }
.kb-card { text-align: left; background: var(--bg-card, rgba(255,255,255,0.02)); border: 1px solid var(--border, rgba(255,255,255,0.08)); border-radius: 12px; padding: 14px; cursor: pointer; transition: border-color 0.15s; }
.kb-card:hover { border-color: var(--portal-purple, #7c3aed); }
.kb-modal { width: 100%; max-width: 720px; background: var(--bg-card, #14161c); border: 1px solid var(--border, rgba(255,255,255,0.1)); border-radius: 14px; overflow: hidden; }
.kb-modal-head { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-bottom: 1px solid var(--border, rgba(255,255,255,0.08)); }
.kb-icon { background: transparent; border: none; color: var(--text-muted); cursor: pointer; padding: 4px; border-radius: 6px; }
.kb-icon:hover { color: var(--text-h); }
.kb-content { color: var(--text-body, #cbd5e1); font-size: 14px; line-height: 1.7; }
.kb-content h1,.kb-content h2,.kb-content h3 { color: var(--text-h); margin: 1em 0 0.4em; }
.kb-content ul,.kb-content ol { padding-left: 1.4em; margin: 0.6em 0; }
.kb-content a { color: var(--portal-purple-light, #a78bfa); }
.kb-content img { max-width: 100%; border-radius: 8px; }
.kb-spin { animation: kb-spin 0.9s linear infinite; }
@keyframes kb-spin { to { transform: rotate(360deg); } }
`
