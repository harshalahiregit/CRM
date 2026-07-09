import { useState, useEffect, useCallback } from 'react'
import { useSearchParams } from 'react-router-dom'
import {
  Plus, CheckCircle, XCircle, Clock, Send, RefreshCw, FileText, Eye, Trash2,
  ThumbsUp, ThumbsDown, Search, Briefcase, Rocket, PlayCircle, Lock, Pencil,
  UserPlus, ShieldCheck, Inbox,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { useAuth } from '@/context/AuthContext'
import {
  MR_STATUS, STATUS_CONFIG, statusColor, statusLabel, PRIORITY_COLORS,
  WORKFLOW_STEPS, EMPLOYEE_LEVELS, EMPLOYMENT_TYPES, PRIORITIES,
  canApproveL1, canApproveL2, canManageHrQueue,
} from '../constants'

const EMPTY_FORM = {
  business_unit: '', department: '', project: '', location: '',
  position_title: '', employee_level: '', experience_required: '',
  number_of_posts: 1, job_type: 'Full-time', priority: 'Medium',
  salary_min: '', salary_max: '', required_skills: '',
  target_joining_date: '', required_by_date: '',
  job_description: '', justification: '',
}

const labelStyle = { display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 6, textTransform: 'uppercase', letterSpacing: '0.05em' }
const inputStyle = { width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }

// ── Status badge ─────────────────────────────────────────────────────────────
function StatusBadge({ status }) {
  const cfg = statusColor(status)
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '3px 10px', borderRadius: 20, fontSize: 11, fontWeight: 700, background: cfg.bg, color: cfg.color, border: `1px solid ${cfg.color}40` }}>
      {statusLabel(status)}
    </span>
  )
}

// ── Header process pipeline (live counts + active-stage highlight) ──────────
function WorkflowPipeline({ stats = {}, activeStatus = 'All', onStage }) {
  const stages = [
    { key: 'dept', label: 'Dept User',       sub: 'Raise',   icon: UserPlus,    color: '#8b5cf6', status: MR_STATUS.DRAFT,           count: stats.draft || 0 },
    { key: 'l1',   label: 'L1 · Dept Head',  sub: 'Approve', icon: ShieldCheck, color: '#f59e0b', status: MR_STATUS.L1_PENDING,      count: stats.l1_pending || 0 },
    { key: 'l2',   label: 'L2 · Management', sub: 'Approve', icon: ShieldCheck, color: '#a855f7', status: MR_STATUS.L2_PENDING,      count: stats.l2_pending || 0 },
    { key: 'hr',   label: 'HR Queue',        sub: 'Review',  icon: Inbox,       color: '#0ea5e9', status: MR_STATUS.READY_FOR_HR,    count: stats.ready_for_hr || 0 },
    { key: 'jd',   label: 'Job Description', sub: 'Convert', icon: FileText,    color: '#6366f1', status: MR_STATUS.CONVERTED_TO_JD, count: stats.converted_to_jd || 0 },
    { key: 'post', label: 'Job Posting',     sub: 'Publish', icon: Rocket,      color: '#10b981', status: MR_STATUS.JOB_POSTED,      count: (stats.job_posted || 0) + (stats.hiring || 0) },
  ]
  return (
    <div style={{ display: 'flex', alignItems: 'center', width: 'max-content', minWidth: '100%' }}>
      {stages.map((s, i) => {
        const Icon = s.icon
        const hasWork  = s.count > 0            // a stage currently holding requests
        const selected = activeStatus === s.status
        const lit = hasWork || selected         // "working" / highlighted step
        return (
          <div key={s.key} style={{ display: 'flex', alignItems: 'center' }}>
            <button type="button" onClick={() => onStage?.(selected ? 'All' : s.status)} title={`${s.count} in ${s.label} — click to filter`}
              className={`mr-node${lit ? ' mr-active' : ''}`}
              style={{
                '--glow': s.color,
                display: 'flex', alignItems: 'center', gap: 8, padding: '5px 10px 5px 5px', borderRadius: 999, cursor: 'pointer',
                background: lit ? `${s.color}22` : `${s.color}0d`,
                border: `1.5px solid ${lit ? s.color : s.color + '3a'}`,
                opacity: lit ? 1 : 0.5,
                boxShadow: selected ? `0 0 0 2px ${s.color}` : undefined,
              }}>
              <span style={{ width: 26, height: 26, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', background: `linear-gradient(135deg, ${s.color}, ${s.color}bb)`, color: '#fff', boxShadow: lit ? `0 2px 12px ${s.color}88` : 'none', flexShrink: 0 }}>
                <Icon size={13} />
              </span>
              <span style={{ display: 'flex', flexDirection: 'column', lineHeight: 1.1, textAlign: 'left' }}>
                <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-h)', whiteSpace: 'nowrap' }}>{s.label}</span>
                <span style={{ fontSize: 9, fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase', color: s.color }}>{s.sub}</span>
              </span>
              {s.count > 0 && (
                <span style={{ minWidth: 20, height: 20, padding: '0 6px', borderRadius: 999, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11, fontWeight: 800, color: '#fff', background: s.color, flexShrink: 0 }}>{s.count}</span>
              )}
            </button>
            {i < stages.length - 1 && (
              <div className={`mr-flow${lit ? '' : ' mr-flow-dim'}`} style={{ width: 26, height: 4, borderRadius: 4, margin: '0 5px', background: `linear-gradient(90deg, ${s.color}, ${stages[i + 1].color}, ${s.color})`, backgroundSize: '200% 100%' }} />
            )}
          </div>
        )
      })}
    </div>
  )
}

// ── Workflow stepper (Draft → … → Closed) ───────────────────────────────────
function WorkflowStepper({ status }) {
  if (status === MR_STATUS.REJECTED) {
    return (
      <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11, color: '#ef4444' }}>
        <XCircle size={13} /> Rejected
      </div>
    )
  }
  // normalise legacy → canonical for indexing
  const norm = { Pending_L1: MR_STATUS.L1_PENDING, Pending_L2: MR_STATUS.L2_PENDING, Approved: MR_STATUS.READY_FOR_HR, Pending: MR_STATUS.L1_PENDING }[status] || status
  const currentIdx = WORKFLOW_STEPS.findIndex(s => s.key === norm)
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 4, flexWrap: 'wrap' }}>
      {WORKFLOW_STEPS.map((step, i) => {
        const done = i < currentIdx, active = i === currentIdx
        return (
          <div key={step.key} style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
            <div style={{
              display: 'flex', alignItems: 'center', gap: 4, fontSize: 10, fontWeight: 600, padding: '2px 8px', borderRadius: 12,
              background: done ? 'rgba(16,185,129,0.15)' : active ? 'rgba(139,92,246,0.2)' : 'rgba(255,255,255,0.05)',
              color: done ? '#10b981' : active ? '#a78bfa' : 'var(--text-muted)',
              border: `1px solid ${done ? 'rgba(16,185,129,0.3)' : active ? 'rgba(139,92,246,0.4)' : 'rgba(255,255,255,0.08)'}`,
            }}>
              {done ? <CheckCircle size={10} /> : active ? <Clock size={10} /> : <div style={{ width: 8, height: 8, borderRadius: '50%', background: 'currentColor', opacity: 0.4 }} />}
              {step.label}
            </div>
            {i < WORKFLOW_STEPS.length - 1 && <div style={{ width: 12, height: 1, background: 'rgba(255,255,255,0.1)' }} />}
          </div>
        )
      })}
    </div>
  )
}

