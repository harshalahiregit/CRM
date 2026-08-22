import { useState, useEffect, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Receipt, CreditCard, FileX, IndianRupee, FileText, ClipboardList, FileSignature,
  RefreshCw, ShoppingCart, Truck, Package, Send, FolderKanban, CheckSquare,
  Activity as ActivityIcon, CalendarDays, StickyNote, LifeBuoy, AlertOctagon,
  Star, Paperclip, Globe, Bell, Circle, Plus, X, Edit2, ArrowUpRight, Lock,
} from 'lucide-react'
import { customerApi } from '@/services/customerApi'
import { useToast } from '@/hooks/useToast'
import ConfirmIconButton from './ConfirmIconButton'
import RichTextEditor from '@/components/ui/RichTextEditor'
import { ACTIVITIES } from './recordSchemas'

/**
 * §4 — Activities, as a register of everything attached to this customer.
 *
 * The document lists eight things a user can log by hand (Call, Email,
 * WhatsApp, Visit, Meeting, Follow-up, Note, Escalation). In practice people
 * open this tab to ask "what has happened with this customer", and a list
 * containing only what somebody remembered to type is a poor answer to that —
 * a proposal was sent, an invoice was raised, a ticket was opened, and none of
 * it appeared here.
 *
 * So this shows every record attached to the customer, from every module.
 * Manually logged activities stay editable; everything else is read-only and
 * links to the screen that owns it, which is §6 applied to rows instead of
 * tabs. The alternative — copying other modules' records into this table —
 * would be the duplication the document warns against.
 */
const ICON = {
  invoice: Receipt, payment: CreditCard, credit_note: FileX, expense: IndianRupee,
  estimate: ClipboardList, proposal: FileText, contract: FileSignature,
  subscription: RefreshCw, purchase_order: ShoppingCart,
  shipment: Truck, package: Package, pre_alert: Send, delivery_note: Truck,
  project: FolderKanban, task: CheckSquare,
  activity: ActivityIcon, meeting: CalendarDays, note: StickyNote,
  ticket: LifeBuoy, complaint: AlertOctagon, feedback: Star,
  file: Paperclip, domain: Globe, reminder: Bell,
}

const CATEGORY_COLOR = {
  finance: '#0d9488', commercial: '#8b5cf6', operations: '#2563eb',
  relationship: '#0ea5e9', service: '#f97316', admin: '#64748b', other: '#6b7280',
}

const LABEL = (t) => t.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())

const RANGES = [
  { key: '30',  label: 'Last 30 days', days: 30 },
  { key: '90',  label: 'Last 90 days', days: 90 },
  { key: '365', label: 'Last year',    days: 365 },
  { key: 'all', label: 'All time',     days: null },
]

