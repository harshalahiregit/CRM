import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import {
  StickyNote, Bell, Link2, Tag, SlidersHorizontal,
  ChevronDown, Plus, Check, X
} from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'
import SLABadge from './ui/SLABadge'

const fmtWhen = ts =>
  ts
    ? new Date(ts).toLocaleString('en-IN', {
        day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
      })
    : ''

const initials = (name) => {
  if (!name) return '?'
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

const INP = {
  border: '1px solid var(--border)',
  color: 'var(--text-h)',
  background: 'var(--bg-input)',
}

/* ── Collapsible section wrapper ─────────────────────────── */
function Section({ icon: Icon, title, count, children, defaultOpen = true, accent = '#22d3ee' }) {
  const [open, setOpen] = useState(defaultOpen)
  return (
    <div
      className="rounded-2xl overflow-hidden"
      style={{
        background: 'var(--bg-card)',
        border: '1px solid var(--border)',
        boxShadow: 'var(--shadow-card)',
      }}
    >
      <button
        onClick={() => setOpen(o => !o)}
        className="w-full flex items-center gap-2 px-4 py-3 hover:opacity-80 transition-opacity"
      >
        <div
          className="w-6 h-6 rounded-lg flex items-center justify-center shrink-0"
          style={{ background: `${accent}18` }}
        >
          <Icon size={12} style={{ color: accent }} />
        </div>
        <span className="text-sm font-bold flex-1 text-left" style={{ color: 'var(--text-h)' }}>
          {title}
        </span>
        {count != null && (
          <span
            className="text-[10px] px-2 py-0.5 rounded-full font-bold"
            style={{ background: 'var(--bg-global)', color: 'var(--text-muted)' }}
          >
            {count}
          </span>
        )}
        <ChevronDown
          size={13}
          style={{
            color: 'var(--text-muted)',
            transform: open ? 'rotate(0deg)' : 'rotate(-90deg)',
            transition: 'transform 0.2s ease',
          }}
        />
      </button>
      {open && (
        <div className="px-4 pb-4" style={{ borderTop: '1px solid var(--border)' }}>
          <div className="pt-3">{children}</div>
        </div>
      )}
    </div>
  )
}

/* ── Field label ─────────────────────────────────────────── */
function Field({ label, children }) {
  return (
    <label className="block">
      <span
        className="text-[10px] uppercase tracking-widest block mb-1.5 font-bold"
        style={{ color: 'var(--text-muted)' }}
      >
        {label}
      </span>
      {children}
    </label>
  )
}

/* ── Main Intelligence Panel ─────────────────────────────── */
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

/* ── Properties ──────────────────────────────────────────── */
function PropertiesSection({ ticketId }) {
  const qc = useQueryClient()
  const tkey = ['helpdesk-ticket', String(ticketId)]
  const { data: ticket } = useQuery({ queryKey: tkey, queryFn: () => helpdeskApi.tickets.get(ticketId) })
  const { data: settings } = useQuery({ queryKey: ['helpdesk-settings'], queryFn: helpdeskApi.settings.all })
  const invalidate = () => qc.invalidateQueries({ queryKey: tkey })
  // Optimistic patch helper — updates the cached ticket instantly, rolls back on error.
  const patchOpt = async (patch) => { await qc.cancelQueries({ queryKey: tkey }); const prev = qc.getQueryData(tkey); qc.setQueryData(tkey, (o) => (o ? { ...o, ...patch } : o)); return { prev } }
  const rollback = (_e, _v, ctx) => ctx?.prev && qc.setQueryData(tkey, ctx.prev)
  const setStatus = useMutation({ mutationFn: (s) => helpdeskApi.tickets.setStatus(ticketId, s), onMutate: (s) => patchOpt({ status: s }), onError: rollback, onSettled: invalidate })
  const update = useMutation({ mutationFn: (data) => helpdeskApi.tickets.update(ticketId, data), onMutate: (data) => patchOpt(data), onError: rollback, onSettled: invalidate })

  if (!ticket) return null

  const SEL = 'w-full text-xs rounded-xl px-2.5 py-2 outline-none'

  return (
    <Section icon={SlidersHorizontal} title="Properties" accent="#22d3ee">
      <div className="space-y-3">
        <Field label="Status">
          <select
            value={ticket.status}
            onChange={e => setStatus.mutate(e.target.value)}
            className={SEL}
            style={INP}
          >
            {(settings?.statuses || []).map(s => (
              <option key={s.id} value={s.name}>{s.name}</option>
            ))}
          </select>
        </Field>

        <Field
          label={
            <span className="flex items-center gap-2">
              Priority
              <SLABadge priority={ticket.priority} dueDate={ticket.due_date} />
            </span>
          }
        >
          <select
            value={ticket.priority}
            onChange={e => update.mutate({ priority: e.target.value })}
            className={SEL}
            style={INP}
          >
            {(settings?.priorities || []).map(p => (
              <option key={p.id} value={p.name}>{p.name}</option>
            ))}
          </select>
        </Field>

        <Field label="Department">
          <select
            value={ticket.department_id || ''}
            onChange={e => update.mutate({ department_id: e.target.value ? Number(e.target.value) : null })}
            className={SEL}
            style={INP}
          >
            <option value="">— none —</option>
            {(settings?.departments || []).map(d => (
              <option key={d.id} value={d.id}>{d.name}</option>
            ))}
          </select>
        </Field>
      </div>
    </Section>
  )
}

/* ── Tags ────────────────────────────────────────────────── */
function TagsSection({ ticketId }) {
  const qc = useQueryClient()
  const key = ['ticket-tags', ticketId]
  const { data: tags = [] } = useQuery({ queryKey: key, queryFn: () => helpdeskApi.tickets.tags(ticketId) })
  const [name, setName] = useState('')
  const invalidate = () => qc.invalidateQueries({ queryKey: key })
  const add = useMutation({
    mutationFn: () => helpdeskApi.tickets.addTag(ticketId, { name }),
    onMutate: async () => {
      await qc.cancelQueries({ queryKey: key })
      const prev = qc.getQueryData(key)
      qc.setQueryData(key, (o = []) => [...(Array.isArray(o) ? o : []), { id: `tmp-${Date.now()}`, name, color: '#22d3ee' }])
      setName('')
      return { prev }
    },
    onError: (_e, _v, ctx) => ctx?.prev && qc.setQueryData(key, ctx.prev),
    onSettled: invalidate,
  })
  const remove = useMutation({
    mutationFn: (id) => helpdeskApi.tickets.removeTag(ticketId, id),
    onMutate: async (id) => {
      await qc.cancelQueries({ queryKey: key })
      const prev = qc.getQueryData(key)
      qc.setQueryData(key, (o = []) => (Array.isArray(o) ? o : []).filter(t => t.id !== id))
      return { prev }
    },
    onError: (_e, _v, ctx) => ctx?.prev && qc.setQueryData(key, ctx.prev),
    onSettled: invalidate,
  })

  return (
    <Section icon={Tag} title="Tags" count={tags.length} accent="#a78bfa">
      <div className="flex flex-wrap gap-1.5 mb-3">
        {tags.map(t => (
          <span
            key={t.id}
            className="flex items-center gap-1 text-[11px] font-bold px-2.5 py-1 rounded-full"
            style={{
              background: `${t.color}18`,
              color: t.color,
              border: `1px solid ${t.color}35`,
            }}
          >
            {t.name}
            <button onClick={() => remove.mutate(t.id)} className="opacity-50 hover:opacity-100 transition-opacity">
              <X size={10} />
            </button>
          </span>
        ))}
        {tags.length === 0 && (
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>No tags yet.</span>
        )}
      </div>
      <div className="flex gap-1.5">
        <input
          value={name}
          onChange={e => setName(e.target.value)}
          onKeyDown={e => e.key === 'Enter' && name.trim() && add.mutate()}
          placeholder="add a tag…"
          className="flex-1 text-xs rounded-xl px-2.5 py-1.5 outline-none"
          style={INP}
        />
        <button
          disabled={!name.trim() || add.isPending}
          onClick={() => add.mutate()}
          className="flex items-center gap-1 text-xs font-bold px-3 rounded-xl disabled:opacity-40 transition-all hover:opacity-80"
          style={{ background: 'rgba(167,139,250,0.15)', color: '#a78bfa' }}
        >
          <Plus size={12} />
        </button>
      </div>
    </Section>
  )
}

/* ── Private Notes ───────────────────────────────────────── */
function NotesSection({ ticketId }) {
  const qc = useQueryClient()
  const key = ['ticket-notes', ticketId]
  const { data: notes = [] } = useQuery({ queryKey: key, queryFn: () => helpdeskApi.tickets.notes(ticketId) })
  const [text, setText] = useState('')
  const add = useMutation({
    mutationFn: () => helpdeskApi.tickets.addNote(ticketId, text),
    onSuccess: () => { setText(''); qc.invalidateQueries({ queryKey: key }) },
  })

  return (
    <Section icon={StickyNote} title="Private Notes" count={notes.length} accent="#d97706">
      <ul className="space-y-2 mb-3">
        {notes.map(n => (
          <li
            key={n.id}
            className="rounded-xl p-3 text-xs"
            style={{ background: 'rgba(217,119,6,0.07)', border: '1px solid rgba(217,119,6,0.18)' }}
          >
            <p className="leading-relaxed" style={{ color: 'var(--text-h)' }}>{n.content}</p>
            <div className="flex items-center gap-1.5 mt-1.5">
              <div
                className="w-4 h-4 rounded-full flex items-center justify-center text-[9px] font-bold"
                style={{ background: 'rgba(217,119,6,0.2)', color: '#d97706' }}
              >
                {initials(n.user?.name || 'S')}
              </div>
              <p style={{ color: '#b08a4a', fontSize: 10 }}>
                {n.user?.name} · {fmtWhen(n.created_at)}
              </p>
            </div>
          </li>
        ))}
        {notes.length === 0 && (
          <li className="text-xs py-1" style={{ color: 'var(--text-muted)' }}>
            No private notes. Only staff can see these.
          </li>
        )}
      </ul>
      <textarea
        value={text}
        onChange={e => setText(e.target.value)}
        rows={2}
        placeholder="Add a private note…"
        className="w-full text-xs rounded-xl px-2.5 py-2 outline-none resize-none"
        style={INP}
      />
      <button
        disabled={!text.trim() || add.isPending}
        onClick={() => add.mutate()}
        className="mt-2 w-full flex items-center justify-center gap-1.5 text-xs font-bold py-1.5 rounded-xl disabled:opacity-40 hover:opacity-80 transition-opacity"
        style={{ background: 'rgba(217,119,6,0.12)', color: '#d97706' }}
      >
        <Plus size={12} />
        Add Note
      </button>
    </Section>
  )
}

/* ── Reminders ───────────────────────────────────────────── */
function RemindersSection({ ticketId }) {
  const qc = useQueryClient()
  const key = ['ticket-reminders', ticketId]
  const { data: reminders = [] } = useQuery({ queryKey: key, queryFn: () => helpdeskApi.tickets.reminders(ticketId) })
  const [form, setForm] = useState({ remind_at: '', note: '' })
  const invalidate = () => qc.invalidateQueries({ queryKey: key })
  const add = useMutation({
    mutationFn: () => helpdeskApi.tickets.addReminder(ticketId, form),
    onSuccess: () => { setForm({ remind_at: '', note: '' }); invalidate() },
  })
  const toggle = useMutation({ mutationFn: (id) => helpdeskApi.tickets.reminderDone(id), onSuccess: invalidate })

  return (
    <Section icon={Bell} title="Reminders" count={reminders.length} accent="#0891b2">
      <ul className="space-y-2 mb-3">
        {reminders.map(r => (
          <li key={r.id} className="flex items-start gap-2 text-xs">
            <button
              onClick={() => toggle.mutate(r.id)}
              className="mt-0.5 shrink-0 w-4 h-4 rounded-md flex items-center justify-center border transition-all duration-150"
              style={{
                borderColor: r.is_done ? '#10b981' : 'var(--border)',
                background: r.is_done ? 'rgba(16,185,129,0.15)' : 'transparent',
              }}
            >
              {r.is_done && <Check size={10} style={{ color: '#10b981' }} />}
            </button>
            <div className="flex-1">
              <p
                style={{
                  color: 'var(--text-h)',
                  textDecoration: r.is_done ? 'line-through' : 'none',
                  opacity: r.is_done ? 0.5 : 1,
                }}
              >
                {r.note || 'Reminder'}
              </p>
              <p style={{ color: 'var(--text-muted)', fontSize: 10 }}>{fmtWhen(r.remind_at)}</p>
            </div>
          </li>
        ))}
        {reminders.length === 0 && (
          <li className="text-xs py-1" style={{ color: 'var(--text-muted)' }}>No reminders set.</li>
        )}
      </ul>
      <div className="space-y-1.5">
        <input
          type="datetime-local"
          value={form.remind_at}
          onChange={e => setForm({ ...form, remind_at: e.target.value })}
          className="w-full text-xs rounded-xl px-2.5 py-2 outline-none"
          style={INP}
        />
        <input
          value={form.note}
          onChange={e => setForm({ ...form, note: e.target.value })}
          placeholder="note (optional)"
          className="w-full text-xs rounded-xl px-2.5 py-2 outline-none"
          style={INP}
        />
        <button
          disabled={!form.remind_at || add.isPending}
          onClick={() => add.mutate()}
          className="w-full flex items-center justify-center gap-1.5 text-xs font-bold py-1.5 rounded-xl disabled:opacity-40 hover:opacity-80 transition-opacity"
          style={{ background: 'rgba(8,145,178,0.12)', color: '#22d3ee' }}
        >
          <Bell size={12} />
          Set Reminder
        </button>
      </div>
    </Section>
  )
}

/* ── Related Tickets ─────────────────────────────────────── */
function RelatedSection({ ticketId }) {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const key = ['ticket-related', ticketId]
  const { data: related = [] } = useQuery({ queryKey: key, queryFn: () => helpdeskApi.tickets.related(ticketId) })
  const [rid, setRid] = useState('')
  const invalidate = () => qc.invalidateQueries({ queryKey: key })
  const add = useMutation({
    mutationFn: () => helpdeskApi.tickets.addRelated(ticketId, Number(rid)),
    onSuccess: () => { setRid(''); invalidate() },
  })
  const remove = useMutation({
    mutationFn: (id) => helpdeskApi.tickets.removeRelated(ticketId, id),
    onSuccess: invalidate,
  })

  return (
    <Section icon={Link2} title="Related Tickets" count={related.length} accent="#22d3ee">
      <ul className="space-y-1.5 mb-3">
        {related.map(t => (
          <li key={t.id} className="flex items-center gap-2 text-xs group">
            <button
              onClick={() => navigate(`/app/helpdesk/tickets/${t.id}`)}
              className="flex-1 flex items-center gap-2 text-left hover:opacity-80 transition-opacity"
              style={{ color: 'var(--text-body)' }}
            >
              <span
                className="font-mono font-black text-[11px] px-1.5 py-0.5 rounded-lg"
                style={{ background: 'rgba(34,211,238,0.1)', color: '#22d3ee' }}
              >
                #{t.id}
              </span>
              <span className="truncate">{t.subject}</span>
            </button>
            <button
              onClick={() => remove.mutate(t.id)}
              className="opacity-30 hover:opacity-100 hover:text-red-400 transition-all"
              style={{ color: 'var(--text-muted)' }}
            >
              <X size={12} />
            </button>
          </li>
        ))}
        {related.length === 0 && (
          <li className="text-xs py-1" style={{ color: 'var(--text-muted)' }}>No linked tickets.</li>
        )}
      </ul>
      <div className="flex gap-1.5">
        <input
          value={rid}
          onChange={e => setRid(e.target.value.replace(/\D/g, ''))}
          placeholder="ticket id"
          className="flex-1 text-xs rounded-xl px-2.5 py-1.5 outline-none"
          style={INP}
        />
        <button
          disabled={!rid || add.isPending}
          onClick={() => add.mutate()}
          className="flex items-center gap-1 text-xs font-bold px-3 rounded-xl disabled:opacity-40 hover:opacity-80 transition-opacity"
          style={{ background: 'rgba(34,211,238,0.12)', color: '#22d3ee' }}
        >
          <Link2 size={12} />
          Link
        </button>
      </div>
      {add.isError && (
        <p className="text-[11px] mt-1" style={{ color: '#ef4444' }}>{add.error?.message}</p>
      )}
    </Section>
  )
}
