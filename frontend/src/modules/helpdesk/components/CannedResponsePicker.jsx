import { useState, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { MessageSquareText, Search, X } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

/* Canned-response picker for the ticket reply composer. Click a saved reply to
   insert its text into the composer; usage is recorded (best-effort). */
export default function CannedResponsePicker({ onInsert }) {
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const { data: list = [] } = useQuery({ queryKey: ['canned-responses'], queryFn: helpdeskApi.cannedResponses.list, enabled: open })

  const filtered = useMemo(() => {
    const t = q.trim().toLowerCase()
    if (!t) return list
    return list.filter(cr => `${cr.title} ${cr.shortcut || ''} ${cr.content}`.toLowerCase().includes(t))
  }, [list, q])

  const pick = (cr) => {
    onInsert(cr.content)
    helpdeskApi.cannedResponses.used(cr.id).catch(() => {})
    setOpen(false); setQ('')
  }

  return (
    <div className="relative">
      <button type="button" onClick={() => setOpen(o => !o)}
        className="flex items-center gap-1.5 text-xs font-semibold transition-opacity hover:opacity-70"
        style={{ color: 'var(--text-muted)' }}>
        <MessageSquareText size={14} /> Canned
      </button>

      {open && (
        <>
          <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} />
          <div className="absolute bottom-full left-0 mb-2 z-50 w-80 rounded-2xl overflow-hidden"
            style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}>
            <div className="flex items-center gap-2 px-3 py-2.5" style={{ borderBottom: '1px solid var(--border)' }}>
              <Search size={14} style={{ color: 'var(--text-muted)' }} />
              <input autoFocus value={q} onChange={e => setQ(e.target.value)} placeholder="Search saved replies…"
                className="flex-1 bg-transparent outline-none text-sm" style={{ color: 'var(--text-h)' }} />
              <button onClick={() => setOpen(false)}><X size={14} style={{ color: 'var(--text-muted)' }} /></button>
            </div>
            <div className="max-h-72 overflow-y-auto py-1">
              {filtered.length === 0 && <p className="px-3 py-4 text-xs text-center" style={{ color: 'var(--text-muted)' }}>No saved replies. Create them in KB Admin → Canned Responses.</p>}
              {filtered.map(cr => (
                <button key={cr.id} type="button" onClick={() => pick(cr)} className="w-full text-left px-3 py-2.5 transition-colors"
                  onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'} onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                  <div className="flex items-center gap-2 mb-0.5">
                    <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{cr.title}</span>
                    {cr.shortcut && <span className="text-[10px] font-mono px-1.5 py-0.5 rounded" style={{ background: 'color-mix(in srgb, var(--color-support-500) 12%, transparent)', color: 'var(--color-support-500)' }}>{cr.shortcut}</span>}
                  </div>
                  <p className="text-xs line-clamp-2" style={{ color: 'var(--text-muted)' }}>{String(cr.content || '').replace(/<[^>]*>/g, ' ').replace(/&nbsp;/gi, ' ').replace(/\s+/g, ' ').trim()}</p>
                </button>
              ))}
            </div>
          </div>
        </>
      )}
    </div>
  )
}
