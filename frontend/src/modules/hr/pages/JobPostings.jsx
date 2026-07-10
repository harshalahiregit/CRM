import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Plus, RefreshCw, Search, Eye, Pencil, Users, Rocket, Pause, Play, Copy, Lock,
  Briefcase, MapPin, Building2, FolderKanban, Calendar, CheckCircle2, Send, Clock,
  ClipboardList, Layers, XCircle, User, ShieldCheck, Activity, LayoutGrid, List, Globe,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { useAuth } from '@/context/AuthContext'
import JobListView from './JobListView'
import {
  JOB_STATUS, JOB_STATUS_CONFIG, JOB_STATUSES, jobStatusColor, jobStatusLabel,
  JOB_TYPE_COLORS, PRIORITY_COLORS, canManageHrQueue,
} from '../constants'

const DEPARTMENTS = ['Engineering', 'Sales', 'HR', 'Operations', 'Finance', 'Product', 'Marketing']
const SOURCES = ['LinkedIn', 'Naukri', 'Career Page', 'Internal Portal', 'Employee Referral']
const EMPLOYMENT_TYPES = ['Full-time', 'Part-time', 'Contract', 'Internship', 'Remote']
const EMPTY = {
  title: '', department: '', location: '', job_type: 'Full-time', posting_type: 'Both',
  description: '', requirements: '', salary_from: '', salary_to: '',
  number_of_openings: 1, closing_date: '', status: 'Draft', sources: [],
}
const labelStyle = { display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 6, textTransform: 'uppercase', letterSpacing: '0.05em' }
const inputStyle = { width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const fmtSalary = (f, t) => (!f && !t) ? null : `₹${f ? (f / 100000).toFixed(1) : '0'}–${t ? (t / 100000).toFixed(1) : '0'}L`
const timeAgo = (iso) => {
  if (!iso) return null
  const s = (Date.now() - new Date(iso).getTime()) / 1000
  if (s < 60) return 'just now'
  if (s < 3600) return `${Math.floor(s / 60)}m ago`
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`
  if (s < 2592000) return `${Math.floor(s / 86400)}d ago`
  return new Date(iso).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' })
}

// L1/L2 approval badge — indicates the job came from an approved manpower request
function ApprovalBadge({ level, name }) {
  const ok = !!name
  const color = ok ? '#10b981' : 'var(--text-muted)'
  return (
    <span title={ok ? `${level} approved by ${name}` : `${level} not approved`}
      style={{ display: 'inline-flex', alignItems: 'center', gap: 3, padding: '1px 7px', borderRadius: 8, fontSize: 10, fontWeight: 700, background: ok ? 'rgba(16,185,129,0.12)' : 'var(--bg-input)', color, border: `1px solid ${ok ? 'rgba(16,185,129,0.3)' : 'var(--border)'}` }}>
      <ShieldCheck size={10} /> {level}{ok ? ' ✓' : ''}
    </span>
  )
}

// ── Status badge ─────────────────────────────────────────────────────────────
function StatusBadge({ status }) {
  const c = jobStatusColor(status)
  return <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '3px 10px', borderRadius: 20, fontSize: 11, fontWeight: 700, background: c.bg, color: c.color, border: `1px solid ${c.color}40` }}>{jobStatusLabel(status)}</span>
}

// ── Main page ────────────────────────────────────────────────────────────────
export default function JobPostings() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const manageHr = canManageHrQueue(user)

  const [jobs, setJobs]       = useState([])
  const [stats, setStats]     = useState({})
  const [loading, setLoading] = useState(true)
  const [search, setSearch]   = useState('')
  const [filter, setFilter]   = useState('All')
  const [busy, setBusy]       = useState(false)
  const [view, setView]       = useState(() => localStorage.getItem('hr_jobs_view') || 'card')
  const changeView = (v) => { setView(v); localStorage.setItem('hr_jobs_view', v) }

  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [form, setForm]         = useState(EMPTY)
  const [closeModal, setCloseModal] = useState(null) // { job, action }
  const [remarks, setRemarks]   = useState('')

  const fetchAll = useCallback(async () => {
    setLoading(true)
    try {
      const [list, s] = await Promise.all([hrApi.jobs.list(), hrApi.jobs.stats()])
      setJobs(Array.isArray(list) ? list : [])
      setStats(s || {})
    } catch (e) { console.error('Failed to load jobs', e) }
    finally { setLoading(false) }
  }, [])
  useEffect(() => { fetchAll() }, [fetchAll])

  const filtered = jobs.filter(j => {
    const q = search.toLowerCase()
    const mq = !q || j.title?.toLowerCase().includes(q) || j.department?.toLowerCase().includes(q) || j.manpower_request?.project?.toLowerCase().includes(q)
    const ms = filter === 'All' || j.status === filter
    return mq && ms
  })

  // ── Create / edit ──────────────────────────────────────────────────────────
  const openCreate = () => { setEditingId(null); setForm(EMPTY); setShowForm(true) }
  const openEdit = (j) => {
    setEditingId(j.id)
    setForm({ ...EMPTY, ...j, salary_from: j.salary_from ?? '', salary_to: j.salary_to ?? '', closing_date: j.closing_date?.slice(0, 10) || '', sources: j.sources || [] })
    setShowForm(true)
  }
  const saveJob = async () => {
    if (!form.title || !form.department || !form.location) { alert('Title, Department and Location are required'); return }
    setBusy(true)
    try {
      const payload = { ...form, number_of_openings: Number(form.number_of_openings) || 1, salary_from: form.salary_from || null, salary_to: form.salary_to || null }
      if (editingId) await hrApi.jobs.update(editingId, payload)
      else await hrApi.jobs.create(payload)
      setShowForm(false); fetchAll()
    } catch (e) { alert(e?.response?.data?.message || 'Failed to save job') }
    finally { setBusy(false) }
  }

  // ── Lifecycle actions ──────────────────────────────────────────────────────
  const runAction = async (job, action) => {
    setBusy(true)
    try {
      if (action === 'publish')        await hrApi.jobs.publish(job.id)
      if (action === 'unpublish')      await hrApi.jobs.unpublish(job.id)
      if (action === 'pause')          await hrApi.jobs.pause(job.id)
      if (action === 'duplicate')      await hrApi.jobs.duplicate(job.id)
      if (action === 'publish-portal') await hrApi.jobs.publishTo(job.id, 'careers')
      if (action === 'remove-portal')  await hrApi.jobs.unpublishFrom(job.id, 'careers')
      fetchAll()
    } catch (e) { alert(e?.response?.data?.message || 'Action failed') }
    finally { setBusy(false) }
  }
  const runBulk = async (action, ids) => {
    if (!ids.length) return
    setBusy(true)
    try {
      const r = await hrApi.jobs.bulk(action, ids)
      await fetchAll()
      if (r?.skipped) alert(`${r.success} updated, ${r.skipped} skipped (action not applicable to their status).`)
    } catch (e) { alert(e?.response?.data?.message || 'Bulk action failed') }
    finally { setBusy(false) }
  }

  const runClose = async () => {
    const { job, action } = closeModal
    setBusy(true)
    try {
      if (action === 'close') await hrApi.jobs.close(job.id, remarks)
      if (action === 'cancel') await hrApi.jobs.cancel(job.id, remarks)
      setCloseModal(null); setRemarks(''); fetchAll()
    } catch (e) { alert(e?.response?.data?.message || 'Action failed') }
    finally { setBusy(false) }
  }

  const kpis = [
    { label: 'Approved Requests',   value: stats.approved_requests,   icon: ClipboardList, color: '#7C3AED' },
    { label: 'Pending Publication', value: stats.pending_publication, icon: Clock,         color: '#f59e0b' },
    { label: 'Published Jobs',      value: stats.published_jobs,      icon: Rocket,        color: '#10b981' },
    { label: 'Active Jobs',         value: stats.active_jobs,         icon: Briefcase,     color: '#14b8a6' },
    { label: 'Filled Positions',    value: stats.filled_positions,    icon: CheckCircle2,  color: '#22c55e' },
    { label: 'Remaining Positions', value: stats.remaining_positions, icon: Layers,        color: '#0ea5e9' },
  ]

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 18, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>Job Postings</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '4px 0 0' }}>Recruitment Workspace — Manpower Request → Job → Candidates → Interviews → Offers → Onboarding</p>
        </div>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
          {/* Card / List view toggle (remembered across visits) */}
          <div style={{ display: 'flex', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 10, padding: 2 }}>
            {[['card', LayoutGrid, 'Card'], ['list', List, 'List']].map(([v, Icon, label]) => (
              <button key={v} onClick={() => changeView(v)} title={`${label} view`}
                style={{ display: 'flex', alignItems: 'center', gap: 5, padding: '6px 12px', borderRadius: 8, border: 'none', cursor: 'pointer', fontSize: 12.5, fontWeight: 600,
                  background: view === v ? 'var(--bg-card)' : 'transparent', color: view === v ? '#a78bfa' : 'var(--text-muted)', boxShadow: view === v ? 'var(--shadow-card)' : 'none' }}>
                <Icon size={14} /> {label}
              </button>
            ))}
          </div>
          <button onClick={fetchAll} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}><RefreshCw size={14} /> Refresh</button>
          {manageHr && <button onClick={openCreate} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 18px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }}><Plus size={15} /> Post New Job</button>}
        </div>
      </div>

      {/* Dashboard KPI row */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 12, marginBottom: 20 }}>
        {kpis.map(k => (
          <div key={k.label} className="kpi-3d" style={{ padding: 14 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
              <span style={{ width: 28, height: 28, borderRadius: 8, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${k.color}20` }}><k.icon size={15} style={{ color: k.color }} /></span>
            </div>
            <div style={{ fontSize: 22, fontWeight: 900, color: 'var(--text-h)' }}>{k.value ?? 0}</div>
            <div style={{ fontSize: 11, fontWeight: 600, color: 'var(--text-muted)' }}>{k.label}</div>
          </div>
        ))}
      </div>

      {/* Filters */}
      <div className="card-3d" style={{ padding: '14px 18px', marginBottom: 18, display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap' }}>
        <div style={{ position: 'relative', flex: 1, minWidth: 220 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search title, department or project..." style={{ ...inputStyle, paddingLeft: 32 }} />
        </div>
        <select value={filter} onChange={e => setFilter(e.target.value)} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="All">All Status</option>
          {JOB_STATUSES.map(s => <option key={s} value={s}>{JOB_STATUS_CONFIG[s]?.label || s}</option>)}
        </select>
      </div>

      {/* Card view / List view (shared filtered data → always in sync) */}
      {loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>Loading...</div>
      ) : view === 'list' ? (
        <JobListView jobs={filtered} manageHr={manageHr} busy={busy} navigate={navigate}
          onEdit={openEdit} onAction={runAction}
          onClose={(job, action) => { setCloseModal({ job, action }); setRemarks('') }}
          onBulk={runBulk} />
      ) : filtered.length === 0 ? (
        <div className="card-3d" style={{ padding: 40, textAlign: 'center', color: 'var(--text-muted)' }}><Briefcase size={40} style={{ marginBottom: 12, opacity: 0.4 }} /><p>No job postings found</p></div>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(360px,1fr))', gap: 16 }}>
          {filtered.map(job => (
            <JobCard key={job.id} job={job} manageHr={manageHr} busy={busy}
              onView={() => navigate(`/app/hr/jobs/${job.id}`)} onEdit={() => openEdit(job)}
              onCandidates={() => navigate('/app/hr/candidates')}
              onAction={(a) => runAction(job, a)}
              onClose={(a) => { setCloseModal({ job, action: a }); setRemarks('') }} />
          ))}
        </div>
      )}

      {showForm && <JobFormModal {...{ form, setForm, editingId, busy, onClose: () => setShowForm(false), onSave: saveJob }} />}
      {closeModal && <CloseModal {...{ closeModal, remarks, setRemarks, busy, onClose: () => setCloseModal(null), onConfirm: runClose }} />}
    </div>
  )
}

