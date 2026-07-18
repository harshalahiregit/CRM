import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  CalendarDays, Plus, RefreshCw, Clock, CheckCircle2, AlertTriangle,
  Users, MapPin, ArrowRight, Send, X,
} from 'lucide-react'
import { kickoffApi } from '@/services/kickoffApi'
import { tpvApi } from '@/services/tpvApi'
import {
  KO_STATUS, koStatusCfg, KO_MODES, fmtDateTime,
} from '../kickoffConstants'
import { KIT3D_STYLE, Overlay, ModalFooter, Field, TextInput, SelectInput } from '@/components/ui/kit3d'

/**
 * Kickoff meeting registry — the shared scheduling engine's list view.
 *
 * Lives under the TPV rail (its first consumer) but is not TPV-coupled: it reads
 * kickoffApi, which attaches to any allowlisted subject.
 */
export default function KickoffMeetings() {
  const navigate = useNavigate()
  const [data, setData]   = useState([])
  const [stats, setStats] = useState(null)
  const [loading, setLoad] = useState(true)
  const [filter, setFilter] = useState('All')
  const [showNew, setShowNew] = useState(false)

  const load = () => {
    setLoad(true)
    Promise.all([
      kickoffApi.list(filter === 'All' ? {} : { status: filter }),
      kickoffApi.stats(),
    ]).then(([rows, s]) => { setData(rows?.data ?? rows); setStats(s); setLoad(false) })
      .catch(() => setLoad(false))
  }
  useEffect(() => { load() }, [filter])

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>PRE-ONBOARDING</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Kickoff Meetings</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Schedule, track minutes, and capture vendor acknowledgement.</p>
        </div>
        <div style={{ display: 'flex', gap: 9 }}>
          <button onClick={load} style={ghostBtn}><RefreshCw size={14} /> Refresh</button>
          <button onClick={() => setShowNew(true)} style={solidBtn}><Plus size={15} /> Schedule meeting</button>
        </div>
      </div>

      {/* KPI strip */}
      {stats && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 14, marginBottom: 18 }}>
          <Kpi label="Scheduled" value={stats.scheduled} icon={CalendarDays} color="#0ea5e9" />
          <Kpi label="Delayed" value={stats.delayed} icon={Clock} color="#f59e0b" danger={stats.delayed > 0} />
          <Kpi label="Completed" value={stats.completed} icon={CheckCircle2} color="#10b981" />
          <Kpi label="Awaiting acknowledgement" value={stats.awaiting_ack} icon={Send} color="#a78bfa" danger={stats.awaiting_ack > 0} />
        </div>
      )}

      {/* Filter chips */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap' }}>
        {['All', KO_STATUS.SCHEDULED, KO_STATUS.DELAYED, KO_STATUS.COMPLETED, KO_STATUS.CANCELLED].map(f => {
          const on = filter === f
          return (
            <button key={f} onClick={() => setFilter(f)}
              style={{ padding: '6px 14px', borderRadius: 999, fontSize: 12.5, fontWeight: 700, cursor: 'pointer',
                background: on ? 'linear-gradient(145deg,#a78bfa,#7C3AED)' : 'var(--bg-card)',
                border: on ? 'none' : '1px solid var(--border)',
                color: on ? '#fff' : 'var(--text-muted)',
                boxShadow: on ? '0 6px 16px -6px rgba(124,58,237,.6)' : 'none' }}>
              {f}
            </button>
          )
        })}
      </div>

      {/* List */}
      {loading ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          {[1, 2, 3].map(i => <div key={i} className="skeleton" style={{ height: 92, borderRadius: 16, background: 'var(--border)' }} />)}
        </div>
      ) : data.length === 0 ? (
        <EmptyState onNew={() => setShowNew(true)} filter={filter} />
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          {data.map(m => <MeetingRow key={m.id} m={m} onClick={() => navigate(`/app/tpv/kickoff/${m.id}`)} />)}
        </div>
      )}

      {showNew && <ScheduleModal onClose={() => setShowNew(false)} onDone={(id) => { setShowNew(false); navigate(`/app/tpv/kickoff/${id}`) }} />}
    </div>
  )
}

