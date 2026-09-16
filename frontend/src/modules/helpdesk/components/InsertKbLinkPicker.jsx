import { useState, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { BookMarked } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'
import PickerPopover, { PickerRow, PickerEmpty, PickerTrigger } from './PickerPopover'

/* Insert-knowledge-base-link picker for the ticket composer. Lists PUBLISHED
   articles that have a public slug and, on pick, hands an anchor tag back — the
   body is Quill HTML, so an <a> renders as a real link. The URL mirrors
   KbAdmin's copy-link exactly: the public article is a frontend route
   (/kb/a/:slug), built against the current origin.

   The panel itself — placement, sizing, dismissal — lives in PickerPopover,
   which is shared with CannedResponsePicker. This file is only the list. */
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

  const close = () => { setOpen(false); setQ('') }

  const pick = (a) => {
    const url = `${window.location.origin}/kb/a/${a.public_slug}`
    // Escape the title so a stray quote/angle-bracket can't break the anchor.
    const label = String(a.title || url).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    onInsert(`<a href="${url}">${label}</a>`)
    close()
  }

  return (
    <>
      <PickerTrigger onClick={() => setOpen(o => !o)}
        icon={BookMarked} label="KB link" active={open} />

      <PickerPopover
        open={open} onClose={close}
        placeholder="Search knowledge base…" query={q} onQuery={setQ}
      >
        {filtered.length === 0 && (
          <PickerEmpty>
            {q.trim()
              ? 'No published article matches that.'
              : 'No published articles to link. Publish one in KB Admin first.'}
          </PickerEmpty>
        )}
        {filtered.map(a => (
          <PickerRow key={a.id} onClick={() => pick(a)}>
            <span className="text-sm font-bold block truncate" style={{ color: 'var(--text-h)' }}>{a.title}</span>
            {/* The slug is a hint, not the point — it truncates rather than
                forcing the panel wider than the space it has. */}
            <span className="text-[11px] font-mono truncate block" style={{ color: 'var(--text-muted)' }}>/kb/a/{a.public_slug}</span>
          </PickerRow>
        ))}
      </PickerPopover>
    </>
  )
}