// ── Main page ────────────────────────────────────────────────────────────────
export default function ManpowerRequests() {
  const { user } = useAuth()
  const approveL1 = canApproveL1(user)
  const approveL2 = canApproveL2(user)
  const manageHr  = canManageHrQueue(user)

  const [searchParams] = useSearchParams()
  const [view, setView]         = useState('all')       // all | approvals | queue
  const [requests, setRequests] = useState([])
  const [stats, setStats]       = useState({})
  const [loading, setLoading]   = useState(true)
  const [search, setSearch]     = useState('')
  const [filterStatus, setFilterStatus] = useState(searchParams.get('status') || 'All')

  const [showModal, setShowModal]     = useState(false)
  const [editingId, setEditingId]     = useState(null)
  const [form, setForm]               = useState(EMPTY_FORM)
  const [saving, setSaving]           = useState(false)

  const [actionModal, setActionModal] = useState(null)   // { request, action }
  const [remarks, setRemarks]         = useState('')
  const [actionLoading, setActionLoading] = useState(false)

  const [convertModal, setConvertModal] = useState(null) // { request, jd }
  const [detail, setDetail]             = useState(null)

  const fetchAll = useCallback(async () => {
    setLoading(true)
    try {
      const listCall = view === 'queue' ? hrApi.manpower.queue() : hrApi.manpower.list()
      const [listRes, statRes] = await Promise.all([listCall, hrApi.manpower.stats()])
      let rows = listRes?.data ?? listRes ?? []
      if (view === 'approvals') {
        rows = rows.filter(r =>
          (r.status === MR_STATUS.L1_PENDING && approveL1) ||
          (r.status === MR_STATUS.L2_PENDING && approveL2))
      }
      setRequests(Array.isArray(rows) ? rows : [])
      setStats(statRes?.data ?? statRes ?? {})
    } catch (e) { console.error('Failed to load manpower requests', e) }
    finally { setLoading(false) }
  }, [view, approveL1, approveL2])

  useEffect(() => { fetchAll() }, [fetchAll])

  const filtered = requests.filter(r => {
    const q = search.toLowerCase()
    const matchSearch = !q || r.position_title?.toLowerCase().includes(q) || r.department?.toLowerCase().includes(q) || r.project?.toLowerCase().includes(q)
    const matchStatus = filterStatus === 'All' || r.status === filterStatus
    return matchSearch && matchStatus
  })

  // ── Create / edit ──────────────────────────────────────────────────────────
  const openCreate = () => { setEditingId(null); setForm(EMPTY_FORM); setShowModal(true) }
  const openEdit = (r) => {
    setEditingId(r.id)
    setForm({
      ...EMPTY_FORM, ...r,
      salary_min: r.salary_min ?? '', salary_max: r.salary_max ?? '',
      required_skills: Array.isArray(r.required_skills) ? r.required_skills.join(', ') : (r.required_skills || ''),
      target_joining_date: r.target_joining_date?.slice(0, 10) || '',
      required_by_date: r.required_by_date?.slice(0, 10) || '',
    })
    setShowModal(true)
  }

  const buildPayload = () => {
    const p = { ...form }
    p.number_of_posts = Number(p.number_of_posts) || 1
    p.salary_min = p.salary_min === '' ? null : Number(p.salary_min)
    p.salary_max = p.salary_max === '' ? null : Number(p.salary_max)
    p.required_skills = form.required_skills
      ? form.required_skills.split(',').map(s => s.trim()).filter(Boolean)
      : []
    Object.keys(p).forEach(k => { if (p[k] === '') p[k] = null })
    return p
  }

  const handleSave = async () => {
    if (!form.department || !form.position_title) { alert('Department and Job Title are required'); return }
    setSaving(true)
    try {
      if (editingId) await hrApi.manpower.update(editingId, buildPayload())
      else await hrApi.manpower.create(buildPayload())
      setShowModal(false); setForm(EMPTY_FORM); setEditingId(null)
      fetchAll()
    } catch (e) { alert(e?.response?.data?.message || 'Failed to save request') }
    finally { setSaving(false) }
  }

  // ── Workflow actions ───────────────────────────────────────────────────────
  const runAction = async () => {
    if (!actionModal) return
    const { request, action } = actionModal
    setActionLoading(true)
    try {
      const id = request.id
      if (action === 'submit')          await hrApi.manpower.submit(id)
      else if (action === 'approve-l1') await hrApi.manpower.approveL1(id, remarks)
      else if (action === 'reject-l1')  await hrApi.manpower.rejectL1(id, remarks)
      else if (action === 'approve-l2') await hrApi.manpower.approveL2(id, remarks)
      else if (action === 'reject-l2')  await hrApi.manpower.rejectL2(id, remarks)
      else if (action === 'publish')    await hrApi.manpower.publish(id)
      else if (action === 'start-hiring') await hrApi.manpower.startHiring(id)
      else if (action === 'close')      await hrApi.manpower.close(id, remarks)
      else if (action === 'delete')     await hrApi.manpower.delete(id)
      setActionModal(null); setRemarks(''); fetchAll()
    } catch (e) { alert(e?.response?.data?.message || 'Action failed') }
    finally { setActionLoading(false) }
  }

  const openAction = (request, action) => { setActionModal({ request, action }); setRemarks('') }

  // ── Convert to JD ──────────────────────────────────────────────────────────
  const openConvert = (r) => setConvertModal({
    request: r,
    jd: {
      title: r.position_title || '', location: r.location || '', posting_type: 'Both',
      description: r.job_description || r.justification || '',
      requirements: [r.required_skills?.length ? 'Skills: ' + (Array.isArray(r.required_skills) ? r.required_skills.join(', ') : r.required_skills) : '', r.experience_required ? 'Experience: ' + r.experience_required : ''].filter(Boolean).join('\n'),
      closing_date: r.target_joining_date?.slice(0, 10) || '',
    },
  })
  const runConvert = async () => {
    setActionLoading(true)
    try {
      const { request, jd } = convertModal
      const payload = { ...jd }
      if (!payload.closing_date) delete payload.closing_date
      await hrApi.manpower.convertToJd(request.id, payload)
      setConvertModal(null); fetchAll()
    } catch (e) { alert(e?.response?.data?.message || 'Conversion failed') }
    finally { setActionLoading(false) }
  }

  // ── Stat cards (dashboard-aligned 8 metrics + draft/rejected) ────────────────
  const statCards = [
    { label: 'Total',        value: stats.total,           color: '#7C3AED', filter: 'All' },
    { label: 'L1 Pending',   value: stats.l1_pending,      color: '#f59e0b', filter: MR_STATUS.L1_PENDING },
    { label: 'L2 Pending',   value: stats.l2_pending,      color: '#8b5cf6', filter: MR_STATUS.L2_PENDING },
    { label: 'Ready for HR', value: stats.ready_for_hr,    color: '#0ea5e9', filter: MR_STATUS.READY_FOR_HR },
    { label: 'Converted JD', value: stats.converted_to_jd, color: '#6366f1', filter: MR_STATUS.CONVERTED_TO_JD },
    { label: 'Posted',       value: stats.job_posted,      color: '#10b981', filter: MR_STATUS.JOB_POSTED },
    { label: 'Hiring',       value: stats.hiring,          color: '#14b8a6', filter: MR_STATUS.HIRING_IN_PROGRESS },
    { label: 'Closed',       value: stats.closed,          color: '#64748b', filter: MR_STATUS.CLOSED },
  ]

  const isOwner = (r) => r.requested_by === user?.id || user?.role === 'admin'

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14, flexWrap: 'wrap', gap: 12 }}>
        <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>Manpower Requests</h1>
        <div style={{ display: 'flex', gap: 10 }}>
          <button onClick={fetchAll} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>
            <RefreshCw size={14} /> Refresh
          </button>
          <button onClick={openCreate} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 18px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }}>
            <Plus size={15} /> New Request
          </button>
        </div>
      </div>

      {/* Process pipeline (full-width, live counts + active highlight) */}
      <div style={{ marginBottom: 20, overflowX: 'auto', paddingBottom: 4 }}>
        <WorkflowPipeline stats={stats} activeStatus={filterStatus} onStage={setFilterStatus} />
      </div>

      {/* View tabs */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 18 }}>
        {[
          { key: 'all', label: 'All Requests' },
          ...(approveL1 || approveL2 ? [{ key: 'approvals', label: 'Pending My Approval' }] : []),
          ...(manageHr ? [{ key: 'queue', label: 'HR Queue' }] : []),
        ].map(t => (
          <button key={t.key} onClick={() => { setView(t.key); setFilterStatus('All') }}
            style={{ padding: '7px 16px', borderRadius: 9, fontSize: 13, fontWeight: 600, cursor: 'pointer',
              border: `1px solid ${view === t.key ? 'rgba(124,58,237,0.5)' : 'var(--border)'}`,
              background: view === t.key ? 'rgba(124,58,237,0.15)' : 'var(--bg-card)',
              color: view === t.key ? '#a78bfa' : 'var(--text-muted)' }}>
            {t.label}
          </button>
        ))}
      </div>

      {/* Stats row */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(8,1fr)', gap: 10, marginBottom: 22 }}>
        {statCards.map(s => (
          <div key={s.label} className="kpi-3d" style={{ padding: 14, textAlign: 'center', cursor: 'pointer', opacity: filterStatus === s.filter || (s.filter === 'All' && filterStatus === 'All') ? 1 : 0.85 }}
            onClick={() => setFilterStatus(s.filter)}>
            <div style={{ fontSize: 22, fontWeight: 900, color: s.color }}>{s.value || 0}</div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 2 }}>{s.label}</div>
          </div>
        ))}
      </div>

      {/* Filters */}
      <div className="card-3d" style={{ padding: '14px 18px', marginBottom: 18, display: 'flex', gap: 12, alignItems: 'center' }}>
        <div style={{ position: 'relative', flex: 1 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search job title, department or project..." style={{ ...inputStyle, paddingLeft: 32 }} />
        </div>
        <select value={filterStatus} onChange={e => setFilterStatus(e.target.value)} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="All">All Status</option>
          {Object.values(MR_STATUS).map(s => <option key={s} value={s}>{STATUS_CONFIG[s]?.label || s}</option>)}
        </select>
      </div>

      {/* List */}
      {loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>Loading...</div>
      ) : filtered.length === 0 ? (
        <div className="card-3d" style={{ padding: 40, textAlign: 'center', color: 'var(--text-muted)' }}>
          <FileText size={40} style={{ marginBottom: 12, opacity: 0.4 }} />
          <p>No requests found</p>
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          {filtered.map(req => (
            <div key={req.id} className="card-3d" style={{ padding: 20 }}>
              <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16 }}>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6, flexWrap: 'wrap' }}>
                    <span style={{ color: '#a78bfa', fontWeight: 800, fontSize: 12 }}>MR-{req.id}</span>
                    <span style={{ color: 'var(--text-h)', fontWeight: 700, fontSize: 15 }}>{req.position_title}</span>
                    <StatusBadge status={req.status} />
                    <span style={{ padding: '2px 8px', borderRadius: 10, fontSize: 11, fontWeight: 700, background: `${PRIORITY_COLORS[req.priority]}20`, color: PRIORITY_COLORS[req.priority], border: `1px solid ${PRIORITY_COLORS[req.priority]}40` }}>{req.priority}</span>
                    <span style={{ padding: '2px 8px', borderRadius: 10, fontSize: 11, background: 'rgba(124,58,237,0.1)', color: '#a78bfa', border: '1px solid rgba(124,58,237,0.2)' }}>{req.job_type}</span>
                    {req.employee_level && <span style={{ padding: '2px 8px', borderRadius: 10, fontSize: 11, background: 'rgba(255,255,255,0.05)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>{req.employee_level}</span>}
                  </div>
                  <div style={{ display: 'flex', gap: 14, color: 'var(--text-muted)', fontSize: 12, marginBottom: 10, flexWrap: 'wrap' }}>
                    <span>🏢 {req.department}</span>
                    {req.business_unit && <span>🏬 {req.business_unit}</span>}
                    {req.project && <span>📁 {req.project}</span>}
                    {req.location && <span>📍 {req.location}</span>}
                    <span>👥 {req.number_of_posts} post{req.number_of_posts > 1 ? 's' : ''}</span>
                    {(req.salary_min || req.salary_max) && <span>💰 {req.salary_min || '—'}–{req.salary_max || '—'}</span>}
                    {req.target_joining_date && <span>🎯 {new Date(req.target_joining_date).toLocaleDateString('en-IN')}</span>}
                    <span>👤 {req.requester?.name || 'Unknown'}</span>
                  </div>
                  <WorkflowStepper status={req.status} />
                  {(req.l1_approver_id || req.l2_approver_id) && (
                    <div style={{ display: 'flex', gap: 12, marginTop: 8, fontSize: 11, color: 'var(--text-muted)' }}>
                      {req.l1_approver_id && <span>✅ L1: <strong style={{ color: '#10b981' }}>{req.l1_approver?.name || 'Dept Head'}</strong></span>}
                      {req.l2_approver_id && <span>✅ L2: <strong style={{ color: '#10b981' }}>{req.l2_approver?.name || 'Management'}</strong></span>}
                      {req.job_posting?.id && <span>📄 JD: <strong style={{ color: '#6366f1' }}>#{req.job_posting.id}</strong></span>}
                    </div>
                  )}
                </div>

                {/* Actions */}
                <div style={{ display: 'flex', flexDirection: 'column', gap: 6, flexShrink: 0, alignItems: 'stretch' }}>
                  {req.status === MR_STATUS.DRAFT && isOwner(req) && (
                    <ActBtn onClick={() => openAction(req, 'submit')} icon={Send} color="#a78bfa" bg="rgba(124,58,237,0.15)">Submit for L1</ActBtn>
                  )}
                  {req.status === MR_STATUS.L1_PENDING && approveL1 && (
                    <div style={{ display: 'flex', gap: 6 }}>
                      <ActBtn onClick={() => openAction(req, 'approve-l1')} icon={ThumbsUp} color="#10b981" bg="rgba(16,185,129,0.15)">L1 Approve</ActBtn>
                      <ActBtn onClick={() => openAction(req, 'reject-l1')} icon={ThumbsDown} color="#f87171" bg="rgba(239,68,68,0.1)">Reject</ActBtn>
                    </div>
                  )}
                  {req.status === MR_STATUS.L2_PENDING && approveL2 && (
                    <div style={{ display: 'flex', gap: 6 }}>
                      <ActBtn onClick={() => openAction(req, 'approve-l2')} icon={ThumbsUp} color="#10b981" bg="rgba(16,185,129,0.15)">L2 Approve</ActBtn>
                      <ActBtn onClick={() => openAction(req, 'reject-l2')} icon={ThumbsDown} color="#f87171" bg="rgba(239,68,68,0.1)">Reject</ActBtn>
                    </div>
                  )}
                  {req.status === MR_STATUS.READY_FOR_HR && manageHr && (
                    <ActBtn onClick={() => openConvert(req)} icon={Briefcase} color="#6366f1" bg="rgba(99,102,241,0.15)">Convert to JD</ActBtn>
                  )}
                  {req.status === MR_STATUS.CONVERTED_TO_JD && manageHr && (
                    <ActBtn onClick={() => openAction(req, 'publish')} icon={Rocket} color="#10b981" bg="rgba(16,185,129,0.15)">Publish Job</ActBtn>
                  )}
                  {req.status === MR_STATUS.JOB_POSTED && manageHr && (
                    <ActBtn onClick={() => openAction(req, 'start-hiring')} icon={PlayCircle} color="#14b8a6" bg="rgba(20,184,166,0.15)">Start Hiring</ActBtn>
                  )}
                  {[MR_STATUS.JOB_POSTED, MR_STATUS.HIRING_IN_PROGRESS].includes(req.status) && manageHr && (
                    <ActBtn onClick={() => openAction(req, 'close')} icon={Lock} color="#94a3b8" bg="rgba(100,116,139,0.12)">Close</ActBtn>
                  )}
                  {[MR_STATUS.DRAFT, MR_STATUS.REJECTED].includes(req.status) && isOwner(req) && (
                    <ActBtn onClick={() => openEdit(req)} icon={Pencil} color="var(--text-muted)" bg="var(--bg-card)" border>Edit</ActBtn>
                  )}
                  <ActBtn onClick={() => setDetail(req)} icon={Eye} color="var(--text-muted)" bg="var(--bg-card)" border>View</ActBtn>
                  {[MR_STATUS.DRAFT, MR_STATUS.REJECTED].includes(req.status) && isOwner(req) && (
                    <ActBtn onClick={() => openAction(req, 'delete')} icon={Trash2} color="#f87171" bg="var(--bg-card)" border>Delete</ActBtn>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {showModal && <RequestFormModal {...{ form, setForm, editingId, saving, onClose: () => setShowModal(false), onSave: handleSave }} />}
      {actionModal && <ActionModal {...{ actionModal, remarks, setRemarks, actionLoading, onClose: () => setActionModal(null), onConfirm: runAction }} />}
      {convertModal && <ConvertModal {...{ convertModal, setConvertModal, actionLoading, onClose: () => setConvertModal(null), onConfirm: runConvert }} />}
      {detail && <DetailModal request={detail} onClose={() => setDetail(null)} />}
    </div>
  )
}

// ── Small action button ──────────────────────────────────────────────────────
function ActBtn({ onClick, icon: Icon, color, bg, border, children }) {
  return (
    <button onClick={onClick} style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 5, padding: '6px 12px', borderRadius: 8, background: bg, border: `1px solid ${border ? 'var(--border)' : color + '4d'}`, color, cursor: 'pointer', fontSize: 12, fontWeight: 600, whiteSpace: 'nowrap' }}>
      <Icon size={12} /> {children}
    </button>
  )
}

// ── Field helpers ────────────────────────────────────────────────────────────
const Field = ({ label, children, full }) => (
  <div style={full ? { gridColumn: '1/-1' } : undefined}>
    <label style={labelStyle}>{label}</label>
    {children}
  </div>
)
const TextInput = (props) => <input {...props} style={inputStyle} />
const SelectInput = ({ options, ...p }) => <select {...p} style={{ ...inputStyle, cursor: 'pointer' }}>{options.map(o => <option key={o} value={o}>{o}</option>)}</select>

// ── Request create/edit modal ────────────────────────────────────────────────
function RequestFormModal({ form, setForm, editingId, saving, onClose, onSave }) {
  const set = (k) => (e) => setForm(p => ({ ...p, [k]: e.target.value }))
  return (
    <Overlay onClose={onClose} width={720}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>{editingId ? 'Edit' : 'New'} Manpower Request</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 18px' }}>Provide complete hiring information so HR can act without re-entering data.</p>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 14 }}>
        <Field label="Business Unit"><TextInput value={form.business_unit} onChange={set('business_unit')} placeholder="e.g. Technology" /></Field>
        <Field label="Department *"><TextInput value={form.department} onChange={set('department')} placeholder="e.g. Engineering" /></Field>
        <Field label="Project"><TextInput value={form.project} onChange={set('project')} placeholder="e.g. Apollo" /></Field>
        <Field label="Project Location"><TextInput value={form.location} onChange={set('location')} placeholder="e.g. Pune" /></Field>
        <Field label="Job Title *"><TextInput value={form.position_title} onChange={set('position_title')} placeholder="e.g. Senior Developer" /></Field>
        <Field label="Employee Level"><SelectInput value={form.employee_level} onChange={set('employee_level')} options={['', ...EMPLOYEE_LEVELS]} /></Field>
        <Field label="Employment Type"><SelectInput value={form.job_type} onChange={set('job_type')} options={EMPLOYMENT_TYPES} /></Field>
        <Field label="Experience"><TextInput value={form.experience_required} onChange={set('experience_required')} placeholder="e.g. 3-5 years" /></Field>
        <Field label="No. of Positions *"><TextInput type="number" min="1" value={form.number_of_posts} onChange={set('number_of_posts')} /></Field>
        <Field label="Priority"><SelectInput value={form.priority} onChange={set('priority')} options={PRIORITIES} /></Field>
        <Field label="Salary Min"><TextInput type="number" min="0" value={form.salary_min} onChange={set('salary_min')} placeholder="e.g. 1200000" /></Field>
        <Field label="Salary Max"><TextInput type="number" min="0" value={form.salary_max} onChange={set('salary_max')} placeholder="e.g. 1800000" /></Field>
        <Field label="Target Joining Date"><TextInput type="date" value={form.target_joining_date} onChange={set('target_joining_date')} /></Field>
        <Field label="Required By Date"><TextInput type="date" value={form.required_by_date} onChange={set('required_by_date')} /></Field>
        <Field label="Required Skills (comma-separated)" full><TextInput value={form.required_skills} onChange={set('required_skills')} placeholder="e.g. PHP, Laravel, React" /></Field>
        <Field label="Job Description" full><textarea value={form.job_description} onChange={set('job_description')} rows={3} placeholder="Role summary and responsibilities" style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        <Field label="Hiring Justification" full><textarea value={form.justification} onChange={set('justification')} rows={2} placeholder="Why is this position required?" style={{ ...inputStyle, resize: 'vertical' }} /></Field>
      </div>
      <ModalFooter onClose={onClose} onConfirm={onSave} loading={saving} confirmLabel={editingId ? 'Save Changes' : 'Create Request'} />
    </Overlay>
  )
}

// ── Approve / reject / publish / close modal ─────────────────────────────────
function ActionModal({ actionModal, remarks, setRemarks, actionLoading, onClose, onConfirm }) {
  const { action, request } = actionModal
  const isReject = action.startsWith('reject')
  const isDelete = action === 'delete'
  const needsReason = isReject
  const meta = {
    submit: { title: 'Submit for L1 Approval', color: '#7C3AED' },
    'approve-l1': { title: 'Approve L1 (Department Head)', color: '#10b981' },
    'reject-l1': { title: 'Reject at L1', color: '#ef4444' },
    'approve-l2': { title: 'Approve L2 (Management)', color: '#10b981' },
    'reject-l2': { title: 'Reject at L2', color: '#ef4444' },
    publish: { title: 'Publish Job', color: '#10b981' },
    'start-hiring': { title: 'Start Hiring', color: '#14b8a6' },
    close: { title: 'Close Position', color: '#94a3b8' },
    delete: { title: 'Delete Request', color: '#ef4444' },
  }[action]
  const showRemarks = !['submit', 'publish', 'start-hiring', 'delete'].includes(action)
  return (
    <Overlay onClose={() => !actionLoading && onClose()} width={460}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 }}>
        {isReject || isDelete ? <XCircle size={22} color={meta.color} /> : <CheckCircle size={22} color={meta.color} />}
        <h3 style={{ color: 'var(--text-h)', margin: 0, fontSize: 16, fontWeight: 800 }}>{meta.title}</h3>
      </div>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, marginBottom: 14 }}>
        <strong style={{ color: 'var(--text-h)' }}>MR-{request.id} · {request.position_title}</strong> — {request.department}
      </p>
      {action === 'submit' && <InfoBox>Sends to <strong>Department Head (L1)</strong>, then <strong>Management (L2)</strong>. HR can act only after both approvals.</InfoBox>}
      {action === 'delete' && <InfoBox tone="danger">This permanently deletes the request. This cannot be undone.</InfoBox>}
      {showRemarks && (
        <>
          <label style={labelStyle}>{needsReason ? 'Reason for Rejection *' : 'Remarks (optional)'}</label>
          <textarea value={remarks} onChange={e => setRemarks(e.target.value)} rows={3} placeholder={needsReason ? 'Enter reason...' : 'Add remarks...'}
            style={{ ...inputStyle, resize: 'vertical', borderColor: needsReason && !remarks ? '#ef444480' : 'var(--border)' }} />
        </>
      )}
      <ModalFooter onClose={onClose} onConfirm={onConfirm} loading={actionLoading} disabled={needsReason && !remarks} confirmLabel="Confirm" color={meta.color} />
    </Overlay>
  )
}