function Kpi({ label, value, icon: Icon, color, danger }) {
  return (
    <div className="pr-kpi" style={{ padding: 16, outline: danger ? `1.5px solid ${color}66` : 'none' }}>
      <div style={{ width: 38, height: 38, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${color}1f` }}>
        <Icon size={18} style={{ color }} />
      </div>
      <div style={{ fontSize: 24, fontWeight: 900, color: 'var(--text-h)', marginTop: 11, lineHeight: 1 }}>{value ?? 0}</div>
      <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-muted)', marginTop: 4 }}>{label}</div>
    </div>
  )
}

function MeetingRow({ m, onClick }) {
  const cfg = koStatusCfg(m.status)
  const overdue = (m.status === 'Scheduled' || m.status === 'Delayed') && m.scheduled_at && new Date(m.scheduled_at) < new Date()

  return (
    <div className="pr-glass pr-lift" onClick={onClick} style={{ padding: 16, cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 16 }}>
      <div style={{ width: 46, height: 46, borderRadius: 14, flexShrink: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center',
        background: `${cfg.color}18`, border: `1px solid ${cfg.color}44` }}>
        <CalendarDays size={20} style={{ color: cfg.color }} />
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 3 }}>
          <span style={{ fontSize: 14.5, fontWeight: 800, color: 'var(--text-h)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{m.title}</span>
          {m.is_acknowledged && (
            <span title="Vendor has acknowledged the minutes" style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10.5, fontWeight: 700, color: '#10b981' }}>
              <CheckCircle2 size={12} /> Acknowledged
            </span>
          )}
        </div>
        <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap', fontSize: 11.5, color: 'var(--text-muted)' }}>
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><Clock size={12} /> {fmtDateTime(m.scheduled_at)}</span>
          {m.location && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><MapPin size={12} /> {m.location}</span>}
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><Users size={12} /> {m.attendees_count ?? 0} attendees</span>
          {m.subject?.name && <span>· {m.subject.name}</span>}
        </div>
      </div>
      {overdue && (
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '3px 9px', borderRadius: 999, fontSize: 10.5, fontWeight: 800, background: 'rgba(239,68,68,0.14)', color: '#ef4444' }}>
          <AlertTriangle size={11} /> Overdue
        </span>
      )}
      <span style={{ padding: '4px 11px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 11.5, fontWeight: 800, flexShrink: 0 }}>{cfg.label}</span>
      <ArrowRight size={16} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
    </div>
  )
}

function EmptyState({ onNew, filter }) {
  return (
    <div className="pr-glass" style={{ padding: '48px 24px', textAlign: 'center' }}>
      <div style={{ width: 60, height: 60, borderRadius: '50%', margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.12)' }}>
        <CalendarDays size={28} style={{ color: '#a78bfa' }} />
      </div>
      <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>
        {filter === 'All' ? 'No kickoff meetings yet' : `No ${filter.toLowerCase()} meetings`}
      </h3>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '6px 0 18px' }}>
        Schedule a pre-onboarding meeting with a vendor to get started.
      </p>
      {filter === 'All' && <button onClick={onNew} style={{ ...solidBtn, margin: '0 auto' }}><Plus size={15} /> Schedule meeting</button>}
    </div>
  )
}

/* ── Schedule modal ───────────────────────────────────────────────────────── */
function ScheduleModal({ onClose, onDone }) {
  const [vendors, setVendors] = useState([])
  const [form, setForm] = useState({ subject_id: '', title: '', scheduled_at: '', duration_minutes: 60, mode: 'onsite', location: '', agenda: '' })
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)

  useEffect(() => { tpvApi.vendors.list().then(r => setVendors(r?.data ?? r)).catch(() => {}) }, [])

  const set = (k) => (e) => setForm(f => ({ ...f, [k]: e.target.value }))

  const save = async () => {
    setSaving(true); setErr(null)
    try {
      const payload = {
        ...form,
        subject_type: form.subject_id ? 'vendor' : undefined,
        subject_id: form.subject_id || undefined,
        duration_minutes: Number(form.duration_minutes) || undefined,
      }
      const m = await kickoffApi.schedule(payload)
      onDone(m.id)
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not schedule the meeting.')
      setSaving(false)
    }
  }

  return (
    <Overlay onClose={onClose} width={540}>
      <div style={{ padding: '20px 22px 8px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>Schedule kickoff meeting</h2>
        <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>A pre-onboarding meeting with the vendor.</p>
      </div>
      <div style={{ padding: '10px 22px', display: 'flex', flexDirection: 'column', gap: 2 }}>
        <Field label="Vendor" full>
          <SelectInput value={form.subject_id} onChange={set('subject_id')} pairs
            options={[['', 'Select a vendor…'], ...vendors.map(v => [v.id, v.company_name])]} />
        </Field>
        <Field label="Title (optional — defaults to the vendor name)" full>
          <TextInput value={form.title} onChange={set('title')} placeholder="Kickoff — Acme Contractors" />
        </Field>
        <div style={{ display: 'grid', gridTemplateColumns: '1.4fr 1fr', gap: 12 }}>
          <Field label="Date & time"><TextInput type="datetime-local" value={form.scheduled_at} onChange={set('scheduled_at')} /></Field>
          <Field label="Duration (min)"><TextInput type="number" value={form.duration_minutes} onChange={set('duration_minutes')} /></Field>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.6fr', gap: 12 }}>
          <Field label="Mode"><SelectInput value={form.mode} onChange={set('mode')} options={KO_MODES} pairs /></Field>
          <Field label={form.mode === 'online' ? 'Meeting link' : 'Location'}>
            <TextInput value={form.location} onChange={set('location')} placeholder={form.mode === 'online' ? 'https://…' : 'Site office, Gate 1'} />
          </Field>
        </div>
        <Field label="Agenda" full>
          <TextInput value={form.agenda} onChange={set('agenda')} placeholder="HSSE induction, scope walk, document checklist" />
        </Field>
        {err && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 12px', borderRadius: 10, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)', marginTop: 6 }}>
            <AlertTriangle size={14} style={{ color: '#ef4444', flexShrink: 0 }} />
            <span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{err}</span>
          </div>
        )}
      </div>
      <ModalFooter onClose={onClose} onConfirm={save} loading={saving} confirmLabel="Schedule" color="#7C3AED" />
    </Overlay>
  )
}

const solidBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 10, cursor: 'pointer',
  fontSize: 13, fontWeight: 700, color: '#fff', border: 'none',
  background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)',
}
const ghostBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, cursor: 'pointer',
  fontSize: 13, fontWeight: 600, color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)',
}
