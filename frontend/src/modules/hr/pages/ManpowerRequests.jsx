import { useState, useEffect, useCallback, useMemo } from 'react'
import { useSearchParams, useNavigate } from 'react-router-dom'
import {
  Plus, CheckCircle, XCircle, Clock, Send, RefreshCw, FileText, Eye, Trash2,
  ThumbsUp, ThumbsDown, Search, Briefcase, Rocket, PlayCircle, Lock, Pencil,
  UserPlus, ShieldCheck, Inbox, CornerUpLeft, LayoutGrid, List, ChevronUp, ChevronDown, ChevronsUpDown, ChevronLeft, ChevronRight,
  Sparkles, Loader2, TrendingUp,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { useAuth } from '@/context/AuthContext'
import AuditTimeline from '@/components/ui/AuditTimeline'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import SearchableSelect from '@/modules/hr/components/SearchableSelect'
import {
  MR_STATUS, STATUS_CONFIG, statusColor, statusLabel, PRIORITY_COLORS,
  WORKFLOW_STEPS, EMPLOYEE_LEVELS, EMPLOYMENT_TYPES, PRIORITIES,
  canApproveL1, canApproveL2, canManageHrQueue,
} from '../constants'

const EMPTY_FORM = {
  business_unit: '', department: '', project: '', location: '', hiring_manager_id: '',
  position_title: '', employee_level: '', job_type: 'Full-time', work_mode: '', shift: '',
  number_of_posts: 1, experience_required: '', required_by_date: '', target_joining_date: '',
  salary_min: '', salary_max: '', budget: '', required_skills: '', preferred_skills: '',
  education: '', certifications: '',
  hiring_reason: '', replacement_employee_id: '', cost_center: '', priority: 'Medium', criticality: '',
  job_description: '', justification: '',
}
// Job Requisition criticality (Doc 3 Module 2)
const CRITICALITY = ['Low', 'Medium', 'High', 'Business Critical']
// Enterprise enums (SPK-1) — match the backend `in:` validation lists.
const WORK_MODES = ['Onsite', 'Remote', 'Hybrid']
const SHIFTS = ['Day', 'Night', 'Rotational', 'Flexible']
const HIRING_REASONS = ['New Position', 'Replacement', 'Expansion', 'Contract']

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

// ── Simplified 4-step workflow (SPK-1) ──────────────────────────────────────
// Presentation only. The 6 backend statuses are folded into 4 user-facing steps;
// the underlying create → L1 → L2 → HR Queue → JD → Job Posting flow, its APIs
// and automation are untouched. Approval combines L1 + L2 into one step.
const wf4Canon = (s) => ({ Pending_L1: MR_STATUS.L1_PENDING, Pending_L2: MR_STATUS.L2_PENDING, Approved: MR_STATUS.READY_FOR_HR, Pending: MR_STATUS.L1_PENDING }[s] || s)
const WF4 = [
  { key: 'request',   label: 'Request',   sub: 'Submitted',  icon: FileText,    color: '#8b5cf6', statuses: [MR_STATUS.DRAFT] },
  { key: 'approval',  label: 'Approval',  sub: 'L1 & L2',    icon: ShieldCheck, color: '#f59e0b', statuses: [MR_STATUS.L1_PENDING, MR_STATUS.L2_PENDING] },
  { key: 'hr',        label: 'HR Review', sub: 'Queue · JD', icon: Inbox,       color: '#0ea5e9', statuses: [MR_STATUS.READY_FOR_HR, MR_STATUS.CONVERTED_TO_JD] },
  { key: 'published', label: 'Published', sub: 'Recruiting', icon: Rocket,      color: '#10b981', statuses: [MR_STATUS.JOB_POSTED, MR_STATUS.HIRING_IN_PROGRESS, MR_STATUS.CLOSED] },
]
const wf4Count = (stats, key) => ({
  request:   stats.draft || 0,
  approval:  (stats.l1_pending || 0) + (stats.l2_pending || 0),
  hr:        (stats.ready_for_hr || 0) + (stats.converted_to_jd || 0),
  published: (stats.job_posted || 0) + (stats.hiring || 0) + (stats.closed || 0),
}[key] || 0)
const wf4IndexOf = (status) => WF4.findIndex(s => s.statuses.includes(wf4Canon(status)))
const wf4Date = (req, key) => ({
  request:   req.submitted_at || req.created_at,
  approval:  req.l2_approved_at || req.l1_approved_at,
  hr:        req.converted_at || req.posted_at,
  published: req.posted_at,
}[key])
const wf4FmtDate = (d) => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : null

// ── Header pipeline — 4 premium steps with live aggregate counts ────────────
function WorkflowPipeline({ stats = {}, activeStatus = 'All', onStage }) {
  const activeIdx = wf4IndexOf(activeStatus)   // -1 unless a stage/status is active
  return (
    <div style={{ display: 'flex', alignItems: 'center', width: 'max-content', minWidth: '100%', gap: 0 }}>
      {WF4.map((s, i) => {
        const Icon = s.icon
        const count = wf4Count(stats, s.key)
        const selected = activeStatus === s.key || (activeIdx >= 0 && activeIdx === i)
        const lit = count > 0 || selected
        return (
          <div key={s.key} style={{ display: 'flex', alignItems: 'center', flex: 1, minWidth: 150 }}>
            <button type="button" onClick={() => onStage?.(selected ? 'All' : s.key)} title={`${count} in ${s.label} — click to filter`}
              className={`mr-node${lit ? ' mr-active' : ''}`}
              style={{
                '--glow': s.color, flex: 1, display: 'flex', alignItems: 'center', gap: 10, padding: '10px 14px', borderRadius: 16, cursor: 'pointer',
                background: lit ? `linear-gradient(135deg, ${s.color}26, ${s.color}12)` : 'var(--bg-input)',
                border: `1.5px solid ${selected ? s.color : lit ? s.color + '55' : 'var(--border)'}`,
                opacity: lit ? 1 : 0.55, boxShadow: selected ? `0 6px 20px ${s.color}44` : 'none', transition: 'all .2s ease',
              }}>
              <span style={{ width: 34, height: 34, borderRadius: 11, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `linear-gradient(135deg, ${s.color}, ${s.color}bb)`, color: '#fff', boxShadow: lit ? `0 3px 14px ${s.color}77` : 'none', flexShrink: 0 }}>
                <Icon size={16} />
              </span>
              <span style={{ display: 'flex', flexDirection: 'column', lineHeight: 1.2, textAlign: 'left', minWidth: 0 }}>
                <span style={{ fontSize: 9, fontWeight: 800, letterSpacing: '0.06em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>Step {i + 1}</span>
                <span style={{ fontSize: 13.5, fontWeight: 800, color: 'var(--text-h)', whiteSpace: 'nowrap' }}>{s.label}</span>
                <span style={{ fontSize: 9.5, fontWeight: 700, letterSpacing: '0.04em', textTransform: 'uppercase', color: s.color }}>{s.sub}</span>
              </span>
              <span style={{ marginLeft: 'auto', minWidth: 24, height: 24, padding: '0 7px', borderRadius: 999, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 12, fontWeight: 800, color: count > 0 ? '#fff' : 'var(--text-muted)', background: count > 0 ? s.color : 'var(--bg-card)', border: count > 0 ? 'none' : '1px solid var(--border)', flexShrink: 0 }}>{count}</span>
            </button>
            {i < WF4.length - 1 && (
              <div className={`mr-flow${lit ? '' : ' mr-flow-dim'}`} style={{ width: 28, height: 3, borderRadius: 4, margin: '0 4px', flexShrink: 0, background: `linear-gradient(90deg, ${s.color}, ${WF4[i + 1].color})` }} />
            )}
          </div>
        )
      })}
    </div>
  )
}

// ── Per-request stepper — linear 4-step progress with completed dates ───────
function WorkflowStepper({ request, status }) {
  const st = request?.status ?? status
  if (st === MR_STATUS.REJECTED) {
    return (
      <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11, color: '#ef4444' }}>
        <XCircle size={13} /> Rejected
      </div>
    )
  }
  const currentIdx = Math.max(0, wf4IndexOf(st))
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 4, flexWrap: 'wrap' }}>
      {WF4.map((step, i) => {
        const done = i < currentIdx, active = i === currentIdx
        const date = (done || active) && request ? wf4FmtDate(wf4Date(request, step.key)) : null
        return (
          <div key={step.key} style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
            <div title={step.sub} style={{
              display: 'flex', alignItems: 'center', gap: 5, fontSize: 10.5, fontWeight: 700, padding: '3px 10px', borderRadius: 999, transition: 'all .2s ease',
              background: done ? 'rgba(16,185,129,0.15)' : active ? `linear-gradient(135deg, ${step.color}33, ${step.color}18)` : 'var(--bg-input)',
              color: done ? '#10b981' : active ? step.color : 'var(--text-muted)',
              border: `1px solid ${done ? 'rgba(16,185,129,0.35)' : active ? step.color + '66' : 'var(--border)'}`,
              opacity: (!done && !active) ? 0.6 : 1,
            }}>
              {done ? <CheckCircle size={11} /> : active ? <Clock size={11} /> : <div style={{ width: 7, height: 7, borderRadius: '50%', background: 'currentColor', opacity: 0.4 }} />}
              {step.label}
              {date && <span style={{ fontSize: 9, fontWeight: 600, opacity: 0.85 }}>· {date}</span>}
            </div>
            {i < WF4.length - 1 && <div style={{ width: 14, height: 2, borderRadius: 2, background: done ? 'rgba(16,185,129,0.4)' : 'var(--border)' }} />}
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
  const [view, setView]         = useState('all')       // all | approvals | queue (filter tabs)
  // Card / List view (SPK-1) — persisted per user, like the Candidates module.
  const [viewMode, setViewMode] = useState(() => localStorage.getItem('hr_manpower_view') || 'card')
  const changeViewMode = (v) => { setViewMode(v); localStorage.setItem('hr_manpower_view', v) }
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
  const navigate = useNavigate()

  // SPK-1: open the existing Job Posting form pre-filled from an approved request
  // (reuses the Job Postings create form + AI JD; no blank form).
  const openPostJob = async (r) => {
    const skills = Array.isArray(r.required_skills) ? r.required_skills.join(', ') : (r.required_skills || '')
    // The standard JD (SPK-1) is built server-side from this requisition — fetch it
    // so "Post Job" and "Convert to JD" produce identical text from one template.
    // Falls back to the request's own description if the call fails.
    let description = r.job_description || r.justification || ''
    try {
      const full = await hrApi.manpower.get(r.id)
      description = (full?.data ?? full)?.standard_jd || description
    } catch { /* keep the fallback */ }

    navigate('/app/hr/jobs', { state: { prefill: {
      manpower_request_id: r.id,
      title: r.position_title || '',
      department: r.department || '',
      location: r.location || '',
      job_type: r.job_type || 'Full-time',
      number_of_openings: r.number_of_posts || 1,
      salary_from: r.salary_min ?? '',
      salary_to: r.salary_max ?? '',
      description,
      requirements: [skills ? 'Skills: ' + skills : '', r.experience_required ? 'Experience: ' + r.experience_required : ''].filter(Boolean).join('\n'),
      closing_date: r.target_joining_date?.slice(0, 10) || '',
      posting_type: 'Both',
    } } })
  }

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
    // filterStatus is either a single status (status dropdown) or a 4-step stage
    // key (header pipeline). Both are matched here — purely presentational.
    const matchStatus = filterStatus === 'All'
      || r.status === filterStatus
      || !!WF4.find(s => s.key === filterStatus)?.statuses.includes(wf4Canon(r.status))
    return matchSearch && matchStatus
  })

  // ── Create / edit ──────────────────────────────────────────────────────────
  const openCreate = () => { setEditingId(null); setForm(EMPTY_FORM); setShowModal(true) }
  const openEdit = (r) => {
    setEditingId(r.id)
    const list = v => Array.isArray(v) ? v.join(', ') : (v || '')
    setForm({
      ...EMPTY_FORM, ...r,
      salary_min: r.salary_min ?? '', salary_max: r.salary_max ?? '', budget: r.budget ?? '',
      required_skills: list(r.required_skills),
      preferred_skills: list(r.preferred_skills),
      certifications: list(r.certifications),
      // Coerce nullable ids to '' so the dropdowns stay controlled.
      hiring_manager_id: r.hiring_manager_id ?? '',
      replacement_employee_id: r.replacement_employee_id ?? '',
      work_mode: r.work_mode ?? '', shift: r.shift ?? '', hiring_reason: r.hiring_reason ?? '', cost_center: r.cost_center ?? '',
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
    p.budget     = p.budget === '' ? null : Number(p.budget)
    const toList = v => (typeof v === 'string' ? v.split(',').map(s => s.trim()).filter(Boolean) : (v || []))
    p.required_skills  = toList(form.required_skills)
    p.preferred_skills = toList(form.preferred_skills)
    p.certifications   = toList(form.certifications)
    Object.keys(p).forEach(k => { if (p[k] === '') p[k] = null })
    return p
  }

  // Frontend validation mirrors the backend rules (backend stays authoritative).
  const validateForm = () => {
    if (!form.department || !form.position_title) return 'Department and Job Title are required.'
    if (Number(form.number_of_posts) < 1) return 'Number of Positions must be at least 1.'
    if (form.salary_min !== '' && form.salary_max !== '' && Number(form.salary_min) > Number(form.salary_max))
      return 'Salary Max cannot be less than Salary Min.'
    if (form.required_by_date && new Date(form.required_by_date) < new Date(new Date().toDateString()))
      return 'Required By date cannot be earlier than today.'
    if (form.hiring_reason === 'Replacement' && !form.replacement_employee_id)
      return 'Select the employee being replaced.'
    return null
  }

  // mode: 'draft' saves only; 'submit' also sends it for approval.
  const handleSave = async (mode = 'draft') => {
    const err = validateForm()
    if (err) { alert(err); return }
    setSaving(true)
    try {
      let saved
      if (editingId) saved = await hrApi.manpower.update(editingId, buildPayload())
      else saved = await hrApi.manpower.create(buildPayload())
      const id = editingId || saved?.id || saved?.data?.id
      if (mode === 'submit' && id) await hrApi.manpower.submit(id)
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
      else if (action === 'send-back')  await hrApi.manpower.sendBack(id, remarks)
      else if (action === 'publish')    await hrApi.manpower.publish(id)
      else if (action === 'close')      await hrApi.manpower.close(id, remarks)
      else if (action === 'delete')     await hrApi.manpower.delete(id)
      setActionModal(null); setRemarks(''); fetchAll()
    } catch (e) { alert(e?.response?.data?.message || 'Action failed') }
    finally { setActionLoading(false) }
  }

  const openAction = (request, action) => { setActionModal({ request, action }); setRemarks('') }

  // Open the details modal — show the list row immediately, then enrich it with
  // the full record (which includes the audit_logs timeline) from the API.
  const openDetail = async (req) => {
    setDetail(req)
    try {
      const res = await hrApi.manpower.get(req.id)
      const full = res?.data ?? res
      if (full?.id) setDetail(full)
    } catch (e) { /* keep the list-row data if the fetch fails */ }
  }

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
          {/* Card / List view toggle (SPK-1) */}
          <div style={{ display: 'flex', borderRadius: 10, overflow: 'hidden', border: '1px solid var(--border)' }}>
            {[['card', LayoutGrid, 'Card'], ['list', List, 'List']].map(([v, Icon, label]) => (
              <button key={v} onClick={() => changeViewMode(v)} title={`${label} view`}
                style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 12px', border: 'none', cursor: 'pointer', fontSize: 12.5, fontWeight: 700,
                  background: viewMode === v ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', color: viewMode === v ? '#fff' : 'var(--text-muted)' }}>
                <Icon size={13} /> {label}
              </button>
            ))}
          </div>
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
        <HrLoading label="Loading requests…" />
      ) : filtered.length === 0 ? (
        <HrEmpty icon={FileText} title="No manpower requests found" hint="Raise a manpower request to kick off the recruitment workflow." />
      ) : viewMode === 'list' ? (
        <ManpowerListView rows={filtered} onView={openDetail} onEdit={openEdit} isOwner={isOwner} openAction={openAction} openConvert={openConvert} manageHr={manageHr} approveL1={approveL1} approveL2={approveL2} />
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
                  <WorkflowStepper request={req} />
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
                    <ActBtn onClick={() => openAction(req, 'submit')} icon={Send} color="#a78bfa" bg="rgba(124,58,237,0.15)">Submit</ActBtn>
                  )}
                  {req.status === MR_STATUS.L1_PENDING && approveL1 && (
                    <>
                      <div style={{ display: 'flex', gap: 6 }}>
                        <ActBtn onClick={() => openAction(req, 'approve-l1')} icon={ThumbsUp} color="#10b981" bg="rgba(16,185,129,0.15)">L1 Approve</ActBtn>
                        <ActBtn onClick={() => openAction(req, 'reject-l1')} icon={ThumbsDown} color="#f87171" bg="rgba(239,68,68,0.1)">Reject</ActBtn>
                      </div>
                      <ActBtn onClick={() => openAction(req, 'send-back')} icon={CornerUpLeft} color="#f59e0b" bg="rgba(245,158,11,0.12)">Send Back</ActBtn>
                    </>
                  )}
                  {req.status === MR_STATUS.L2_PENDING && approveL2 && (
                    <>
                      <div style={{ display: 'flex', gap: 6 }}>
                        <ActBtn onClick={() => openAction(req, 'approve-l2')} icon={ThumbsUp} color="#10b981" bg="rgba(16,185,129,0.15)">L2 Approve</ActBtn>
                        <ActBtn onClick={() => openAction(req, 'reject-l2')} icon={ThumbsDown} color="#f87171" bg="rgba(239,68,68,0.1)">Reject</ActBtn>
                      </div>
                      <ActBtn onClick={() => openAction(req, 'send-back')} icon={CornerUpLeft} color="#f59e0b" bg="rgba(245,158,11,0.12)">Send Back</ActBtn>
                    </>
                  )}
                  {req.status === MR_STATUS.READY_FOR_HR && manageHr && (
                    <>
                      <ActBtn onClick={() => openConvert(req)} icon={Briefcase} color="#6366f1" bg="rgba(99,102,241,0.15)">Convert to JD</ActBtn>
                      <ActBtn onClick={() => openPostJob(req)} icon={Rocket} color="#10b981" bg="rgba(16,185,129,0.15)">Post Job</ActBtn>
                    </>
                  )}
                  {req.status === MR_STATUS.CONVERTED_TO_JD && manageHr && (
                    <ActBtn onClick={() => openAction(req, 'publish')} icon={Rocket} color="#10b981" bg="rgba(16,185,129,0.15)">Publish Job</ActBtn>
                  )}
                  {[MR_STATUS.JOB_POSTED, MR_STATUS.HIRING_IN_PROGRESS].includes(req.status) && manageHr && (
                    <ActBtn onClick={() => openAction(req, 'close')} icon={Lock} color="#94a3b8" bg="rgba(100,116,139,0.12)">Close</ActBtn>
                  )}
                  {[MR_STATUS.DRAFT, MR_STATUS.REJECTED].includes(req.status) && isOwner(req) && (
                    <ActBtn onClick={() => openEdit(req)} icon={Pencil} color="var(--text-muted)" bg="var(--bg-card)" border>Edit</ActBtn>
                  )}
                  <ActBtn onClick={() => openDetail(req)} icon={Eye} color="var(--text-muted)" bg="var(--bg-card)" border>View</ActBtn>
                  {[MR_STATUS.DRAFT, MR_STATUS.REJECTED].includes(req.status) && isOwner(req) && (
                    <ActBtn onClick={() => openAction(req, 'delete')} icon={Trash2} color="#f87171" bg="var(--bg-card)" border>Delete</ActBtn>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {showModal && <RequestFormModal {...{ form, setForm, editingId, saving, requestedBy: user?.name, onClose: () => setShowModal(false), onSave: handleSave }} />}
      {actionModal && actionModal.action === 'publish'
        ? <PublishModal request={actionModal.request} onClose={() => setActionModal(null)} onPublished={() => { setActionModal(null); fetchAll() }} />
        : actionModal && <ActionModal {...{ actionModal, remarks, setRemarks, actionLoading, onClose: () => setActionModal(null), onConfirm: runAction }} />}
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
// Native select. `options` are plain strings, or [value, label] pairs when `pairs`.
const SelectInput = ({ options, pairs, ...p }) => (
  <select {...p} style={{ ...inputStyle, cursor: 'pointer' }}>
    {options.map(o => pairs
      ? <option key={o[0]} value={o[0]}>{o[1]}</option>
      : <option key={o} value={o}>{o}</option>)}
  </select>
)

// ── Request create/edit modal ────────────────────────────────────────────────
// Section header for the enterprise Manpower Request form (SPK-1).
const Section = ({ n, title, children }) => (
  <div style={{ marginBottom: 20 }}>
    <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
      <span style={{ width: 22, height: 22, borderRadius: 7, background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontSize: 11, fontWeight: 800, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>{n}</span>
      <h3 style={{ margin: 0, fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>{title}</h3>
      <div style={{ flex: 1, height: 1, background: 'var(--border)' }} />
    </div>
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 14 }}>{children}</div>
  </div>
)
const ReadOnly = ({ label, value }) => (
  <Field label={label}><div style={{ ...inputStyle, background: 'var(--bg-card, var(--bg-input))', color: 'var(--text-muted)', display: 'flex', alignItems: 'center' }}>{value || '—'}</div></Field>
)

function RequestFormModal({ form, setForm, editingId, saving, requestedBy, onClose, onSave }) {
  const set = (k) => (e) => setForm(p => ({ ...p, [k]: e.target.value }))
  const setV = (k) => (v) => setForm(p => ({ ...p, [k]: v }))

  // Lookups — all loaded from the database (projects + business units, departments,
  // hiring managers). Nothing hardcoded.
  const [projects, setProjects] = useState([])
  const [opts, setOpts] = useState({ business_units: [], departments: [], departments_by_bu: {}, hiring_managers: [] })
  const [loading, setLoading] = useState(true)
  useEffect(() => {
    Promise.all([hrApi.manpower.projects(), hrApi.manpower.formOptions()])
      .then(([p, o]) => {
        setProjects(Array.isArray(p?.data ?? p) ? (p.data ?? p) : [])
        setOpts(o?.data ?? o ?? {})
      })
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [])

  // Department options filter by the chosen Business Unit (from real history);
  // fall back to all departments when the BU has no recorded departments.
  const deptOptions = (form.business_unit && opts.departments_by_bu?.[form.business_unit]?.length)
    ? opts.departments_by_bu[form.business_unit]
    : opts.departments
  const managers = opts.hiring_managers || []
  const managerName = (id) => managers.find(m => String(m.id) === String(id))?.name || ''
  const today = new Date().toISOString().slice(0, 10)
  const isReplacement = form.hiring_reason === 'Replacement'

  return (
    <Overlay onClose={onClose} width={1120}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>{editingId ? 'Edit' : 'New'} Manpower Request</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 18px' }}>Complete the requisition in sections. Fields marked * are required.</p>

      {/* ── Section 1 — Basic Information ── */}
      <Section n={1} title="Basic Information">
        <Field label="Business Unit"><SearchableSelect value={form.business_unit} onChange={setV('business_unit')} options={opts.business_units || []} loading={loading} placeholder="Select business unit…" emptyText="No business units yet" allowCreate /></Field>
        <Field label="Department *"><SearchableSelect value={form.department} onChange={setV('department')} options={deptOptions || []} loading={loading} placeholder="Select department…" emptyText="No departments yet" allowCreate /></Field>
        <Field label="Project"><SearchableSelect value={form.project} onChange={setV('project')} options={projects} loading={loading} placeholder="Select or search project…" emptyText="No Projects Found" allowCreate /></Field>
        <Field label="Project Location"><TextInput value={form.location} onChange={set('location')} placeholder="e.g. Pune" /></Field>
        <Field label="Hiring Manager"><SelectInput value={form.hiring_manager_id} onChange={set('hiring_manager_id')} options={[['', 'Select manager…'], ...managers.map(m => [String(m.id), m.label])]} pairs /></Field>
        <ReadOnly label="Request Date" value={editingId ? undefined : today} />
        <ReadOnly label="Requested By" value={requestedBy || 'Current user'} />
      </Section>

      {/* ── Section 2 — Position Details ── */}
      <Section n={2} title="Position Details">
        <Field label="Job Title *"><TextInput value={form.position_title} onChange={set('position_title')} placeholder="e.g. Senior Developer" /></Field>
        <Field label="Employee Level"><SelectInput value={form.employee_level} onChange={set('employee_level')} options={['', ...EMPLOYEE_LEVELS]} /></Field>
        <Field label="Employment Type"><SelectInput value={form.job_type} onChange={set('job_type')} options={EMPLOYMENT_TYPES} /></Field>
        <Field label="Work Mode"><SelectInput value={form.work_mode} onChange={set('work_mode')} options={['', ...WORK_MODES]} /></Field>
        <Field label="Shift"><SelectInput value={form.shift} onChange={set('shift')} options={['', ...SHIFTS]} /></Field>
        <Field label="No. of Positions *"><TextInput type="number" min="1" value={form.number_of_posts} onChange={set('number_of_posts')} /></Field>
        <Field label="Experience Required"><TextInput value={form.experience_required} onChange={set('experience_required')} placeholder="e.g. 3-5 years" /></Field>
        <Field label="Required By Date"><TextInput type="date" min={today} value={form.required_by_date} onChange={set('required_by_date')} /></Field>
        <Field label="Expected Joining Date"><TextInput type="date" min={today} value={form.target_joining_date} onChange={set('target_joining_date')} /></Field>
      </Section>

      {/* ── Section 3 — Compensation & Skills ── */}
      <Section n={3} title="Compensation & Skills">
        <Field label="Salary Min"><TextInput type="number" min="0" value={form.salary_min} onChange={set('salary_min')} placeholder="e.g. 1200000" /></Field>
        <Field label="Salary Max"><TextInput type="number" min="0" value={form.salary_max} onChange={set('salary_max')} placeholder="e.g. 1800000" /></Field>
        <Field label="Budget (optional)"><TextInput type="number" min="0" value={form.budget} onChange={set('budget')} placeholder="Total hiring budget" /></Field>
        <Field label="Required Skills (comma-separated)" full><TextInput value={form.required_skills} onChange={set('required_skills')} placeholder="e.g. PHP, Laravel, React" /></Field>
        <Field label="Preferred Skills (comma-separated)" full><TextInput value={form.preferred_skills} onChange={set('preferred_skills')} placeholder="e.g. Docker, AWS — good to have" /></Field>
        <Field label="Education"><TextInput value={form.education} onChange={set('education')} placeholder="e.g. B.Tech" /></Field>
        <Field label="Certifications (comma-separated)"><TextInput value={form.certifications} onChange={set('certifications')} placeholder="e.g. AWS, PMP" /></Field>
      </Section>

      {/* ── Section 4 — Hiring Details ── */}
      <Section n={4} title="Hiring Details">
        <Field label="Hiring Reason"><SelectInput value={form.hiring_reason} onChange={set('hiring_reason')} options={['', ...HIRING_REASONS]} /></Field>
        <Field label="Priority"><SelectInput value={form.priority} onChange={set('priority')} options={PRIORITIES} /></Field>
        <Field label="Criticality"><SelectInput value={form.criticality} onChange={set('criticality')} options={['', ...CRITICALITY]} /></Field>
        {isReplacement && <Field label="Replacement Employee *"><SelectInput value={form.replacement_employee_id} onChange={set('replacement_employee_id')} options={[['', 'Select employee…'], ...managers.map(m => [String(m.id), m.label])]} pairs /></Field>}
        {isReplacement && <Field label="Cost Center (optional)"><TextInput value={form.cost_center} onChange={set('cost_center')} placeholder="e.g. CC-1024" /></Field>}
      </Section>

      {/* ── Section 5 — Job Description ── */}
      <Section n={5} title="Job Description">
        <Field label="Job Description" full><textarea value={form.job_description} onChange={set('job_description')} rows={3} placeholder="Role summary and responsibilities (auto-formatted into a standard JD when converted)" style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        <Field label="Additional Remarks / Justification" full><textarea value={form.justification} onChange={set('justification')} rows={2} placeholder="Why is this position required?" style={{ ...inputStyle, resize: 'vertical' }} /></Field>
      </Section>

      {/* Sticky footer — Save Draft · Submit · Cancel */}
      <div style={{ position: 'sticky', bottom: -28, margin: '4px -28px -28px', padding: '14px 28px', background: 'var(--bg-card, var(--bg-input))', borderTop: '1px solid var(--border)', display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
        <button onClick={onClose} disabled={saving} style={{ padding: '9px 16px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-muted)', fontWeight: 700, fontSize: 13, cursor: 'pointer' }}>Cancel</button>
        <button onClick={() => onSave('draft')} disabled={saving} style={{ padding: '9px 16px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontWeight: 700, fontSize: 13, cursor: 'pointer' }}>Save Draft</button>
        <button onClick={() => onSave('submit')} disabled={saving} style={{ padding: '9px 18px', borderRadius: 10, border: 'none', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 800, fontSize: 13, cursor: 'pointer', opacity: saving ? 0.7 : 1 }}>{saving ? 'Saving…' : 'Submit for Approval'}</button>
      </div>
    </Overlay>
  )
}

// Count-up animation for the improved ATS score.
function AnimatedNumber({ to, from = 0, duration = 900 }) {
  const [v, setV] = useState(from)
  useEffect(() => {
    let raf, start
    const step = (t) => { if (!start) start = t; const p = Math.min(1, (t - start) / duration); setV(Math.round(from + (to - from) * p)); if (p < 1) raf = requestAnimationFrame(step) }
    raf = requestAnimationFrame(step)
    return () => { if (raf) cancelAnimationFrame(raf) }
  }, [to, from, duration])
  return <>{v}%</>
}

const ATS_THRESHOLD = 70
const cmpLbl = { fontSize: 10, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.03em', margin: '0 0 4px' }
const cmpBox = { maxHeight: 300, overflowY: 'auto', whiteSpace: 'pre-wrap', fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.55, padding: 10, background: 'var(--bg-input)', borderRadius: 8 }
const pubBtn = (extra = {}) => ({ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 15px', borderRadius: 9, border: 'none', cursor: 'pointer', fontSize: 13, fontWeight: 700, ...extra })

// ── Publish Job — enterprise ATS workflow (reuses AI generator + JD analyzer) ─
function PublishModal({ request, onClose, onPublished }) {
  const jobId = request.job_posting_id
  const [loading, setLoading] = useState(true)
  const [original, setOriginal] = useState('')
  const [working, setWorking] = useState('')
  const [report, setReport] = useState(null)
  const [analyzing, setAnalyzing] = useState(false)
  const [atsBefore, setAtsBefore] = useState(null)
  const [improving, setImproving] = useState(false)
  const [improvedOnce, setImprovedOnce] = useState(false)
  const [err, setErr] = useState('')
  const [compare, setCompare] = useState(null)   // { content, previous }
  const [success, setSuccess] = useState(null)   // { old, new }
  const [publishing, setPublishing] = useState(false)

  // Reuses the existing Analyze JD endpoint (adapter over JdAnalyzer).
  const analyze = async (content, silent = true) => {
    if (!content?.trim()) { setReport(null); return null }
    setAnalyzing(true)
    try {
      const r = await hrApi.manpower.analyzeJd(request.id, { title: request.position_title, description: content, silent })
      const rep = r?.data ?? r; setReport(rep); return rep
    } catch { return null } finally { setAnalyzing(false) }
  }

  useEffect(() => {
    let alive = true
    ;(async () => {
      let desc = request.job_description || ''
      try { if (jobId) { const j = await hrApi.jobs.get(jobId); const job = j?.data ?? j; desc = job.description || desc } } catch { /* fall back */ }
      if (!alive) return
      setOriginal(desc); setWorking(desc)
      const rep = await analyze(desc, true)
      if (!alive) return
      setAtsBefore(rep?.ats_score ?? null)
      setLoading(false)
    })()
    return () => { alive = false }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const ats = report?.ats_score
  const needsWork = report != null && ats < ATS_THRESHOLD

  // Reuses the existing Generate AI JD endpoint in improvement mode.
  const improve = async () => {
    setImproving(true); setErr(''); setSuccess(null)
    try {
      const r = await hrApi.manpower.generateJd(request.id, { improve: true, current_jd: working, ats_before: ats })
      const d = r?.data ?? r
      setCompare({ content: d?.draft?.content || '', previous: d?.previous?.content || null })
    } catch (e) { setErr(e?.response?.data?.message || 'AI improvement failed.') } finally { setImproving(false) }
  }

  const applyCompare = async (mode) => {
    const next = mode === 'append' && working ? `${working}\n\n${compare.content}` : compare.content
    setWorking(next); setCompare(null); setImprovedOnce(true)
    const rep = await analyze(next, false)
    const newAts = rep?.ats_score ?? ats
    hrApi.manpower.jdImprovementDecision(request.id, 'accepted', atsBefore ?? ats, newAts).catch(() => {})
    if (newAts >= ATS_THRESHOLD) setSuccess({ old: atsBefore ?? ats, new: newAts })
  }
  const rejectCompare = () => {
    setCompare(null)
    hrApi.manpower.jdImprovementDecision(request.id, 'rejected', atsBefore, null).catch(() => {})
  }

  const publish = async () => {
    setPublishing(true); setErr('')
    try {
      if (jobId && working !== original) await hrApi.jobs.update(jobId, { description: working })
      await hrApi.manpower.publish(request.id)
      onPublished()
    } catch (e) { setErr(e?.response?.data?.message || 'Publish failed'); setPublishing(false) }
  }

  return (
    <Overlay onClose={() => !publishing && !improving && onClose()} width={compare ? 980 : 640}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6 }}>
        <Rocket size={20} color="#10b981" />
        <h3 style={{ color: 'var(--text-h)', margin: 0, fontSize: 16, fontWeight: 800 }}>Publish Job</h3>
      </div>
      <p style={{ color: 'var(--text-muted)', fontSize: 12.5, marginBottom: 14 }}><strong style={{ color: 'var(--text-h)' }}>MR-{request.id} · {request.position_title}</strong> — {request.department}</p>

      {loading ? (
        <p style={{ fontSize: 13, color: '#a78bfa', display: 'inline-flex', alignItems: 'center', gap: 8, padding: '10px 0' }}><Loader2 size={15} className="animate-spin" /> Checking JD quality…</p>
      ) : compare ? (
        <>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8, flexWrap: 'wrap', gap: 8 }}>
            <span style={{ fontSize: 12.5, fontWeight: 800, color: '#a78bfa', display: 'inline-flex', alignItems: 'center', gap: 6 }}><Sparkles size={14} /> AI Improvement — compare</span>
            <span style={{ fontSize: 11, color: '#f59e0b' }}>Nothing is saved until you Publish.</span>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
            <div><p style={cmpLbl}>Current JD</p><div style={cmpBox}>{working || '—'}</div></div>
            <div><p style={{ ...cmpLbl, color: '#a78bfa' }}>Improved AI JD</p><div style={{ ...cmpBox, color: 'var(--text-h)' }}>{compare.content}</div></div>
          </div>
          <div style={{ display: 'flex', gap: 8, marginTop: 14, justifyContent: 'flex-end' }}>
            <button type="button" onClick={rejectCompare} style={pubBtn({ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' })}>Cancel</button>
            <button type="button" onClick={() => applyCompare('append')} style={pubBtn({ background: 'rgba(124,58,237,0.12)', color: '#a78bfa', border: '1px solid rgba(124,58,237,0.4)' })}>Append</button>
            <button type="button" onClick={() => applyCompare('replace')} style={pubBtn({ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff' })}>Replace JD</button>
          </div>
        </>
      ) : (
        <>
          {success && (
            <div className="animate-fade-in" style={{ marginBottom: 14, padding: '12px 14px', borderRadius: 10, background: 'rgba(16,185,129,0.12)', border: '1px solid rgba(16,185,129,0.4)', display: 'flex', alignItems: 'center', gap: 12 }}>
              <CheckCircle size={24} color="#10b981" />
              <div>
                <p style={{ fontSize: 13.5, fontWeight: 800, color: '#10b981', margin: 0 }}>ATS Improved</p>
                <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '2px 0 0' }}>Old Score <b style={{ color: 'var(--text-h)' }}>{success.old}%</b> <TrendingUp size={13} style={{ display: 'inline', verticalAlign: -2, margin: '0 4px', color: '#10b981' }} /> New Score <b style={{ color: '#10b981', fontSize: 16 }}><AnimatedNumber from={success.old} to={success.new} /></b></p>
              </div>
            </div>
          )}

          <JdScorecard report={report} analyzing={analyzing} onReanalyze={() => analyze(working, false)} />

          {needsWork && !success && (
            <div style={{ marginTop: 12, border: '1px solid rgba(239,68,68,0.4)', borderRadius: 10, padding: 12, background: 'rgba(239,68,68,0.06)' }}>
              <p style={{ fontSize: 12.5, fontWeight: 800, color: '#f87171', margin: '0 0 6px', display: 'inline-flex', alignItems: 'center', gap: 6 }}><XCircle size={14} /> JD Quality Issues — ATS {ats}% · Overall {report.overall_score}%</p>
              {report.warnings?.length > 0 && <ul style={{ margin: '4px 0 0', paddingLeft: 18, fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.6 }}>{report.warnings.map((w, i) => <li key={i}>{w}</li>)}</ul>}
            </div>
          )}
          {err && <p style={{ fontSize: 12, color: '#f87171', margin: '10px 0 0' }}>{err}</p>}

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 18, flexWrap: 'wrap' }}>
            <button type="button" onClick={onClose} disabled={publishing || improving} style={pubBtn({ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' })}>Cancel</button>
            {needsWork && !success && (
              <button type="button" onClick={improve} disabled={improving} style={pubBtn({ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', opacity: improving ? 0.7 : 1 })}>
                {improving ? <Loader2 size={14} className="animate-spin" /> : <Sparkles size={14} />} {improving ? 'Improving…' : improvedOnce ? 'Improve Again' : 'Improve with AI'}
              </button>
            )}
            <button type="button" onClick={publish} disabled={publishing || improving} style={pubBtn({ background: '#10b981', color: '#fff', opacity: publishing ? 0.7 : 1 })}>
              {publishing ? <Loader2 size={14} className="animate-spin" /> : <Rocket size={14} />} {needsWork && !success ? 'Publish Anyway' : 'Publish'}
            </button>
          </div>
        </>
      )}
    </Overlay>
  )
}

// ── Approve / reject / publish / close modal ─────────────────────────────────
function ActionModal({ actionModal, remarks, setRemarks, actionLoading, onClose, onConfirm }) {
  const { action, request } = actionModal
  const isReject = action.startsWith('reject')
  const isSendBack = action === 'send-back'
  const isDelete = action === 'delete'
  const needsReason = isReject || isSendBack
  const meta = {
    submit: { title: 'Submit for Approval', color: '#7C3AED' },
    'approve-l1': { title: 'Approve L1 (Department Head)', color: '#10b981' },
    'reject-l1': { title: 'Reject at L1', color: '#ef4444' },
    'approve-l2': { title: 'Approve L2 (Management)', color: '#10b981' },
    'reject-l2': { title: 'Reject at L2', color: '#ef4444' },
    'send-back': { title: 'Send Back for Revision', color: '#f59e0b' },
    publish: { title: 'Publish Job', color: '#10b981' },
    close: { title: 'Close Position', color: '#94a3b8' },
    delete: { title: 'Delete Request', color: '#ef4444' },
  }[action]
  const showRemarks = !['submit', 'publish', 'delete'].includes(action)
  return (
    <Overlay onClose={() => !actionLoading && onClose()} width={460}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 }}>
        {isSendBack ? <CornerUpLeft size={22} color={meta.color} />
          : isReject || isDelete ? <XCircle size={22} color={meta.color} />
          : <CheckCircle size={22} color={meta.color} />}
        <h3 style={{ color: 'var(--text-h)', margin: 0, fontSize: 16, fontWeight: 800 }}>{meta.title}</h3>
      </div>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, marginBottom: 14 }}>
        <strong style={{ color: 'var(--text-h)' }}>MR-{request.id} · {request.position_title}</strong> — {request.department}
      </p>
      {action === 'submit' && <InfoBox><strong>L1 is approved automatically</strong> on submission — the request goes straight to <strong>Management (L2)</strong> approval, then the HR queue.</InfoBox>}
      {action === 'delete' && <InfoBox tone="danger">This permanently deletes the request. This cannot be undone.</InfoBox>}
      {showRemarks && (
        <>
          <label style={labelStyle}>{isSendBack ? 'Reason to send back *' : needsReason ? 'Reason for Rejection *' : 'Remarks (optional)'}</label>
          <textarea value={remarks} onChange={e => setRemarks(e.target.value)} rows={3} placeholder={isSendBack ? 'What needs to be revised?' : needsReason ? 'Enter reason...' : 'Add remarks...'}
            style={{ ...inputStyle, resize: 'vertical', borderColor: needsReason && !remarks ? '#ef444480' : 'var(--border)' }} />
        </>
      )}
      <ModalFooter onClose={onClose} onConfirm={onConfirm} loading={actionLoading} disabled={needsReason && !remarks} confirmLabel="Confirm" color={meta.color} />
    </Overlay>
  )
}

// ── Convert to JD modal (HR edits missing info) ──────────────────────────────
// Fallback AI option catalog (mirrors backend JobDescriptionPromptBuilder::OPTIONS);
// the live catalog is fetched from /hr/manpower-requests/jd-templates.
const DEFAULT_AI_OPTIONS = {
  tone: ['Professional', 'Corporate', 'Startup', 'Technical', 'Executive'],
  industry: ['IT', 'Healthcare', 'Finance', 'Manufacturing', 'Retail', 'Telecom', 'Education'],
  experience_level: ['Intern', 'Junior', 'Mid', 'Senior', 'Lead'],
  employment_type: ['Full Time', 'Contract', 'Internship', 'Remote', 'Hybrid', 'Onsite'],
}
const scoreColor = (v) => v >= 85 ? '#10b981' : v >= 70 ? '#f59e0b' : '#f87171'

function ScoreChip({ label, value }) {
  return (
    <div style={{ background: 'var(--bg-input)', borderRadius: 9, padding: '8px 10px', textAlign: 'center', minWidth: 74 }}>
      <div style={{ fontSize: 18, fontWeight: 900, color: scoreColor(value) }}>{value}%</div>
      <div style={{ fontSize: 9.5, color: 'var(--text-muted)', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.03em', marginTop: 1 }}>{label}</div>
    </div>
  )
}

// JD Quality scorecard — powered by the existing JD Analyzer (adapter).
function JdScorecard({ report, analyzing, onReanalyze }) {
  if (!report && !analyzing) return null
  if (!report) return <p style={{ fontSize: 12, color: '#a78bfa', display: 'inline-flex', alignItems: 'center', gap: 6, marginTop: 10 }}><Loader2 size={13} className="animate-spin" /> Analyzing…</p>
  const bias = report.bias || { status: 'Pass', flagged: [] }
  const notes = [...(report.warnings || []), ...(report.suggestions || [])].slice(0, 5)
  return (
    <div style={{ marginTop: 10, border: '1px solid var(--border)', borderRadius: 10, padding: 12, background: 'var(--bg-card)' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10, flexWrap: 'wrap', gap: 8 }}>
        <span style={{ fontSize: 12, fontWeight: 800, color: 'var(--text-h)', display: 'inline-flex', alignItems: 'center', gap: 6 }}><ShieldCheck size={14} style={{ color: '#a78bfa' }} /> JD Quality</span>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <span style={{ fontSize: 12, fontWeight: 900, color: scoreColor(report.overall_score) }}>Overall {report.overall_score}%</span>
          <button type="button" onClick={onReanalyze} disabled={analyzing} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 7, padding: '4px 9px', cursor: 'pointer' }}>{analyzing ? <Loader2 size={11} className="animate-spin" /> : <RefreshCw size={11} />} Re-analyze</button>
        </div>
      </div>
      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
        <ScoreChip label="ATS" value={report.ats_score} />
        <ScoreChip label="SEO" value={report.seo_score} />
        <ScoreChip label="Skills" value={report.skill_coverage} />
        <ScoreChip label="Complete" value={report.completeness} />
        <ScoreChip label="Readable" value={report.readability} />
        <div style={{ background: 'var(--bg-input)', borderRadius: 9, padding: '8px 10px', textAlign: 'center', minWidth: 74 }}>
          <div style={{ fontSize: 15, fontWeight: 900, color: bias.status === 'Pass' ? '#10b981' : '#f59e0b', marginTop: 1 }}>{bias.status}</div>
          <div style={{ fontSize: 9.5, color: 'var(--text-muted)', fontWeight: 700, textTransform: 'uppercase', marginTop: 3 }}>Bias</div>
        </div>
      </div>
      {notes.length > 0 && (
        <ul style={{ margin: '10px 0 0', paddingLeft: 16, fontSize: 11.5, color: 'var(--text-muted)', lineHeight: 1.6 }}>
          {notes.map((s, i) => <li key={i}>{s}</li>)}
        </ul>
      )}
    </div>
  )
}

function ConvertModal({ convertModal, setConvertModal, actionLoading, onClose, onConfirm }) {
  const { request, jd } = convertModal
  const set = (k) => (e) => setConvertModal(p => ({ ...p, jd: { ...p.jd, [k]: e.target.value } }))
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [compare, setCompare] = useState(null)   // { content, source, previous, regen }
  const [aiOpts, setAiOpts] = useState({ tone: '', industry: '', experience_level: '', employment_type: '' })
  const [catalog, setCatalog] = useState({ templates: [], ai_options: DEFAULT_AI_OPTIONS })
  const [tmpl, setTmpl] = useState('')
  const [report, setReport] = useState(null)
  const [analyzing, setAnalyzing] = useState(false)

  useEffect(() => {
    hrApi.manpower.jdTemplates()
      .then(r => { const d = r?.data ?? r; setCatalog({ templates: d.templates || [], ai_options: d.ai_options || DEFAULT_AI_OPTIONS }) })
      .catch(() => {})
  }, [])
  const opt = catalog.ai_options || DEFAULT_AI_OPTIONS

  // Reuses the existing JD Analyzer via the backend adapter. silent=true skips the
  // audit for passive refreshes (auto-analysis after a generation).
  const analyzeContent = async (content, silent = true) => {
    if (!content?.trim()) { setReport(null); return }
    setAnalyzing(true)
    try {
      const r = await hrApi.manpower.analyzeJd(request.id, { title: jd.title, description: content, requirements: jd.requirements, silent })
      setReport(r?.data ?? r)
    } catch { /* analysis is non-blocking */ } finally { setAnalyzing(false) }
  }

  // Provenance (jd_source) flows to the backend via runConvert → audits AI/Template/Manual.
  const putDescription = (content, source, appendMode = 'replace') => {
    const next = appendMode === 'append' && jd.description ? `${jd.description}\n\n${content}` : content
    setConvertModal(p => ({ ...p, jd: { ...p.jd, description: next, jd_source: source } }))
    analyzeContent(next, true)
  }
  const applyGenerated = (content, source, meta = {}) => {
    if (!jd.description?.trim()) putDescription(content, source)                         // empty → apply directly
    else setCompare({ content, source, previous: meta.previous || null, regen: !!meta.regen }) // else compare
  }
  const applyCompare = (appendMode) => { putDescription(compare.content, compare.source, appendMode); setCompare(null) }

  const generateAI = async (regen) => {
    setBusy(true); setErr('')
    try {
      const r = await hrApi.manpower.generateJd(request.id, { regenerate: regen, ...aiOpts })
      const d = r?.data ?? r
      if (regen) setCompare({ content: d?.draft?.content || '', source: 'ai', previous: d?.previous?.content || null, regen: true }) // regenerate always confirms
      else applyGenerated(d?.draft?.content || '', 'ai', { previous: d?.previous?.content || null })
    } catch (e) { setErr(e?.response?.data?.message || 'AI generation failed.') } finally { setBusy(false) }
  }
  const generateTemplate = async () => {
    if (!tmpl) { setErr('Choose a template first.'); return }
    setBusy(true); setErr('')
    try {
      const d = (await hrApi.manpower.templateJd(request.id, tmpl))
      applyGenerated((d?.data ?? d)?.content || '', 'template')
    } catch (e) { setErr(e?.response?.data?.message || 'Template generation failed.') } finally { setBusy(false) }
  }

  const selStyle = { ...inputStyle, padding: '7px 9px', fontSize: 12 }
  const actBtn = (extra = {}) => ({ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 13px', borderRadius: 9, border: '1px solid rgba(124,58,237,0.4)', background: 'rgba(124,58,237,0.1)', color: '#a78bfa', cursor: 'pointer', fontSize: 12.5, fontWeight: 700, ...extra })
  const canRegen = !!jd.description?.trim()

  return (
    <Overlay onClose={() => !actionLoading && onClose()} width={980}>
      <h3 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 17, fontWeight: 800 }}>Convert to Job Description</h3>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>Pre-filled from MR-{request.id}. Generate with AI, use a role template, or write it manually — then create the draft.</p>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
        <Field label="Job Title"><TextInput value={jd.title} onChange={set('title')} /></Field>
        <Field label="Location"><TextInput value={jd.location} onChange={set('location')} /></Field>
        <Field label="Posting Type"><SelectInput value={jd.posting_type} onChange={set('posting_type')} options={['Internal', 'External', 'Both']} /></Field>
        <Field label="Closing Date"><TextInput type="date" value={jd.closing_date} onChange={set('closing_date')} /></Field>

        {/* JD Generation — AI options + three actions */}
        <div style={{ gridColumn: '1/-1', border: '1px solid var(--border)', borderRadius: 12, padding: 14, background: 'var(--bg-input)' }}>
          <p style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-h)', textTransform: 'uppercase', letterSpacing: '0.04em', margin: '0 0 10px' }}>JD Generation</p>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 8, marginBottom: 12 }}>
            {[['tone', 'Tone'], ['industry', 'Industry'], ['experience_level', 'Experience'], ['employment_type', 'Employment']].map(([k, l]) => (
              <div key={k}>
                <label style={{ fontSize: 9.5, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.03em' }}>{l}</label>
                <select value={aiOpts[k]} onChange={e => setAiOpts(o => ({ ...o, [k]: e.target.value }))} style={selStyle}>
                  <option value="">Auto</option>
                  {(opt[k] || []).map(v => <option key={v} value={v}>{v}</option>)}
                </select>
              </div>
            ))}
          </div>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
            <button type="button" disabled={busy} onClick={() => generateAI(false)} style={actBtn({ opacity: busy ? 0.6 : 1 })}>
              {busy ? <Loader2 size={14} className="animate-spin" /> : <Sparkles size={14} />} Generate AI JD
            </button>
            <button type="button" disabled={busy || !canRegen} onClick={() => generateAI(true)} style={actBtn({ opacity: busy || !canRegen ? 0.5 : 1 })}>
              <RefreshCw size={14} /> Regenerate AI JD
            </button>
            <span style={{ width: 1, height: 24, background: 'var(--border)', margin: '0 2px' }} />
            <select value={tmpl} onChange={e => setTmpl(e.target.value)} style={{ ...selStyle, width: 'auto', minWidth: 150 }}>
              <option value="">Choose template…</option>
              {(catalog.templates || []).map(t => <option key={t.key} value={t.key}>{t.label}</option>)}
            </select>
            <button type="button" disabled={busy || !tmpl} onClick={generateTemplate} style={actBtn({ background: 'rgba(59,130,246,0.12)', color: '#60a5fa', border: '1px solid rgba(59,130,246,0.4)', opacity: busy || !tmpl ? 0.5 : 1 })}>
              <FileText size={14} /> Generate from Template
            </button>
            <span style={{ fontSize: 11, color: 'var(--text-muted)', display: 'inline-flex', alignItems: 'center', gap: 4 }}><Pencil size={12} /> or write manually below</span>
          </div>
          {err && <p style={{ fontSize: 12, color: '#f87171', margin: '8px 0 0' }}>{err}</p>}
        </div>

        <Field label="Description" full>
          <textarea value={jd.description} onChange={set('description')} rows={compare ? 5 : 9} style={{ ...inputStyle, resize: 'vertical' }} />
        </Field>

        {/* Comparison — Old vs New */}
        {compare && (
          <div style={{ gridColumn: '1/-1', border: '1px solid rgba(124,58,237,0.45)', borderRadius: 12, padding: 12, background: 'var(--bg-card)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8, flexWrap: 'wrap', gap: 8 }}>
              <span style={{ fontSize: 12, fontWeight: 800, color: '#a78bfa', display: 'inline-flex', alignItems: 'center', gap: 6 }}><Sparkles size={13} /> Compare — new {compare.source === 'template' ? 'template' : 'AI'} version{compare.regen ? ' (regenerated)' : ''}</span>
              <span style={{ fontSize: 11, color: '#f59e0b' }}>Replace, append below, or cancel.</span>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
              <div>
                <p style={{ fontSize: 10, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', margin: '0 0 4px' }}>Current version</p>
                <div style={{ maxHeight: 240, overflowY: 'auto', whiteSpace: 'pre-wrap', fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.5, padding: 10, background: 'var(--bg-input)', borderRadius: 8 }}>{jd.description || '—'}</div>
              </div>
              <div>
                <p style={{ fontSize: 10, fontWeight: 700, color: '#a78bfa', textTransform: 'uppercase', margin: '0 0 4px' }}>New version</p>
                <div style={{ maxHeight: 240, overflowY: 'auto', whiteSpace: 'pre-wrap', fontSize: 12, color: 'var(--text-h)', lineHeight: 1.5, padding: 10, background: 'var(--bg-input)', borderRadius: 8 }}>{compare.content}</div>
              </div>
            </div>
            {compare.previous && <p style={{ fontSize: 10.5, color: 'var(--text-muted)', margin: '6px 0 0' }}>Your previous AI version is preserved until you Replace.</p>}
            <div style={{ display: 'flex', gap: 6, marginTop: 10 }}>
              <button type="button" onClick={() => applyCompare('replace')} style={actBtn({ background: '#7C3AED', color: '#fff', border: 'none' })}>Replace</button>
              <button type="button" onClick={() => applyCompare('append')} style={actBtn()}>Append</button>
              <button type="button" onClick={() => setCompare(null)} style={actBtn({ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' })}>Cancel</button>
            </div>
          </div>
        )}

        {/* JD Analyzer scorecard */}
        <div style={{ gridColumn: '1/-1' }}>
          <JdScorecard report={report} analyzing={analyzing} onReanalyze={() => analyzeContent(jd.description, false)} />
        </div>

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
    <Overlay onClose={onClose} width={900}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14, flexWrap: 'wrap' }}>
        <h3 style={{ color: 'var(--text-h)', margin: 0, fontSize: 17, fontWeight: 800 }}>MR-{request.id} · {request.position_title}</h3>
        <StatusBadge status={request.status} />
      </div>
      <div style={{ marginBottom: 16 }}><WorkflowStepper request={request} /></div>
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
      <div style={{ marginTop: 8 }}>
        <label style={labelStyle}>Approval Timeline &amp; Audit Trail</label>
        {request.audit_logs === undefined
          ? <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>Loading timeline…</p>
          : <AuditTimeline entries={request.audit_logs} />}
      </div>
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

// ── List View (SPK-1) — professional enterprise table over the same requests ──
// as the card view (no new API). Sortable, sticky header, bulk selection and
// pagination. Search + status filters already narrow `rows` upstream, so the
// table just presents them.
const mpTh = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap', cursor: 'pointer', userSelect: 'none' }
const mpTd = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle', whiteSpace: 'nowrap' }
const mpPage = 12
const mpMoney = (min, max) => {
  const L = n => n >= 100000 ? `₹${(n / 100000).toFixed(1)}L` : `₹${Number(n).toLocaleString('en-IN')}`
  if (!min && !max) return '—'
  if (min && max) return `${L(min)}–${L(max)}`
  return L(min || max)
}
// Human "current approval level" from the workflow status.
const mpLevel = (s) => ({
  [MR_STATUS.DRAFT]: 'Draft',
  [MR_STATUS.L1_PENDING]: 'L1 Pending',
  [MR_STATUS.L2_PENDING]: 'L2 Pending',
  [MR_STATUS.READY_FOR_HR]: 'Approved',
  [MR_STATUS.CONVERTED_TO_JD]: 'Converted',
  [MR_STATUS.JOB_POSTED]: 'Posted',
  [MR_STATUS.HIRING_IN_PROGRESS]: 'Hiring',
  [MR_STATUS.CLOSED]: 'Closed',
  [MR_STATUS.REJECTED]: 'Rejected',
}[s] || statusLabel(s))

const MP_COLS = [
  { key: 'id',        label: 'MR ID',       get: r => r.id },
  { key: 'title',     label: 'Job Title',   get: r => (r.position_title || '').toLowerCase() },
  { key: 'dept',      label: 'Department',  get: r => (r.department || '').toLowerCase() },
  { key: 'project',   label: 'Project',     get: r => (r.project || '').toLowerCase() },
  { key: 'positions', label: 'Positions',   get: r => Number(r.number_of_posts || 0) },
  { key: 'salary',    label: 'Salary',      get: r => Number(r.salary_max || r.salary_min || 0) },
  { key: 'reqby',     label: 'Required By', get: r => r.required_by_date || '' },
  { key: 'requester', label: 'Requested By',get: r => (r.requester?.name || '').toLowerCase() },
  { key: 'level',     label: 'Approval',    get: r => (r.status || '') },
  { key: 'status',    label: 'Status',      get: r => (r.status || '') },
]

function ManpowerListView({ rows, onView, onEdit, isOwner, openAction, openConvert, manageHr, approveL1, approveL2 }) {
  const [sortKey, setSortKey] = useState('id')
  const [asc, setAsc] = useState(false)
  const [sel, setSel] = useState(new Set())
  const [page, setPage] = useState(1)

  const sorted = useMemo(() => {
    const col = MP_COLS.find(c => c.key === sortKey)
    const out = [...rows]
    if (col) out.sort((a, b) => { const x = col.get(a), y = col.get(b); return (x < y ? -1 : x > y ? 1 : 0) * (asc ? 1 : -1) })
    return out
  }, [rows, sortKey, asc])

  const pages = Math.max(1, Math.ceil(sorted.length / mpPage))
  const pageRows = sorted.slice((page - 1) * mpPage, page * mpPage)
  useEffect(() => { if (page > pages) setPage(1) }, [pages]) // eslint-disable-line

  const toggleSort = (k) => { if (sortKey === k) setAsc(a => !a); else { setSortKey(k); setAsc(true) } }
  const SortIcon = ({ k }) => sortKey === k ? (asc ? <ChevronUp size={11} /> : <ChevronDown size={11} />) : <ChevronsUpDown size={11} style={{ opacity: 0.4 }} />
  const allOnPage = pageRows.length > 0 && pageRows.every(r => sel.has(r.id))
  const toggleAll = () => setSel(prev => { const n = new Set(prev); allOnPage ? pageRows.forEach(r => n.delete(r.id)) : pageRows.forEach(r => n.add(r.id)); return n })
  const toggleOne = (id) => setSel(prev => { const n = new Set(prev); n.has(id) ? n.delete(id) : n.add(id); return n })

  // Primary quick action per row — reuses the existing handlers (no new logic).
  const primary = (r) => {
    if (r.status === MR_STATUS.DRAFT && isOwner(r)) return ['Submit', () => openAction(r, 'submit'), '#a78bfa']
    if (r.status === MR_STATUS.L1_PENDING && approveL1) return ['L1 Approve', () => openAction(r, 'approve-l1'), '#10b981']
    if (r.status === MR_STATUS.L2_PENDING && approveL2) return ['L2 Approve', () => openAction(r, 'approve-l2'), '#10b981']
    if (r.status === MR_STATUS.READY_FOR_HR && manageHr) return ['Convert to JD', () => openConvert(r), '#6366f1']
    return null
  }

  return (
    <div className="card-3d" style={{ padding: 0, overflow: 'hidden' }}>
      {sel.size > 0 && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 14px', background: 'rgba(124,58,237,0.1)', borderBottom: '1px solid var(--border)' }}>
          <span style={{ fontSize: 12, fontWeight: 700, color: '#a78bfa' }}>{sel.size} selected</span>
          <button onClick={() => setSel(new Set())} style={{ marginLeft: 'auto', fontSize: 11.5, fontWeight: 700, padding: '4px 10px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer' }}>Clear</button>
        </div>
      )}
      <div style={{ overflowX: 'auto', maxHeight: '62vh', overflowY: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead style={{ position: 'sticky', top: 0, zIndex: 1, background: 'var(--bg-card, var(--bg-input))' }}>
            <tr>
              <th style={{ ...mpTh, width: 30, cursor: 'default' }}><input type="checkbox" checked={allOnPage} onChange={toggleAll} style={{ cursor: 'pointer' }} /></th>
              {MP_COLS.map(c => (
                <th key={c.key} style={mpTh} onClick={() => toggleSort(c.key)}>
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3 }}>{c.label} <SortIcon k={c.key} /></span>
                </th>
              ))}
              <th style={{ ...mpTh, textAlign: 'right', cursor: 'default' }}>Actions</th>
            </tr>
          </thead>
          <tbody>
            {pageRows.map(r => {
              const p = primary(r)
              const sc = STATUS_CONFIG[r.status] || {}
              return (
                <tr key={r.id} style={{ background: sel.has(r.id) ? 'rgba(124,58,237,0.06)' : 'transparent', cursor: 'pointer' }}
                  onClick={() => onView(r)}
                  onMouseEnter={e => { if (!sel.has(r.id)) e.currentTarget.style.background = 'var(--bg-input)' }}
                  onMouseLeave={e => { if (!sel.has(r.id)) e.currentTarget.style.background = 'transparent' }}>
                  <td style={mpTd} onClick={e => e.stopPropagation()}><input type="checkbox" checked={sel.has(r.id)} onChange={() => toggleOne(r.id)} style={{ cursor: 'pointer' }} /></td>
                  <td style={{ ...mpTd, fontWeight: 700, color: '#a78bfa' }}>MR-{r.id}</td>
                  <td style={{ ...mpTd, fontWeight: 700, color: 'var(--text-h)', whiteSpace: 'normal', maxWidth: 200 }}>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                      <span style={{ width: 7, height: 7, borderRadius: '50%', background: PRIORITY_COLORS[r.priority] || '#94a3b8', flexShrink: 0 }} title={r.priority} />
                      {r.position_title || '—'}
                    </span>
                  </td>
                  <td style={{ ...mpTd, color: 'var(--text-muted)' }}>{r.department || '—'}</td>
                  <td style={{ ...mpTd, color: 'var(--text-muted)' }}>{r.project || '—'}</td>
                  <td style={{ ...mpTd, textAlign: 'center', fontWeight: 700 }}>{r.number_of_posts ?? '—'}</td>
                  <td style={{ ...mpTd, color: 'var(--text-muted)' }}>{mpMoney(r.salary_min, r.salary_max)}</td>
                  <td style={{ ...mpTd, color: 'var(--text-muted)' }}>{r.required_by_date ? new Date(r.required_by_date).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'}</td>
                  <td style={{ ...mpTd, color: 'var(--text-muted)' }}>{r.requester?.name || '—'}</td>
                  <td style={mpTd}><span style={{ fontSize: 10.5, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: `${sc.color || '#94a3b8'}18`, color: sc.color || '#94a3b8' }}>{mpLevel(r.status)}</span></td>
                  <td style={mpTd}><span style={{ fontSize: 10.5, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: sc.bg || 'var(--bg-input)', color: sc.color || 'var(--text-muted)' }}>{statusLabel(r.status)}</span></td>
                  <td style={{ ...mpTd, textAlign: 'right' }} onClick={e => e.stopPropagation()}>
                    <div style={{ display: 'inline-flex', gap: 6, justifyContent: 'flex-end' }}>
                      {p && <button onClick={p[1]} style={{ fontSize: 11, fontWeight: 700, padding: '4px 9px', borderRadius: 7, border: 'none', background: `${p[2]}1f`, color: p[2], cursor: 'pointer' }}>{p[0]}</button>}
                      <button onClick={() => onView(r)} title="View" style={{ fontSize: 11, fontWeight: 700, padding: '4px 8px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer' }}><Eye size={12} /></button>
                    </div>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      {/* Pagination */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '10px 14px', borderTop: '1px solid var(--border)' }}>
        <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{sorted.length} request{sorted.length === 1 ? '' : 's'} · page {page} of {pages}</span>
        <div style={{ display: 'flex', gap: 6 }}>
          <button onClick={() => setPage(p => Math.max(1, p - 1))} disabled={page === 1} style={{ padding: '5px 9px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: page === 1 ? 'not-allowed' : 'pointer', opacity: page === 1 ? 0.5 : 1 }}><ChevronLeft size={14} /></button>
          <button onClick={() => setPage(p => Math.min(pages, p + 1))} disabled={page === pages} style={{ padding: '5px 9px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: page === pages ? 'not-allowed' : 'pointer', opacity: page === pages ? 0.5 : 1 }}><ChevronRight size={14} /></button>
        </div>
      </div>
    </div>
  )
}
