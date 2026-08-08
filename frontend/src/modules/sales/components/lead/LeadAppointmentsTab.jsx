import { useState, useEffect, useCallback } from 'react'
import { CalendarClock, Plus, Video, Phone, MapPin, Check, Trash2, X, AlertTriangle, Loader2 } from 'lucide-react'
import { appointmentApi } from '@/services/leadEngagementApi'
import { taskApi } from '@/services/taskApi'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import EmptyState from '@/components/ui/EmptyState'
import RichTextEditor from '@/components/ui/RichTextEditor'
import { richHtml } from '@/lib/richText'
import { useToast } from '@/hooks/useToast'

const MODE_ICON = { in_person: MapPin, phone: Phone, video: Video }
const MODE_LABEL = { in_person: 'In person', phone: 'Phone', video: 'Video call' }
const STATUS_STYLE = {
  scheduled: { label: 'Scheduled', color: '#3b82f6' },
  completed: { label: 'Completed', color: '#10b981' },
  cancelled: { label: 'Cancelled', color: '#94a3b8' },
  no_show:   { label: 'No show',   color: '#f59e0b' },
}
const REMINDERS = [
  { v: '', l: 'No reminder' }, { v: '15', l: '15 min before' }, { v: '30', l: '30 min before' },
  { v: '60', l: '1 hour before' }, { v: '1440', l: '1 day before' },
]
const BLANK = {
  title: '', description: '', starts_at: '', ends_at: '', location: '',
  mode: 'in_person', meeting_url: '', assigned_to: '', remind_before_minutes: '',
}
const fmtDT = s => s
  ? new Date(s).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
  : '—'

/**
 * Meetings scheduled against a lead.
 *
 * In the old CRM appointments were a paid third-party module bolted onto the lead
 * profile. Built in properly here, and closing one out requires an outcome — an
 * appointment marked done with no record of what happened is what makes these
 * histories useless a month later.
 */