// ── Job card ─────────────────────────────────────────────────────────────────
function JobCard({ job, manageHr, busy, onView, onEdit, onCandidates, onAction, onClose }) {
  const mr = job.manpower_request || {}
  const p = job.progress || {}
  const live = ['Published', 'Hiring', 'Partially_Filled'].includes(job.status)
  const pct = p.required ? Math.min(100, Math.round((p.filled / p.required) * 100)) : 0
  const ICONS = { Building2, FolderKanban, MapPin }
  const meta = [['Building2', mr.business_unit], ['FolderKanban', mr.project], ['MapPin', job.location]]
  return (
    <div className="card-3d" style={{ padding: 18, display: 'flex', flexDirection: 'column', gap: 12 }}>
      {/* header */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 10 }}>
        <div style={{ minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
            <span style={{ color: 'var(--text-h)', fontWeight: 700, fontSize: 15 }}>{job.title}</span>
            {mr.priority && <span style={{ padding: '1px 7px', borderRadius: 8, fontSize: 10, fontWeight: 700, background: `${PRIORITY_COLORS[mr.priority]}20`, color: PRIORITY_COLORS[mr.priority] }}>{mr.priority}</span>}
          </div>
          <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>{job.department}</div>
        </div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 5, alignItems: 'flex-end' }}>
          <StatusBadge status={job.status} />
          {job.on_career_portal && <span title="Live on the public Career Portal" style={{ display: 'inline-flex', alignItems: 'center', gap: 3, padding: '1px 7px', borderRadius: 8, fontSize: 10, fontWeight: 700, background: 'rgba(14,165,233,0.12)', color: '#0ea5e9', border: '1px solid rgba(14,165,233,0.3)' }}><Globe size={10} /> Portal</span>}
        </div>
      </div>

      {/* request source: requested by + approval status (job originated from an approved request) */}
      {mr.id && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', fontSize: 11.5, color: 'var(--text-muted)' }}>
          {mr.requester?.name && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><User size={12} /> {mr.requester.name}</span>}
          {mr.l1_approver?.name && mr.l2_approver?.name
            ? <span title={`L1: ${mr.l1_approver.name} · L2: ${mr.l2_approver.name}`} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '1px 8px', borderRadius: 8, fontSize: 10, fontWeight: 700, background: 'rgba(16,185,129,0.12)', color: '#10b981', border: '1px solid rgba(16,185,129,0.3)' }}><ShieldCheck size={11} /> Approved</span>
            : <><ApprovalBadge level="L1" name={mr.l1_approver?.name} /><ApprovalBadge level="L2" name={mr.l2_approver?.name} /></>}
        </div>
      )}

      {/* chips */}
      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
        <span style={{ padding: '2px 8px', borderRadius: 8, fontSize: 11, fontWeight: 600, background: `${JOB_TYPE_COLORS[job.job_type] || '#7C3AED'}18`, color: JOB_TYPE_COLORS[job.job_type] || '#7C3AED' }}>{job.job_type}</span>
        <span style={{ padding: '2px 8px', borderRadius: 8, fontSize: 11, background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}>{job.posting_type}</span>
        {fmtSalary(job.salary_from, job.salary_to) && <span style={{ padding: '2px 8px', borderRadius: 8, fontSize: 11, color: 'var(--text-muted)', border: '1px solid var(--border)' }}>{fmtSalary(job.salary_from, job.salary_to)}</span>}
      </div>

      {/* meta */}
      <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap', color: 'var(--text-muted)', fontSize: 12 }}>
        {meta.filter(([, v]) => v).map(([ic, v]) => { const I = ICONS[ic]; return <span key={ic} style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><I size={12} /> {v}</span> })}
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><Calendar size={12} /> Closes {fmtDate(job.closing_date)}</span>
        {job.published_at && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><Send size={12} /> Pub {fmtDate(job.published_at)}</span>}
      </div>

      {/* progress */}
      <div>
        <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, color: 'var(--text-muted)', marginBottom: 4 }}>
          <span>Hiring Progress · <strong style={{ color: 'var(--text-h)' }}>{p.hiring_pct ?? pct}%</strong></span>
          <span>Filled {p.filled ?? 0}/{p.required ?? job.number_of_openings} · {p.remaining ?? job.number_of_openings} left</span>
        </div>
        <div style={{ height: 7, borderRadius: 6, background: 'var(--bg-input)', overflow: 'hidden' }}>
          <div style={{ width: `${p.hiring_pct ?? pct}%`, height: '100%', borderRadius: 6, background: 'linear-gradient(90deg,#10b981,#22c55e)' }} />
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(5,1fr)', gap: 6, marginTop: 10 }}>
          {[['Apps', p.applications], ['Short', p.shortlisted], ['Intv', p.interviews], ['Offers', p.offers], ['Joined', p.joined]].map(([l, v]) => (
            <div key={l} style={{ textAlign: 'center', padding: '5px 2px', background: 'var(--bg-input)', borderRadius: 7 }}>
              <div style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>{v ?? 0}</div>
              <div style={{ fontSize: 9, color: 'var(--text-muted)', fontWeight: 600 }}>{l}</div>
            </div>
          ))}
        </div>
      </div>

      {/* last activity */}
      {job.last_activity?.at && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 5, fontSize: 11, color: 'var(--text-muted)' }}>
          <Activity size={12} /> {job.last_activity.label} · {timeAgo(job.last_activity.at)}
        </div>
      )}

      {/* actions */}
      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', borderTop: '1px solid var(--border)', paddingTop: 10 }}>
        <IconBtn icon={Eye} label="View" onClick={onView} />
        {manageHr && <IconBtn icon={Pencil} label="Edit" onClick={onEdit} />}
        <IconBtn icon={Users} label="Candidates" onClick={onCandidates} />
        {manageHr && !live && !['Closed', 'Cancelled'].includes(job.status) && job.status !== 'On_Hold' && <IconBtn icon={Rocket} label="Publish" color="#10b981" onClick={() => onAction('publish')} disabled={busy} />}
        {manageHr && job.status === 'On_Hold' && <IconBtn icon={Play} label="Resume" color="#10b981" onClick={() => onAction('publish')} disabled={busy} />}
        {manageHr && live && <IconBtn icon={Pause} label="Pause" color="#a855f7" onClick={() => onAction('pause')} disabled={busy} />}
        {manageHr && live && !job.on_career_portal && <IconBtn icon={Globe} label="To Portal" color="#0ea5e9" onClick={() => onAction('publish-portal')} disabled={busy} />}
        {manageHr && job.on_career_portal && <IconBtn icon={Globe} label="Off Portal" color="#10b981" onClick={() => onAction('remove-portal')} disabled={busy} />}
        {manageHr && live && <IconBtn icon={Send} label="Unpublish" onClick={() => onAction('unpublish')} disabled={busy} />}
        {manageHr && <IconBtn icon={Copy} label="Duplicate" onClick={() => onAction('duplicate')} disabled={busy} />}
        {manageHr && !['Closed', 'Cancelled', 'Completed'].includes(job.status) && <IconBtn icon={Lock} label="Close" color="#f87171" onClick={() => onClose('close')} disabled={busy} />}
      </div>
    </div>
  )
}

