import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { StickyNote, Bell, Link2, ChevronDown, Plus, Check, X } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

const fmtWhen = ts => ts ? new Date(ts).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''

/**
 * The ticket "Intelligence Panel" right-rail (Phase 2 → extended in Phase 7).
 * Houses private notes, reminders and related-ticket links. Private notes use a
 * distinct amber styling so they never read as client-facing replies.
 */
export default function TicketIntelligencePanel({ ticketId }) {
  return (
    <aside className="space-y-3 w-full">
      <NotesSection ticketId={ticketId} />
      <RemindersSection ticketId={ticketId} />
      <RelatedSection ticketId={ticketId} />
    </aside>
  )
}

function Section({ icon: Icon, title, count, children, defaultOpen = true, accent = '#22d3ee' }) {
  const [open, setOpen] = useState(defaultOpen)
  return (
    <div className="rounded-2xl border" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
      <button onClick={() => setOpen(o => !o)} className="w-full flex items-center gap-2 px-4 py-3">
        <Icon size={14} style={{ color: accent }} />
        <span className="text-sm font-semibold flex-1 text-left" style={{ color: 'var(--text-h)' }}>{title}</span>
        {count != null && <span className="text-[10px] px-1.5 py-0.5 rounded-lg" style={{ background: 'var(--border)', color: 'var(--text-muted)' }}>{count}</span>}
        <ChevronDown size={14} style={{ color: 'var(--text-muted)', transform: open ? 'none' : 'rotate(-90deg)', transition: 'transform .15s' }} />
      </button>
      {open && <div className="px-4 pb-4">{children}</div>}
    </div>
  )
}

function NotesSection({ ticketId }) {
  const qc = useQueryClient()
  const key = ['ticket-notes', ticketId]
  const { data: notes = [] } = useQuery({ queryKey: key, queryFn: () => helpdeskApi.tickets.notes(ticketId) })
  const [text, setText] = useState('')
  const add = useMutation({ mutationFn: () => helpdeskApi.tickets.addNote(ticketId, text), onSuccess: () => { setText(''); qc.invalidateQueries({ queryKey: key }) } })

  return (
    <Section icon={StickyNote} title="Private Notes" count={notes.length} accent="#f59e0b">
      <ul className="space-y-2 mb-3">
        {notes.map(n => (
          <li key={n.id} className="rounded-xl p-2.5 text-xs" style={{ background: 'rgba(245,158,11,0.08)', border: '1px solid rgba(245,158,11,0.25)', color: '#fde68a' }}>
            <p className="leading-relaxed" style={{ color: 'var(--text-h)' }}>{n.content}</p>
            <p className="mt-1 text-[10px]" style={{ color: 'var(--text-muted)' }}>{n.user?.name} · {fmtWhen(n.created_at)}</p>
          </li>
        ))}
        {notes.length === 0 && <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No private notes. Only staff can see these.</li>}
      </ul>
      <textarea value={text} onChange={e => setText(e.target.value)} rows={2} placeholder="Add a private note…"
        className="w-full text-xs bg-transparent border rounded-lg px-2 py-1.5 outline-none resize-none" style={{ borderColor: 'var(--border)', color: 'var(--text-h)' }} />
      <button disabled={!text.trim() || add.isPending} onClick={() => add.mutate()}
        className="mt-2 w-full flex items-center justify-center gap-1 text-xs font-semibold py-1.5 rounded-lg disabled:opacity-40" style={{ background: 'rgba(245,158,11,0.15)', color: '#fbbf24' }}>
        <Plus size={12} /> Add note
      </button>
    </Section>
  )
}

