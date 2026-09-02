/**
 * The conversation on a request, and the record of what was done to it.
 *
 * Used by both sides. The employee's copy and the admin's copy differ only in
 * what the SERVER sent — internal notes are filtered there, not hidden here, so
 * a mistake in this file cannot leak one. Notes are still styled distinctly, so
 * an admin can see at a glance which remarks the employee is reading.
 *
 * Events and messages share one timeline rather than sitting in separate lists.
 * "Held, employee answered, held again, approved" only reads as a sequence when
 * it is one, and the sequence is the thing an approver picking this up needs.
 */

import { MessageSquare, Lock, Paperclip } from 'lucide-react'

/** Events carry their own colour so the shape of a claim is legible at a glance. */
const EVENT_TONE = {
  submitted:         { fg: '#94a3b8', label: 'Submitted' },
  held:              { fg: '#fbbf24', label: 'Put on hold' },
  hold_cleared:      { fg: '#60a5fa', label: 'Back in the queue' },
  amount_changed:    { fg: '#fbbf24', label: 'Amount changed' },
  proposal_accepted: { fg: '#34d399', label: 'Employee accepted' },
  approved:          { fg: '#34d399', label: 'Approved' },
  declined:          { fg: '#f87171', label: 'Declined' },
}

const when = ts => {
  if (!ts) return ''
  const d = new Date(ts)
  return Number.isNaN(d.getTime())
    ? ''
    : d.toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}

function Attachments({ items, onOpen }) {
  if (!items?.length) return null

  return (
    <div className="flex flex-wrap gap-1.5 mt-2">
      {items.map(a => (
        // A button, not a link. These files sit behind a Bearer token, so an
        // href the browser follows itself arrives unauthenticated — the parent
        // fetches the bytes and opens them.
        <button key={a.id} type="button" onClick={() => onOpen?.(a)}
          className="flex items-center gap-1.5 rounded-lg text-[11px] font-semibold"
          style={{ padding: '4px 9px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#a78bfa' }}>
          <Paperclip size={11} />
          {a.name || 'Attachment'}
          {a.size_label && <span className="opacity-60 font-normal">· {a.size_label}</span>}
        </button>
      ))}
    </div>
  )
}

export default function RequestThread({ entries = [], onOpenFile, emptyLabel = 'Nothing on this request yet.' }) {
  if (!entries.length) {
    return (
      <p className="text-xs italic" style={{ color: 'var(--text-muted)' }}>{emptyLabel}</p>
    )
  }

  return (
    <ol className="flex flex-col gap-2.5" style={{ listStyle: 'none', margin: 0, padding: 0 }}>
      {entries.map(e => {
        const isEvent = e.kind === 'event'
        const isNote  = e.kind === 'note'
        const tone    = EVENT_TONE[e.event_type] || { fg: 'var(--text-muted)', label: e.event_type }

        // An event is a one-line fact; a message is somebody talking. Rendering
        // them the same size buries the conversation under the bookkeeping.
        if (isEvent) {
          return (
            <li key={e.id} className="flex items-start gap-2 text-[11px]" style={{ color: 'var(--text-muted)' }}>
              <span className="rounded-full shrink-0" style={{ width: 6, height: 6, marginTop: 5, background: tone.fg }} />
              <span className="flex-1">
                <span className="font-bold" style={{ color: tone.fg }}>{tone.label}</span>
                {' — '}{e.body}
                {e.author?.name && <span className="opacity-70"> · {e.author.name}</span>}
                <span className="opacity-60"> · {when(e.created_at)}</span>
              </span>
            </li>
          )
        }

        return (
          <li key={e.id} className="rounded-xl" style={{
            padding: '9px 11px',
            background: isNote ? 'rgba(251,191,36,0.07)' : 'var(--bg-input)',
            border: `1px solid ${isNote ? 'rgba(251,191,36,0.25)' : 'var(--border)'}`,
          }}>
            <div className="flex items-center gap-1.5 mb-1">
              {isNote
                ? <Lock size={11} style={{ color: '#fbbf24' }} />
                : <MessageSquare size={11} style={{ color: 'var(--text-muted)' }} />}
              <span className="text-[11px] font-bold" style={{ color: 'var(--text-h)' }}>
                {e.author?.name || 'Someone'}
              </span>
              {isNote && (
                <span className="text-[9px] font-bold uppercase tracking-wider" style={{ color: '#fbbf24' }}>
                  Internal — the employee cannot see this
                </span>
              )}
              <span className="text-[10px] ml-auto" style={{ color: 'var(--text-muted)' }}>{when(e.created_at)}</span>
            </div>
            <p className="text-xs whitespace-pre-wrap" style={{ color: 'var(--text-p)', margin: 0 }}>{e.body}</p>
            <Attachments items={e.attachments} onOpen={onOpenFile} />
          </li>
        )
      })}
    </ol>
  )
}