// ── Convert to JD modal (HR edits missing info) ──────────────────────────────
function ConvertModal({ convertModal, setConvertModal, actionLoading, onClose, onConfirm }) {
  const { request, jd } = convertModal
  const set = (k) => (e) => setConvertModal(p => ({ ...p, jd: { ...p.jd, [k]: e.target.value } }))
  return (
    <Overlay onClose={() => !actionLoading && onClose()} width={620}>
      <h3 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 17, fontWeight: 800 }}>Convert to Job Description</h3>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>Pre-filled from MR-{request.id}. Edit only what's missing, then create the JD draft.</p>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
        <Field label="Job Title"><TextInput value={jd.title} onChange={set('title')} /></Field>
        <Field label="Location"><TextInput value={jd.location} onChange={set('location')} /></Field>
        <Field label="Posting Type"><SelectInput value={jd.posting_type} onChange={set('posting_type')} options={['Internal', 'External', 'Both']} /></Field>
        <Field label="Closing Date"><TextInput type="date" value={jd.closing_date} onChange={set('closing_date')} /></Field>
        <Field label="Description" full><textarea value={jd.description} onChange={set('description')} rows={3} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        <Field label="Requirements" full><textarea value={jd.requirements} onChange={set('requirements')} rows={3} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
      </div>
      <ModalFooter onClose={onClose} onConfirm={onConfirm} loading={actionLoading} confirmLabel="Create JD Draft" color="#6366f1" />
    </Overlay>
  )
}