function RemindersSection({ ticketId }) {
  const qc = useQueryClient()
  const key = ['ticket-reminders', ticketId]
  const { data: reminders = [] } = useQuery({ queryKey: key, queryFn: () => helpdeskApi.tickets.reminders(ticketId) })
  const [form, setForm] = useState({ remind_at: '', note: '' })
  const invalidate = () => qc.invalidateQueries({ queryKey: key })
  const add = useMutation({ mutationFn: () => helpdeskApi.tickets.addReminder(ticketId, form), onSuccess: () => { setForm({ remind_at: '', note: '' }); invalidate() } })
  const toggle = useMutation({ mutationFn: (id) => helpdeskApi.tickets.reminderDone(id), onSuccess: invalidate })

  return (
    <Section icon={Bell} title="Reminders" count={reminders.length}>
      <ul className="space-y-1.5 mb-3">
        {reminders.map(r => (
          <li key={r.id} className="flex items-start gap-2 text-xs">
            <button onClick={() => toggle.mutate(r.id)} className="mt-0.5 shrink-0 w-4 h-4 rounded flex items-center justify-center border" style={{ borderColor: r.is_done ? '#10b981' : 'var(--border)', background: r.is_done ? 'rgba(16,185,129,0.2)' : 'transparent' }}>
              {r.is_done && <Check size={11} style={{ color: '#10b981' }} />}
            </button>
            <div className="flex-1">
              <p style={{ color: 'var(--text-h)', textDecoration: r.is_done ? 'line-through' : 'none', opacity: r.is_done ? 0.6 : 1 }}>{r.note || 'Reminder'}</p>
              <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{fmtWhen(r.remind_at)}</p>
            </div>
          </li>
        ))}
        {reminders.length === 0 && <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No reminders.</li>}
      </ul>
      <div className="space-y-1.5">
        <input type="datetime-local" value={form.remind_at} onChange={e => setForm({ ...form, remind_at: e.target.value })}
          className="w-full text-xs bg-transparent border rounded-lg px-2 py-1.5 outline-none" style={{ borderColor: 'var(--border)', color: 'var(--text-h)' }} />
        <input value={form.note} onChange={e => setForm({ ...form, note: e.target.value })} placeholder="note (optional)"
          className="w-full text-xs bg-transparent border rounded-lg px-2 py-1.5 outline-none" style={{ borderColor: 'var(--border)', color: 'var(--text-h)' }} />
        <button disabled={!form.remind_at || add.isPending} onClick={() => add.mutate()}
          className="w-full flex items-center justify-center gap-1 text-xs font-semibold py-1.5 rounded-lg disabled:opacity-40" style={{ background: 'rgba(34,211,238,0.15)', color: '#22d3ee' }}>
          <Plus size={12} /> Set reminder
        </button>
      </div>
    </Section>
  )
}

function RelatedSection({ ticketId }) {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const key = ['ticket-related', ticketId]
  const { data: related = [] } = useQuery({ queryKey: key, queryFn: () => helpdeskApi.tickets.related(ticketId) })
  const [rid, setRid] = useState('')
  const invalidate = () => qc.invalidateQueries({ queryKey: key })
  const add = useMutation({ mutationFn: () => helpdeskApi.tickets.addRelated(ticketId, Number(rid)), onSuccess: () => { setRid(''); invalidate() } })
  const remove = useMutation({ mutationFn: (id) => helpdeskApi.tickets.removeRelated(ticketId, id), onSuccess: invalidate })

  return (
    <Section icon={Link2} title="Related Tickets" count={related.length}>
      <ul className="space-y-1 mb-3">
        {related.map(t => (
          <li key={t.id} className="flex items-center gap-2 text-xs group">
            <button onClick={() => navigate(`/app/helpdesk/tickets/${t.id}`)} className="flex-1 flex items-center gap-2 text-left hover:underline" style={{ color: 'var(--text-h)' }}>
              <span className="font-mono" style={{ color: '#22d3ee' }}>#{t.id}</span>
              <span className="truncate">{t.subject}</span>
            </button>
            <button onClick={() => remove.mutate(t.id)} className="opacity-40 hover:opacity-100 hover:text-red-400"><X size={12} /></button>
          </li>
        ))}
        {related.length === 0 && <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No linked tickets.</li>}
      </ul>
      <div className="flex gap-1.5">
        <input value={rid} onChange={e => setRid(e.target.value.replace(/\D/g, ''))} placeholder="ticket id"
          className="flex-1 text-xs bg-transparent border rounded-lg px-2 py-1.5 outline-none" style={{ borderColor: 'var(--border)', color: 'var(--text-h)' }} />
        <button disabled={!rid || add.isPending} onClick={() => add.mutate()}
          className="flex items-center gap-1 text-xs font-semibold px-3 rounded-lg disabled:opacity-40" style={{ background: 'rgba(34,211,238,0.15)', color: '#22d3ee' }}>
          <Link2 size={12} /> Link
        </button>
      </div>
      {add.isError && <p className="text-[11px] text-red-400 mt-1">{add.error?.message}</p>}
    </Section>
  )
}
