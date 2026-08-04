import { useState, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { BookMarked, Search, X } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

/* Insert-knowledge-base-link picker for the ticket reply composer (image12's
   "Insert knowledge base link" dropdown, beside the canned-response picker).
   Lists PUBLISHED articles that have a public slug and, on pick, hands an anchor
   tag back to the composer — the reply body is Quill HTML, so an <a> renders as
   a real link. The URL mirrors KbAdmin's copy-link exactly: the public article
   is a frontend route (/kb/a/:slug), built against the current origin. */
export default function InsertKbLinkPicker({ onInsert }) {
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const { data: list = [] } = useQuery({ queryKey: ['kb-articles', 'link-picker'], queryFn: () => helpdeskApi.kb.articles(), enabled: open })

  // Only articles a customer can actually reach: published + has a public slug.
  const linkable = useMemo(
    () => (Array.isArray(list) ? list : []).filter(a => a.is_published && a.public_slug),
    [list],
  )

  const filtered = useMemo(() => {
    const t = q.trim().toLowerCase()
    if (!t) return linkable
    return linkable.filter(a => `${a.title} ${a.public_slug || ''}`.toLowerCase().includes(t))
  }, [linkable, q])

  const pick = (a) => {
    const url = `${window.location.origin}/kb/a/${a.public_slug}`
    // Escape the title so a stray quote/angle-bracket can't break the anchor.
    const label = String(a.title || url).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    onInsert(`<a href="${url}">${label}</a>`)
    setOpen(false); setQ('')
  }

  return (
    <div className="relative">
      <button type="button" onClick={() => setOpen(o => !o)}
        className="flex items-center gap-1.5 text-xs font-semibold transition-opacity hover:opacity-70"
        style={{ color: 'var(--text-muted)' }}>
        <BookMarked size={14} /> KB link
      </button>

      {open && (
        <>
          <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} />
          <div className="absolute bottom-full left-0 mb-2 z-50 w-80 rounded-2xl overflow-hidden"
            style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}>
            <div className="flex items-center gap-2 px-3 py-2.5" style={{ borderBottom: '1px solid var(--border)' }}>
              <Search size={14} style={{ color: 'var(--text-muted)' }} />
              <input autoFocus value={q} onChange={e => setQ(e.target.value)} placeholder="Search knowledge base…"
                className="flex-1 bg-transparent outline-none text-sm" style={{ color: 'var(--text-h)' }} />
              <button onClick={() => setOpen(false)}><X size={14} style={{ color: 'var(--text-muted)' }} /></button>
            </div>
            <div className="max-h-72 overflow-y-auto py-1">
              {filtered.length === 0 && <p className="px-3 py-4 text-xs text-center" style={{ color: 'var(--text-muted)' }}>No published articles to link. Publish one in KB Admin first.</p>}
              {filtered.map(a => (
                <button key={a.id} type="button" onClick={() => pick(a)} className="w-full text-left px-3 py-2.5 transition-colors"
                  onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'} onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                  <span className="text-sm font-bold block" style={{ color: 'var(--text-h)' }}>{a.title}</span>
                  <span className="text-[11px] font-mono truncate block" style={{ color: 'var(--text-muted)' }}>/kb/a/{a.public_slug}</span>
                </button>
              ))}
            </div>
          </div>
        </>
      )}
    </div>
  )
}