// ── Detail modal ─────────────────────────────────────────────────────────────
function DetailModal({ request, onClose }) {
  const rows = [
    ['Business Unit', request.business_unit], ['Department', request.department], ['Project', request.project],
    ['Location', request.location], ['Job Title', request.position_title], ['Employee Level', request.employee_level],
    ['Employment Type', request.job_type], ['Experience', request.experience_required], ['Positions', request.number_of_posts],
    ['Priority', request.priority], ['Salary', (request.salary_min || request.salary_max) ? `${request.salary_min || '—'} – ${request.salary_max || '—'}` : null],
    ['Skills', Array.isArray(request.required_skills) ? request.required_skills.join(', ') : request.required_skills],
    ['Target Joining', request.target_joining_date && new Date(request.target_joining_date).toLocaleDateString('en-IN')],
    ['Requested By', request.requester?.name],
  ].filter(([, v]) => v !== null && v !== undefined && v !== '')
  return (
    <Overlay onClose={onClose} width={640}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14, flexWrap: 'wrap' }}>
        <h3 style={{ color: 'var(--text-h)', margin: 0, fontSize: 17, fontWeight: 800 }}>MR-{request.id} · {request.position_title}</h3>
        <StatusBadge status={request.status} />
      </div>
      <div style={{ marginBottom: 16 }}><WorkflowStepper status={request.status} /></div>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px 20px', marginBottom: 16 }}>
        {rows.map(([k, v]) => (
          <div key={k} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, borderBottom: '1px solid var(--border)', paddingBottom: 6 }}>
            <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>{k}</span>
            <span style={{ color: 'var(--text-h)', fontSize: 12, fontWeight: 600, textAlign: 'right' }}>{v}</span>
          </div>
        ))}
      </div>
      {request.job_description && <DetailBlock title="Job Description" text={request.job_description} />}
      {request.justification && <DetailBlock title="Hiring Justification" text={request.justification} />}
      {request.approvalHistory?.length > 0 && (
        <div style={{ marginTop: 8 }}>
          <label style={labelStyle}>Approval History</label>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            {request.approvalHistory.map(h => (
              <div key={h.id} style={{ display: 'flex', gap: 8, fontSize: 12, color: 'var(--text-muted)', padding: '6px 10px', background: 'var(--bg-input)', borderRadius: 8 }}>
                <span style={{ fontWeight: 700, color: 'var(--text-h)' }}>{h.action}</span>
                {h.level && h.level !== 'General' && <span style={{ color: '#a78bfa' }}>[{h.level}]</span>}
                {h.actor?.name && <span>· {h.actor.name}</span>}
                {h.remarks && <span style={{ fontStyle: 'italic' }}>— {h.remarks}</span>}
                <span style={{ marginLeft: 'auto' }}>{h.created_at && new Date(h.created_at).toLocaleString('en-IN')}</span>
              </div>
            ))}
          </div>
        </div>
      )}
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 18 }}>
        <button onClick={onClose} style={{ padding: '9px 20px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>Close</button>
      </div>
    </Overlay>
  )
}

