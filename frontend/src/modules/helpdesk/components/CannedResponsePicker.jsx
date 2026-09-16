import { useState, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { MessageSquareText } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'
import PickerPopover, { PickerRow, PickerEmpty, PickerTrigger } from './PickerPopover'

/* Canned-response picker for the ticket composer. Click a saved reply to insert
   its text; usage is recorded (best-effort).

   The panel itself — placement, sizing, dismissal — lives in PickerPopover,
   which is shared with InsertKbLinkPicker. This file is only the list. */
export default function CannedResponsePicker({ onInsert }) {
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const { data: list = [] } = useQuery({ queryKey: ['canned-responses'], queryFn: helpdeskApi.cannedResponses.list, enabled: open })

  const filtered = useMemo(() => {
    const t = q.trim().toLowerCase()
    const rows = Array.isArray(list) ? list : []
    if (!t) return rows
    return rows.filter(cr => `${cr.title} ${cr.shortcut || ''} ${cr.content}`.toLowerCase().includes(t))
  }, [list, q])

  const close = () => { setOpen(false); setQ('') }

  const pick = (cr) => {
    onInsert(cr.content)
    helpdeskApi.cannedResponses.used(cr.id).catch(() => {})
    close()
  }

  // Strip the stored HTML down to a one-line gist for the preview row.
  const preview = (html) => String(html || '')
    .replace(/<[^>]*>/g, ' ')
    .replace(/&nbsp;/gi, ' ')
    .replace(/\s+/g, ' ')
    .trim()

  return (
    <>
      <PickerTrigger onClick={() => setOpen(o => !o)}
        icon={MessageSquareText} label="Canned" active={open} />

      <PickerPopover
        open={open} onClose={close}
        placeholder="Search saved replies…" query={q} onQuery={setQ}
      >
        {filtered.length === 0 && (
          <PickerEmpty>
            {q.trim()
              ? 'No saved reply matches that.'
              : 'No saved replies yet. Create them in KB Admin → Canned Responses.'}
          </PickerEmpty>
        )}
        {filtered.map(cr => (
          <PickerRow key={cr.id} onClick={() => pick(cr)}>
            <div className="flex items-center gap-2 mb-0.5">
              <span className="text-sm font-bold truncate" style={{ color: 'var(--text-h)' }}>{cr.title}</span>
              {cr.shortcut && (
                <span className="text-[10px] font-mono px-1.5 py-0.5 rounded shrink-0"
                  style={{ background: 'color-mix(in srgb, var(--color-support-500) 12%, transparent)', color: 'var(--color-support-500)' }}>
                  {cr.shortcut}
                </span>
              )}
            </div>
            <p className="text-xs line-clamp-2" style={{ color: 'var(--text-muted)' }}>{preview(cr.content)}</p>
          </PickerRow>
        ))}
      </PickerPopover>
    </>
  )
}