const when = (iso) => {
  const d = new Date(iso)
  return isNaN(d) ? '—' : d.toLocaleString('en-IN', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}

const nowLocal = () => {
  const d = new Date()
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
  return d.toISOString().slice(0, 16)
}

const blank = () => ({
  type: 'Call', direction: '', subject: '', occurred_at: nowLocal(),
  client_contact_id: '', outcome: '', duration_minutes: '',
  follow_up_on: '', follow_up_done: false, summary: '',
})

export default function ActivitiesTab({ id, contacts = [] }) {
  const toast = useToast()
  const navigate = useNavigate()
  const [data, setData] = useState(null)
  const [range, setRange] = useState('90')
  const [active, setActive] = useState([])       // selected type chips
  const [editing, setEditing] = useState(null)   // null | {} | row
  const [form, setForm] = useState(blank)
  const [saving, setSaving] = useState(false)

  const load = () => {
    const days = RANGES.find((r) => r.key === range)?.days
    const params = {}
    if (days) {
      const d = new Date(); d.setDate(d.getDate() - days)
      params.from = d.toISOString().slice(0, 10)
    }
    if (active.length) params.types = active.join(',')
    return customerApi.activityFeed(id, params).then(setData)
      .catch((e) => toast.error(e.message))
  }

  useEffect(() => { setData(null); load() }, [id, range, active]) // eslint-disable-line

  const chips = useMemo(
    () => Object.entries(data?.counts ?? {}).sort((a, b) => b[1] - a[1]),
    [data?.counts],
  )

  const toggle = (t) => setActive((p) => (p.includes(t) ? p.filter((x) => x !== t) : [...p, t]))
  const sf = (k, v) => setForm((p) => ({ ...p, [k]: v }))

  const openNew = () => { setForm(blank()); setEditing({}) }

  const openEdit = async (row) => {
    // The feed carries only what a row needs to render, so the full record is
    // fetched before editing rather than opening a form seeded with blanks.
    try {
      const rows = await customerApi.activities.list(id)
      const full = (Array.isArray(rows) ? rows : []).find((r) => r.id === row.id)
      if (!full) return toast.error('That activity no longer exists.')
      setForm({
        type: full.type || 'Call',
        direction: full.direction || '',
        subject: full.subject || '',
        occurred_at: String(full.occurred_at || '').replace(' ', 'T').slice(0, 16),
        client_contact_id: full.client_contact_id ?? '',
        outcome: full.outcome || '',
        duration_minutes: full.duration_minutes ?? '',
        follow_up_on: full.follow_up_on ? String(full.follow_up_on).slice(0, 10) : '',
        follow_up_done: !!full.follow_up_done,
        summary: full.summary || '',
      })
      setEditing(full)
    } catch (e) { toast.error(e.message) }
  }

  const save = async () => {
    if (!form.subject.trim()) return toast.error('Subject is required')
    if (!form.occurred_at) return toast.error('When is required')
    setSaving(true)
    try {
      const payload = { ...form, client_contact_id: form.client_contact_id || null }
      if (payload.duration_minutes === '') delete payload.duration_minutes
      if (payload.follow_up_on === '') delete payload.follow_up_on
      if (payload.direction === '') delete payload.direction
      if (payload.outcome === '') delete payload.outcome
      if (editing?.id) { await customerApi.activities.update(id, editing.id, payload); toast.success('Activity updated') }
      else { await customerApi.activities.create(id, payload); toast.success('Activity logged') }
      setEditing(null); load()
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  const del = async (row) => {
    try { await customerApi.activities.remove(id, row.id); toast.success('Deleted'); load() }
    catch (e) { toast.error(e.message) }
  }

  const rows = data?.rows ?? null

  return (
    <div className="space-y-4">
      {/* ── range + type filters ── */}
      <div className="card-3d" style={{ padding: 16 }}>
        <div className="flex flex-wrap items-center gap-2 mb-3">
          {RANGES.map((r) => (
            <button key={r.key} onClick={() => setRange(r.key)}
              className="px-3 py-1.5 rounded-lg text-[11px] font-bold"
              style={{
                background: range === r.key ? 'rgba(124,58,237,0.16)' : 'var(--bg-input)',
                color: range === r.key ? 'var(--accent)' : 'var(--text-muted)',
                border: `1px solid ${range === r.key ? 'rgba(124,58,237,0.4)' : 'var(--border)'}`,
              }}>{r.label}</button>
          ))}
          <div className="ml-auto flex items-center gap-2">
            {active.length > 0 && (
              <button onClick={() => setActive([])} className="text-[11px] font-bold" style={{ color: 'var(--accent)' }}>
                Clear {active.length} filter{active.length > 1 ? 's' : ''}
              </button>
            )}
            {!editing && (
              <button onClick={openNew} className="flex items-center gap-1 px-4 py-2 rounded-xl text-xs font-bold text-white"
                style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
                <Plus size={13} /> Log Activity
              </button>
            )}
          </div>
        </div>
        <div className="flex flex-wrap gap-1.5">
          {chips.length === 0 && (
            <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Nothing recorded in this period.</span>
          )}
          {chips.map(([type, n]) => {
            const on = active.includes(type)
            const Icon = ICON[type] || Circle
            return (
              <button key={type} onClick={() => toggle(type)}
                className="flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold"
                style={{
                  background: on ? 'rgba(124,58,237,0.16)' : 'var(--bg-input)',
                  color: on ? 'var(--accent)' : 'var(--text-muted)',
                  border: `1px solid ${on ? 'rgba(124,58,237,0.4)' : 'var(--border)'}`,
                }}>
                <Icon size={12} /> {LABEL(type)} <span style={{ opacity: 0.65 }}>{n}</span>
              </button>
            )
          })}
        </div>
      </div>

      {/* ── log / edit form ── */}
      {editing && (
        <div className="card-3d" style={{ padding: 18 }}>
          <div className="flex items-center justify-between mb-4">
            <p className="label-caps" style={{ color: 'var(--accent)' }}>
              {editing.id ? 'Edit Activity' : 'Log Activity'}
            </p>
            <button onClick={() => setEditing(null)} className="p-1 rounded-lg hover:bg-[rgba(239,68,68,0.08)]">
              <X size={15} style={{ color: 'var(--text-muted)' }} />
            </button>
          </div>
          <div className="grid md:grid-cols-2 gap-4">
            <div>
              <label className="label">Type *</label>
              <select className="input-3d text-sm" value={form.type} onChange={(e) => sf('type', e.target.value)}>
                {ACTIVITIES.fields.find((f) => f.key === 'type').options.map((o) => <option key={o} value={o}>{o}</option>)}
              </select>
            </div>
            <div>
              <label className="label">Direction</label>
              <select className="input-3d text-sm" value={form.direction} onChange={(e) => sf('direction', e.target.value)}>
                <option value="">—</option><option value="Inbound">Inbound</option><option value="Outbound">Outbound</option>
              </select>
            </div>
            <div><label className="label">Subject *</label>
              <input className="input-3d text-sm" value={form.subject} onChange={(e) => sf('subject', e.target.value)} /></div>
            <div><label className="label">When *</label>
              <input type="datetime-local" className="input-3d text-sm" value={form.occurred_at} onChange={(e) => sf('occurred_at', e.target.value)} /></div>
            <div>
              <label className="label">Contact</label>
              <select className="input-3d text-sm" value={form.client_contact_id} onChange={(e) => sf('client_contact_id', e.target.value)}>
                <option value="">Whole company</option>
                {contacts.map((c) => (
                  <option key={c.id} value={c.id}>{[c.first_name, c.last_name].filter(Boolean).join(' ') || c.email}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="label">Outcome</label>
              <select className="input-3d text-sm" value={form.outcome} onChange={(e) => sf('outcome', e.target.value)}>
                <option value="">—</option>
                {ACTIVITIES.fields.find((f) => f.key === 'outcome').options.map((o) => <option key={o} value={o}>{o}</option>)}
              </select>
            </div>
            <div><label className="label">Duration (minutes)</label>
              <input type="number" className="input-3d text-sm" value={form.duration_minutes} onChange={(e) => sf('duration_minutes', e.target.value)} /></div>
            <div>
              <label className="label">Follow up on</label>
              <input type="date" className="input-3d text-sm" value={form.follow_up_on} onChange={(e) => sf('follow_up_on', e.target.value)} />
              <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
                Overdue follow-ups count against Customer Health.
              </p>
            </div>
            <div className="md:col-span-2">
              <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-muted)' }}>
                <input type="checkbox" checked={form.follow_up_done} onChange={(e) => sf('follow_up_done', e.target.checked)} /> Follow-up done
              </label>
            </div>
            <div className="md:col-span-2">
              <label className="label">Summary</label>
              <RichTextEditor value={form.summary} onChange={(v) => sf('summary', v)} placeholder="What happened…" minHeight={120} />
            </div>
          </div>
          <div className="flex justify-end gap-2 mt-4">
            <button onClick={() => setEditing(null)} className="px-4 py-2 rounded-xl text-sm font-semibold"
              style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
            <button onClick={save} disabled={saving} className="px-5 py-2 rounded-xl text-sm font-bold text-white disabled:opacity-60"
              style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>{saving ? 'Saving…' : 'Save'}</button>
          </div>
        </div>
      )}

      {/* ── the register ── */}
      <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead><tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
              {['When', 'Type', 'What happened', 'Detail', ''].map((h, i) => (
                <th key={i} className="py-3 px-4 text-left label-caps whitespace-nowrap">{h}</th>
              ))}
            </tr></thead>
            <tbody>
              {rows === null ? (
                <tr><td colSpan={5} className="p-3"><div className="skeleton h-8 rounded-lg" style={{ background: 'var(--border)' }} /></td></tr>
              ) : rows.length === 0 ? (
                <tr><td colSpan={5} className="py-10 text-center" style={{ color: 'var(--text-muted)' }}>
                  Nothing recorded for this customer in this period.
                </td></tr>
              ) : rows.map((r, i) => {
                const Icon = ICON[r.type] || Circle
                const colour = CATEGORY_COLOR[r.category] || CATEGORY_COLOR.other
                return (
                  <tr key={`${r.type}-${r.id ?? i}-${r.at}`} style={{ borderBottom: '1px solid var(--border)' }}>
                    <td className="py-3 px-4 whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{when(r.at)}</td>
                    <td className="py-3 px-4 whitespace-nowrap">
                      <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-lg text-[11px] font-bold"
                        style={{ background: `${colour}1a`, color: colour, border: `1px solid ${colour}55` }}>
                        <Icon size={11} /> {r.kind || LABEL(r.type)}
                      </span>
                    </td>
                    <td className="py-3 px-4" style={{ color: 'var(--text-h)', fontWeight: 600 }}>{r.label}</td>
                    <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{r.detail || '—'}</td>
                    <td className="py-3 px-4">
                      <div className="flex gap-1 justify-end items-center">
                        {r.editable ? (
                          <>
                            <button onClick={() => openEdit(r)} className="p-1.5 rounded-lg hover:bg-[rgba(124,58,237,0.08)]" title="Edit">
                              <Edit2 size={13} style={{ color: 'var(--text-muted)' }} />
                            </button>
                            <ConfirmIconButton onConfirm={() => del(r)} title="Delete?" message="This activity will be permanently removed." />
                          </>
                        ) : r.link ? (
                          <button onClick={() => navigate(r.link)} className="flex items-center gap-1 text-[11px] font-bold" style={{ color: 'var(--accent)' }}>
                            Open <ArrowUpRight size={12} />
                          </button>
                        ) : (
                          <span title="Owned by another module — edit it there" style={{ color: 'var(--text-faint,#6b7280)' }}>
                            <Lock size={12} />
                          </span>
                        )}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
        {data && data.total > data.showing && (
          <p className="text-[11px] text-center py-2" style={{ color: 'var(--text-muted)' }}>
            Showing the most recent {data.showing} of {data.total}.
          </p>
        )}
      </div>
    </div>
  )
}
