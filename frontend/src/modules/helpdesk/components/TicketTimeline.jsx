import { useQuery } from '@tanstack/react-query'
import { MessageSquare, StickyNote, Bell, UserCog } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

/* ───────────────────────────────────────────────────────────────
   Unified activity Timeline (SDS reusable Timeline pattern).
   Merges the conversation, private notes and reminders into one
   chronological stream. Reuses the same react-query keys as the
   Intelligence Panel, so no extra network round-trips.

   Note: there is no server-side audit/status-history endpoint yet,
   so status changes and assignments are not shown here — only the
   records the API actually exposes. (Flagged, not faked.)
─────────────────────────────────────────────────────────────── */

const fmt = ts => ts
  ? new Date(ts).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
  : ''

const TYPES = {
  reply:    { icon: MessageSquare, color: 'var(--color-support-500)' },
  note:     { icon: StickyNote,    color: 'var(--color-warning-500)' },
  reminder: { icon: Bell,          color: 'var(--color-info-500)' },
  system:   { icon: UserCog,       color: 'var(--text-muted)' },
}

export default function TicketTimeline({ ticketId, replies = [] }) {
  const { data: notes = [] } = useQuery({ queryKey: ['ticket-notes', ticketId], queryFn: () => helpdeskApi.tickets.notes(ticketId) })
  const { data: reminders = [] } = useQuery({ queryKey: ['ticket-reminders', ticketId], queryFn: () => helpdeskApi.tickets.reminders(ticketId) })

  const events = [
    ...replies.map(r => ({
      type: 'reply', at: r.created_at,
      title: `${r.sender_type === 'client' ? 'Customer' : 'Agent'} replied`,
      body: r.message,
    })),
    ...notes.map(n => ({
      type: 'note', at: n.created_at,
      title: `${n.user?.name || 'Someone'} added a private note`,
      body: n.content,
    })),
    ...reminders.map(r => ({
      type: 'reminder', at: r.remind_at,
      title: r.is_done ? 'Reminder completed' : 'Reminder set',
      body: r.note,
    })),
  ].filter(e => e.at).sort((a, b) => new Date(a.at) - new Date(b.at))

  if (events.length === 0) {
    return <p className="text-center text-sm py-10" style={{ color: 'var(--text-muted)' }}>No activity yet.</p>
  }

  return (
    <ol className="relative pl-1">
      {events.map((e, i) => {
        const cfg = TYPES[e.type] || TYPES.system
        const Icon = cfg.icon
        const last = i === events.length - 1
        return (
          <li key={i} className="relative flex gap-3 pb-5">
            {/* connector line */}
            {!last && <span className="absolute left-[15px] top-8 bottom-0 w-px" style={{ background: 'var(--border)' }} />}
            <span className="relative z-10 w-8 h-8 rounded-full flex items-center justify-center shrink-0"
              style={{ background: `color-mix(in srgb, ${cfg.color} 14%, transparent)`, border: `1px solid color-mix(in srgb, ${cfg.color} 30%, transparent)` }}>
              <Icon size={14} style={{ color: cfg.color }} />
            </span>
            <div className="min-w-0 flex-1 pt-1">
              <div className="flex items-baseline gap-2 flex-wrap">
                <span className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{e.title}</span>
                <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{fmt(e.at)}</span>
              </div>
              {e.body && <p className="text-xs mt-1 leading-relaxed line-clamp-3" style={{ color: 'var(--text-body)' }}>{e.body}</p>}
            </div>
          </li>
        )
      })}
    </ol>
  )
}