export default function LeadAppointmentsTab({ leadId }) {
  const toast = useToast()
  const [rows, setRows] = useState(null)
  const [staff, setStaff] = useState([])
  const [adding, setAdding] = useState(false)
  const [form, setForm] = useState(BLANK)
  const [saving, setSaving] = useState(false)
  const [closing, setClosing] = useState(null)      // appointment being closed out
  const [outcome, setOutcome] = useState('')
  const [closeStatus, setCloseStatus] = useState('completed')
  const [confirmDel, setConfirmDel] = useState(null)
  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const load = useCallback(() => {
    appointmentApi.list('lead', leadId)
      .then(d => setRows(Array.isArray(d) ? d : (d?.data ?? [])))
      .catch(e => { toast.error(e.message); setRows([]) })
  }, [leadId])
  useEffect(() => { load() }, [load])
  useEffect(() => { taskApi.staff().then(setStaff).catch(() => setStaff([])) }, [])

  const save = async () => {
    if (!form.title.trim()) return toast.error('Give the appointment a title')
    if (!form.starts_at) return toast.error('Pick a start date and time')
    setSaving(true)
    try {
      await appointmentApi.create({
        subject_type: 'lead',
        subject_id: Number(leadId),
        title: form.title.trim(),
        description: form.description || null,
        // datetime-local gives "YYYY-MM-DDTHH:mm"; the API wants a space.
        starts_at: form.starts_at.replace('T', ' '),
        ends_at: form.ends_at ? form.ends_at.replace('T', ' ') : null,
        location: form.location || null,
        mode: form.mode,
        meeting_url: form.mode === 'video' ? (form.meeting_url || null) : null,
        assigned_to: form.assigned_to || null,
        remind_before_minutes: form.remind_before_minutes || null,
      })
      toast.success('Appointment scheduled')
      setForm(BLANK); setAdding(false); load()
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  const closeOut = async () => {
    if (!outcome.trim()) return toast.error('Add a short outcome')
    try {
      await appointmentApi.complete(closing.id, { outcome, status: closeStatus })
      toast.success('Appointment closed out')
      setClosing(null); setOutcome(''); setCloseStatus('completed'); load()
    } catch (e) { toast.error(e.message) }
  }

  const remove = async () => {
    try { await appointmentApi.remove(confirmDel.id); toast.success('Appointment deleted'); setConfirmDel(null); load() }
    catch (e) { toast.error(e.message) }
  }

  return (
    <div className="card-3d" style={{ padding: '20px' }}>
      <div className="flex items-center justify-between mb-4">
        <h3 className="font-bold text-sm flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
          <CalendarClock size={14} style={{ color: 'var(--accent)' }} /> Appointments
          {!!rows?.length && <span className="text-[11px] font-semibold" style={{ color: 'var(--text-muted)' }}>{rows.length}</span>}
        </h3>
        <button onClick={() => setAdding(a => !a)}
          className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold"
          style={{ background: 'rgba(124,58,237,0.1)', color: 'var(--accent)', border: '1px solid var(--border)' }}>
          <Plus size={12} /> Schedule
        </button>
      </div>

      {adding && (
        <div className="rounded-xl p-3 mb-4 space-y-2" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <input className="input-3d text-sm" placeholder="Title, e.g. Discovery call"
            value={form.title} onChange={e => sf('title', e.target.value)} />
          <div className="grid grid-cols-2 gap-2">
            <div>
              <label className="label">Starts</label>
              <input type="datetime-local" className="input-3d text-sm" value={form.starts_at} onChange={e => sf('starts_at', e.target.value)} />
            </div>
            <div>
              <label className="label">Ends (optional)</label>
              <input type="datetime-local" className="input-3d text-sm" value={form.ends_at} onChange={e => sf('ends_at', e.target.value)} />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <div>
              <label className="label">Mode</label>
              <select className="input-3d text-sm" value={form.mode} onChange={e => sf('mode', e.target.value)}>
                {Object.entries(MODE_LABEL).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
              </select>
            </div>
            <div>
              <label className="label">Reminder</label>
              <select className="input-3d text-sm" value={form.remind_before_minutes} onChange={e => sf('remind_before_minutes', e.target.value)}>
                {REMINDERS.map(r => <option key={r.v} value={r.v}>{r.l}</option>)}
              </select>
            </div>
          </div>
          {/* A meeting link only means anything for a video call. */}
          {form.mode === 'video' ? (
            <input className="input-3d text-sm" placeholder="https://meet.google.com/…"
              value={form.meeting_url} onChange={e => sf('meeting_url', e.target.value)} />
          ) : (
            <input className="input-3d text-sm" placeholder="Location (optional)"
              value={form.location} onChange={e => sf('location', e.target.value)} />
          )}
          <div>
            <label className="label">Assign to</label>
            <select className="input-3d text-sm" value={form.assigned_to} onChange={e => sf('assigned_to', e.target.value)}>
              <option value="">Unassigned</option>
              {staff.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
          </div>
          <div>
            <label className="label">Agenda / notes</label>
            <RichTextEditor value={form.description} onChange={v => sf('description', v)}
              placeholder="What needs covering…" minHeight={90} />
          </div>
          <div className="flex justify-end gap-2">
            <button onClick={() => { setAdding(false); setForm(BLANK) }}
              className="px-3 py-1.5 rounded-lg text-xs font-semibold"
              style={{ background: 'var(--bg-card)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
            <button onClick={save} disabled={saving}
              className="px-4 py-1.5 rounded-lg text-xs font-bold text-white inline-flex items-center gap-1.5 disabled:opacity-60"
              style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
              {saving && <Loader2 size={11} className="animate-spin" />} Schedule
            </button>
          </div>
        </div>
      )}

      {rows === null ? (
        <div className="skeleton h-20 rounded-xl" style={{ background: 'var(--border)' }} />
      ) : !rows.length ? (
        <EmptyState icon={CalendarClock} title="No appointments" description="Meetings scheduled with this lead will appear here." />
      ) : (
        <div className="space-y-2">
          {rows.map(a => {
            const Icon = MODE_ICON[a.mode] || MapPin
            const st = STATUS_STYLE[a.status] || STATUS_STYLE.scheduled
            return (
              <div key={a.id} className="rounded-xl p-3"
                style={{
                  border: '1px solid var(--border)', background: 'var(--bg-card)',
                  opacity: a.status === 'cancelled' ? 0.6 : 1,
                }}>
                <div className="flex items-start gap-2">
                  <div className="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0"
                    style={{ background: 'rgba(124,58,237,0.12)' }}>
                    <Icon size={13} style={{ color: '#a78bfa' }} />
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                      <p className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>{a.title}</p>
                      <span className="text-[10px] font-bold px-1.5 py-0.5 rounded-md"
                        style={{ background: `${st.color}1a`, color: st.color }}>{st.label}</span>
                      {/* A scheduled appointment whose time has passed needs closing out. */}
                      {a.is_past && (
                        <span className="inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded-md"
                          style={{ background: 'rgba(245,158,11,0.12)', color: '#f59e0b' }}>
                          <AlertTriangle size={9} /> needs closing out
                        </span>
                      )}
                    </div>
                    <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
                      {fmtDT(a.starts_at)}{a.ends_at ? ` – ${fmtDT(a.ends_at)}` : ''} · {MODE_LABEL[a.mode] || a.mode}
                      {a.assignee?.name ? ` · ${a.assignee.name}` : ''}
                      {a.remind_before_minutes ? ` · reminder ${a.remind_before_minutes}m before` : ''}
                    </p>
                    {a.location && <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>📍 {a.location}</p>}
                    {a.meeting_url && (
                      <a href={a.meeting_url} target="_blank" rel="noreferrer"
                        className="text-[11px] font-semibold" style={{ color: 'var(--accent)' }}>Join meeting →</a>
                    )}
                    {a.description && (
                      <div className="rich-content text-[11px] mt-1.5" style={{ color: 'var(--text-muted)' }}
                        dangerouslySetInnerHTML={richHtml(a.description)} />
                    )}
                    {a.outcome && (
                      <div className="rounded-lg px-2 py-1.5 mt-2" style={{ background: 'var(--bg-input)' }}>
                        <p className="label-caps mb-0.5">Outcome</p>
                        <div className="rich-content text-[11px]" style={{ color: 'var(--text-h)' }}
                          dangerouslySetInnerHTML={richHtml(a.outcome)} />
                      </div>
                    )}
                  </div>
                  <div className="flex items-center gap-1 flex-shrink-0">
                    {a.status === 'scheduled' && (
                      <button onClick={() => { setClosing(a); setOutcome(''); setCloseStatus('completed') }} title="Close out">
                        <Check size={14} style={{ color: '#10b981' }} />
                      </button>
                    )}
                    <button onClick={() => setConfirmDel(a)} title="Delete">
                      <Trash2 size={13} style={{ color: '#f87171' }} />
                    </button>
                  </div>
                </div>

                {closing?.id === a.id && (
                  <div className="mt-3 pt-3 space-y-2" style={{ borderTop: '1px solid var(--border)' }}>
                    <div className="flex items-center justify-between">
                      <p className="label-caps">How did it go?</p>
                      <button onClick={() => setClosing(null)}><X size={12} style={{ color: 'var(--text-muted)' }} /></button>
                    </div>
                    <select className="input-3d text-sm" value={closeStatus} onChange={e => setCloseStatus(e.target.value)}>
                      <option value="completed">Completed</option>
                      <option value="no_show">No show</option>
                      <option value="cancelled">Cancelled</option>
                    </select>
                    <RichTextEditor value={outcome} onChange={setOutcome}
                      placeholder="What was agreed, what happens next…" minHeight={80} />
                    <div className="flex justify-end">
                      <button onClick={closeOut}
                        className="px-4 py-1.5 rounded-lg text-xs font-bold text-white"
                        style={{ background: 'linear-gradient(135deg,#10b981,#059669)' }}>Save outcome</button>
                    </div>
                  </div>
                )}
              </div>
            )
          })}
        </div>
      )}

      {confirmDel && (
        <ConfirmDialog
          title="Delete this appointment?"
          message={`"${confirmDel.title}" will be removed.`}
          confirmLabel="Delete" tone="danger"
          onCancel={() => setConfirmDel(null)} onConfirm={remove}
        />
      )}
    </div>
  )
}