function IconBtn({ icon: Icon, label, color, onClick, disabled }) {
  return (
    <button onClick={onClick} disabled={disabled} title={label}
      style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '5px 10px', borderRadius: 8, background: color ? `${color}14` : 'var(--bg-card)', border: `1px solid ${color ? color + '3a' : 'var(--border)'}`, color: color || 'var(--text-muted)', cursor: disabled ? 'not-allowed' : 'pointer', fontSize: 11.5, fontWeight: 600, opacity: disabled ? 0.6 : 1 }}>
      <Icon size={12} /> {label}
    </button>
  )
}


// ── Job form modal ───────────────────────────────────────────────────────────
function JobFormModal({ form, setForm, editingId, busy, onClose, onSave }) {
  const set = (k) => (e) => setForm(p => ({ ...p, [k]: e.target.value }))
  const toggleSource = (s) => setForm(p => ({ ...p, sources: (p.sources || []).includes(s) ? p.sources.filter(x => x !== s) : [...(p.sources || []), s] }))
  return (
    <Overlay onClose={onClose} width={640}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 18px', fontSize: 18, fontWeight: 800 }}>{editingId ? 'Edit' : 'New'} Job Posting</h2>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
        <Field label="Job Title *"><input value={form.title} onChange={set('title')} placeholder="e.g. Senior Developer" style={inputStyle} /></Field>
        <Field label="Department *"><select value={form.department} onChange={set('department')} style={{ ...inputStyle, cursor: 'pointer' }}><option value="">Select…</option>{DEPARTMENTS.map(d => <option key={d}>{d}</option>)}</select></Field>
        <Field label="Location *"><input value={form.location} onChange={set('location')} placeholder="e.g. Pune" style={inputStyle} /></Field>
        <Field label="Employment Type"><select value={form.job_type} onChange={set('job_type')} style={{ ...inputStyle, cursor: 'pointer' }}>{EMPLOYMENT_TYPES.map(t => <option key={t}>{t}</option>)}</select></Field>
        <Field label="Posting Type"><select value={form.posting_type} onChange={set('posting_type')} style={{ ...inputStyle, cursor: 'pointer' }}>{['Internal', 'External', 'Both'].map(t => <option key={t}>{t}</option>)}</select></Field>
        <Field label="No. of Openings"><input type="number" min="1" value={form.number_of_openings} onChange={set('number_of_openings')} style={inputStyle} /></Field>
        <Field label="Salary From"><input type="number" min="0" value={form.salary_from} onChange={set('salary_from')} placeholder="e.g. 1200000" style={inputStyle} /></Field>
        <Field label="Salary To"><input type="number" min="0" value={form.salary_to} onChange={set('salary_to')} placeholder="e.g. 1800000" style={inputStyle} /></Field>
        <Field label="Closing Date"><input type="date" value={form.closing_date} onChange={set('closing_date')} style={inputStyle} /></Field>
        {!editingId && <Field label="Initial Status"><select value={form.status} onChange={set('status')} style={{ ...inputStyle, cursor: 'pointer' }}>{[JOB_STATUS.DRAFT, JOB_STATUS.PUBLISHED].map(s => <option key={s} value={s}>{jobStatusLabel(s)}</option>)}</select></Field>}
        <Field label="Description" full><textarea value={form.description} onChange={set('description')} rows={3} placeholder="Role summary" style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        <Field label="Requirements" full><textarea value={form.requirements} onChange={set('requirements')} rows={2} placeholder="Skills, experience…" style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        <Field label="Sources" full>
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
            {SOURCES.map(s => <button key={s} type="button" onClick={() => toggleSource(s)} style={{ padding: '5px 10px', borderRadius: 8, fontSize: 11, cursor: 'pointer', border: `1px solid ${(form.sources || []).includes(s) ? '#7C3AED' : 'var(--border)'}`, background: (form.sources || []).includes(s) ? 'rgba(124,58,237,0.15)' : 'var(--bg-input)', color: (form.sources || []).includes(s) ? '#a78bfa' : 'var(--text-muted)' }}>{s}</button>)}
          </div>
        </Field>
      </div>
      <ModalFooter onClose={onClose} onConfirm={onSave} loading={busy} confirmLabel={editingId ? 'Save Changes' : 'Create Job'} />
    </Overlay>
  )
}

