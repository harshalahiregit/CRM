import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { StickyNote, Bell, Link2, Tag, SlidersHorizontal, ChevronDown, Plus, Check, X } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'
import SLABadge from './ui/SLABadge'

const fmtWhen = ts => ts ? new Date(ts).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''

/* Light Freshdesk-style tokens (shared with the ticket workspace). */
const CARD = { border: '1px solid #e7eaf2', background: '#fff', borderRadius: 16 }
const INPUT = { border: '1px solid #dfe4ee', color: '#16233d', background: '#fff' }
const MUTED = '#7a879e'

/**
 * The ticket "Intelligence Panel" right-rail (Phase 2 → extended in Phase 7).
 * Houses properties, tags, private notes, reminders and related-ticket links.
 * Private notes keep a distinct amber styling so they never read as client replies.
 */
export default function TicketIntelligencePanel({ ticketId }) {
  return (
    <aside className="space-y-3 w-full">
      <PropertiesSection ticketId={ticketId} />
      <TagsSection ticketId={ticketId} />
      <NotesSection ticketId={ticketId} />
      <RemindersSection ticketId={ticketId} />
      <RelatedSection ticketId={ticketId} />
    </aside>
  )
}

function PropertiesSection({ ticketId }) {
  const qc = useQueryClient()
  const tkey = ['helpdesk-ticket', String(ticketId)]
  const { data: ticket } = useQuery({ queryKey: tkey, queryFn: () => helpdeskApi.tickets.get(ticketId) })
  const { data: settings } = useQuery({ queryKey: ['helpdesk-settings'], queryFn: helpdeskApi.settings.all })
  const invalidate = () => qc.invalidateQueries({ queryKey: tkey })
  const setStatus = useMutation({ mutationFn: (s) => helpdeskApi.tickets.setStatus(ticketId, s), onSuccess: invalidate })
  const update = useMutation({ mutationFn: (data) => helpdeskApi.tickets.update(ticketId, data), onSuccess: invalidate })

  if (!ticket) return null
  const sel = 'w-full text-xs rounded-lg px-2 py-1.5 outline-none'

  return (
    <Section icon={SlidersHorizontal} title="Properties" accent="#3b6fed">
      <div className="space-y-2.5">
        <Field label="Status">
          <select value={ticket.status} onChange={e => setStatus.mutate(e.target.value)} className={sel} style={INPUT}>
            {(settings?.statuses || []).map(s => <option key={s.id} value={s.name}>{s.name}</option>)}
          </select>
        </Field>
        <Field label={<span className="flex items-center gap-1.5">Priority <SLABadge priority={ticket.priority} dueDate={ticket.due_date} /></span>}>
          <select value={ticket.priority} onChange={e => update.mutate({ priority: e.target.value })} className={sel} style={INPUT}>
            {(settings?.priorities || []).map(p => <option key={p.id} value={p.name}>{p.name}</option>)}
          </select>
        </Field>
        <Field label="Department">
          <select value={ticket.department_id || ''} onChange={e => update.mutate({ department_id: e.target.value ? Number(e.target.value) : null })} className={sel} style={INPUT}>
            <option value="">— none —</option>
            {(settings?.departments || []).map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
          </select>
        </Field>
      </div>
    </Section>
  )
}

function Field({ label, children }) {
  return (
    <label className="block">
      <span className="text-[10px] uppercase tracking-wide block mb-1" style={{ color: MUTED }}>{label}</span>
      {children}
    </label>
  )
}

function TagsSection({ ticketId }) {
  const qc = useQueryClient()
  const key = ['ticket-tags', ticketId]
  const { data: tags = [] } = useQuery({ queryKey: key, queryFn: () => helpdeskApi.tickets.tags(ticketId) })
  const [name, setName] = useState('')
  const invalidate = () => qc.invalidateQueries({ queryKey: key })
  const add = useMutation({ mutationFn: () => helpdeskApi.tickets.addTag(ticketId, { name }), onSuccess: () => { setName(''); invalidate() } })
  const remove = useMutation({ mutationFn: (id) => helpdeskApi.tickets.removeTag(ticketId, id), onSuccess: invalidate })

  return (
    <Section icon={Tag} title="Tags" count={tags.length} accent="#7c3aed">
      <div className="flex flex-wrap gap-1.5 mb-2">
        {tags.map(t => (
          <span key={t.id} className="flex items-center gap-1 text-[11px] font-semibold px-2 py-1 rounded-lg" style={{ background: `${t.color}18`, color: t.color, border: `1px solid ${t.color}44` }}>
            {t.name}
            <button onClick={() => remove.mutate(t.id)} className="opacity-60 hover:opacity-100"><X size={10} /></button>
          </span>
        ))}
        {tags.length === 0 && <span className="text-xs" style={{ color: MUTED }}>No tags.</span>}
      </div>
      <div className="flex gap-1.5">
        <input value={name} onChange={e => setName(e.target.value)} onKeyDown={e => e.key === 'Enter' && name.trim() && add.mutate()} placeholder="add a tag…"
          className="flex-1 text-xs rounded-lg px-2 py-1.5 outline-none" style={INPUT} />
        <button disabled={!name.trim() || add.isPending} onClick={() => add.mutate()}
          className="flex items-center gap-1 text-xs font-semibold px-3 rounded-lg disabled:opacity-40" style={{ background: '#f3eefe', color: '#7c3aed' }}>
          <Plus size={12} />
        </button>
      </div>
    </Section>
  )
}

function Section({ icon: Icon, title, count, children, defaultOpen = true, accent = '#3b6fed' }) {
  const [open, setOpen] = useState(defaultOpen)
  return (
    <div style={{ ...CARD, boxShadow: '0 1px 2px rgba(20,30,60,0.04)' }}>
      <button onClick={() => setOpen(o => !o)} className="w-full flex items-center gap-2 px-4 py-3">
        <Icon size={14} style={{ color: accent }} />
        <span className="text-sm font-semibold flex-1 text-left" style={{ color: '#16233d' }}>{title}</span>
        {count != null && <span className="text-[10px] px-1.5 py-0.5 rounded-lg" style={{ background: '#eef1f7', color: MUTED }}>{count}</span>}
        <ChevronDown size={14} style={{ color: '#9aa4ba', transform: open ? 'none' : 'rotate(-90deg)', transition: 'transform .15s' }} />
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
    <Section icon={StickyNote} title="Private Notes" count={notes.length} accent="#d97706">
      <ul className="space-y-2 mb-3">
        {notes.map(n => (
          <li key={n.id} className="rounded-xl p-2.5 text-xs" style={{ background: '#fff8ec', border: '1px solid #f3e2b8' }}>
            <p className="leading-relaxed" style={{ color: '#16233d' }}>{n.content}</p>
            <p className="mt-1 text-[10px]" style={{ color: '#b08a4a' }}>{n.user?.name} · {fmtWhen(n.created_at)}</p>
          </li>
        ))}
        {notes.length === 0 && <li className="text-xs" style={{ color: MUTED }}>No private notes. Only staff can see these.</li>}
      </ul>
      <textarea value={text} onChange={e => setText(e.target.value)} rows={2} placeholder="Add a private note…"
        className="w-full text-xs rounded-lg px-2 py-1.5 outline-none resize-none" style={INPUT} />
      <button disabled={!text.trim() || add.isPending} onClick={() => add.mutate()}
        className="mt-2 w-full flex items-center justify-center gap-1 text-xs font-semibold py-1.5 rounded-lg disabled:opacity-40" style={{ background: '#fdf2e0', color: '#d97706' }}>
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
    <Section icon={Bell} title="Reminders" count={reminders.length} accent="#0891b2">
      <ul className="space-y-1.5 mb-3">
        {reminders.map(r => (
          <li key={r.id} className="flex items-start gap-2 text-xs">
            <button onClick={() => toggle.mutate(r.id)} className="mt-0.5 shrink-0 w-4 h-4 rounded flex items-center justify-center border" style={{ borderColor: r.is_done ? '#10b981' : '#dfe4ee', background: r.is_done ? 'rgba(16,185,129,0.15)' : '#fff' }}>
              {r.is_done && <Check size={11} style={{ color: '#10b981' }} />}
            </button>
            <div className="flex-1">
              <p style={{ color: '#16233d', textDecoration: r.is_done ? 'line-through' : 'none', opacity: r.is_done ? 0.55 : 1 }}>{r.note || 'Reminder'}</p>
              <p className="text-[10px]" style={{ color: MUTED }}>{fmtWhen(r.remind_at)}</p>
            </div>
          </li>
        ))}
        {reminders.length === 0 && <li className="text-xs" style={{ color: MUTED }}>No reminders.</li>}
      </ul>
      <div className="space-y-1.5">
        <input type="datetime-local" value={form.remind_at} onChange={e => setForm({ ...form, remind_at: e.target.value })}
          className="w-full text-xs rounded-lg px-2 py-1.5 outline-none" style={INPUT} />
        <input value={form.note} onChange={e => setForm({ ...form, note: e.target.value })} placeholder="note (optional)"
          className="w-full text-xs rounded-lg px-2 py-1.5 outline-none" style={INPUT} />
        <button disabled={!form.remind_at || add.isPending} onClick={() => add.mutate()}
          className="w-full flex items-center justify-center gap-1 text-xs font-semibold py-1.5 rounded-lg disabled:opacity-40" style={{ background: '#e6f7fb', color: '#0891b2' }}>
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
    <Section icon={Link2} title="Related Tickets" count={related.length} accent="#3b6fed">
      <ul className="space-y-1 mb-3">
        {related.map(t => (
          <li key={t.id} className="flex items-center gap-2 text-xs group">
            <button onClick={() => navigate(`/app/helpdesk/tickets/${t.id}`)} className="flex-1 flex items-center gap-2 text-left hover:underline" style={{ color: '#38455f' }}>
              <span className="font-mono font-semibold" style={{ color: '#3b6fed' }}>#{t.id}</span>
              <span className="truncate">{t.subject}</span>
            </button>
            <button onClick={() => remove.mutate(t.id)} className="opacity-40 hover:opacity-100 hover:text-red-500"><X size={12} /></button>
          </li>
        ))}
        {related.length === 0 && <li className="text-xs" style={{ color: MUTED }}>No linked tickets.</li>}
      </ul>
      <div className="flex gap-1.5">
        <input value={rid} onChange={e => setRid(e.target.value.replace(/\D/g, ''))} placeholder="ticket id"
          className="flex-1 text-xs rounded-lg px-2 py-1.5 outline-none" style={INPUT} />
        <button disabled={!rid || add.isPending} onClick={() => add.mutate()}
          className="flex items-center gap-1 text-xs font-semibold px-3 rounded-lg disabled:opacity-40" style={{ background: '#eef4ff', color: '#3b6fed' }}>
          <Link2 size={12} /> Link
        </button>
      </div>
      {add.isError && <p className="text-[11px] text-red-500 mt-1">{add.error?.message}</p>}
    </Section>
  )
}