const DetailBlock = ({ title, text }) => (
  <div style={{ marginBottom: 14 }}>
    <label style={labelStyle}>{title}</label>
    <p style={{ color: 'var(--text-h)', fontSize: 13, margin: 0, whiteSpace: 'pre-wrap', lineHeight: 1.5 }}>{text}</p>
  </div>
)

// ── Reusable overlay / footer ────────────────────────────────────────────────
function Overlay({ onClose, width = 480, children }) {
  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.72)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}
      onClick={e => e.target === e.currentTarget && onClose()}>
      <div className="card-3d" style={{ width: '100%', maxWidth: width, maxHeight: '90vh', overflowY: 'auto', padding: 28 }}>{children}</div>
    </div>
  )
}
function ModalFooter({ onClose, onConfirm, loading, disabled, confirmLabel, color = '#7C3AED' }) {
  return (
    <div style={{ display: 'flex', gap: 10, marginTop: 22, justifyContent: 'flex-end' }}>
      <button onClick={onClose} disabled={loading} style={{ padding: '9px 20px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>Cancel</button>
      <button onClick={onConfirm} disabled={loading || disabled}
        style={{ padding: '9px 24px', borderRadius: 9, background: loading || disabled ? 'rgba(124,58,237,0.4)' : `linear-gradient(135deg,${color},${color}cc)`, color: '#fff', fontWeight: 700, border: 'none', cursor: loading || disabled ? 'not-allowed' : 'pointer', fontSize: 13, opacity: disabled ? 0.6 : 1 }}>
        {loading ? 'Processing...' : confirmLabel}
      </button>
    </div>
  )
}
const InfoBox = ({ tone, children }) => (
  <p style={{ color: 'var(--text-muted)', fontSize: 12, padding: '10px 14px', marginBottom: 14, background: tone === 'danger' ? 'rgba(239,68,68,0.08)' : 'rgba(124,58,237,0.08)', borderRadius: 8, border: `1px solid ${tone === 'danger' ? 'rgba(239,68,68,0.2)' : 'rgba(124,58,237,0.2)'}` }}>{children}</p>
)