// ── Close / cancel modal (reason) ────────────────────────────────────────────
function CloseModal({ closeModal, remarks, setRemarks, busy, onClose, onConfirm }) {
  const { job, action } = closeModal
  const isCancel = action === 'cancel'
  return (
    <Overlay onClose={() => !busy && onClose()} width={440}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 }}>
        {isCancel ? <XCircle size={22} color="#ef4444" /> : <Lock size={22} color="#f87171" />}
        <h3 style={{ color: 'var(--text-h)', margin: 0, fontSize: 16, fontWeight: 800 }}>{isCancel ? 'Cancel Job' : 'Close Job'}</h3>
      </div>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, marginBottom: 14 }}><strong style={{ color: 'var(--text-h)' }}>{job.title}</strong> — {job.department}</p>
      <label style={labelStyle}>Reason (optional)</label>
      <textarea value={remarks} onChange={e => setRemarks(e.target.value)} rows={3} placeholder="Add a note…" style={{ ...inputStyle, resize: 'vertical' }} />
      <ModalFooter onClose={onClose} onConfirm={onConfirm} loading={busy} confirmLabel={isCancel ? 'Cancel Job' : 'Close Job'} color="#ef4444" />
    </Overlay>
  )
}

// ── Reusable primitives (local; Phase 3 will extract to components/ui) ───────
const Field = ({ label, children, full }) => (<div style={full ? { gridColumn: '1/-1' } : undefined}><label style={labelStyle}>{label}</label>{children}</div>)
function Overlay({ onClose, width = 480, children }) {
  return (<div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.72)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }} onClick={e => e.target === e.currentTarget && onClose()}><div className="card-3d" style={{ width: '100%', maxWidth: width, maxHeight: '90vh', overflowY: 'auto', padding: 28 }}>{children}</div></div>)
}
function ModalFooter({ onClose, onConfirm, loading, confirmLabel, color = '#7C3AED' }) {
  return (<div style={{ display: 'flex', gap: 10, marginTop: 22, justifyContent: 'flex-end' }}><button onClick={onClose} disabled={loading} style={{ padding: '9px 20px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>Cancel</button><button onClick={onConfirm} disabled={loading} style={{ padding: '9px 24px', borderRadius: 9, background: loading ? 'rgba(124,58,237,0.4)' : `linear-gradient(135deg,${color},${color}cc)`, color: '#fff', fontWeight: 700, border: 'none', cursor: loading ? 'not-allowed' : 'pointer', fontSize: 13 }}>{loading ? 'Processing…' : confirmLabel}</button></div>)
}
