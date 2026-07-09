import { useState, useEffect, useCallback } from 'react'
import { Plus, ChevronDown, CheckCircle, XCircle, Clock, Send, RefreshCw, FileText, AlertTriangle, Filter, Search, Eye, Trash2, ThumbsUp, ThumbsDown } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { useAuth } from '@/context/AuthContext'

// ── Status config ──────────────────────────────────────────────────────────
const STATUS_CONFIG = {
  Draft:      { label: 'Draft',         color: '#6b7280', bg: 'rgba(107,114,128,0.15)', icon: FileText },
  Pending_L1: { label: 'Pending L1',    color: '#f59e0b', bg: 'rgba(245,158,11,0.15)',  icon: Clock },
  Pending_L2: { label: 'Pending L2',    color: '#8b5cf6', bg: 'rgba(139,92,246,0.15)',  icon: Clock },
  Approved:   { label: 'Approved',      color: '#10b981', bg: 'rgba(16,185,129,0.15)',  icon: CheckCircle },
  Rejected:   { label: 'Rejected',      color: '#ef4444', bg: 'rgba(239,68,68,0.15)',   icon: XCircle },
  Pending:    { label: 'Pending',       color: '#f59e0b', bg: 'rgba(245,158,11,0.15)',  icon: Clock },
}
const PRIORITY_COLORS = { High: '#ef4444', Medium: '#f59e0b', Low: '#10b981' }

// ── Approval Stepper ───────────────────────────────────────────────────────
function ApprovalStepper({ request }) {
  const steps = [
    { key: 'Draft',      label: 'Draft',        done: true },
    { key: 'Pending_L1', label: 'L1 Dept Head', done: ['Pending_L2','Approved'].includes(request.status), active: request.status === 'Pending_L1' },
    { key: 'Pending_L2', label: 'L2 Management',done: request.status === 'Approved', active: request.status === 'Pending_L2' },
    { key: 'Approved',   label: 'HR Posts Job', done: request.status === 'Approved', active: false },
  ]
  if (request.status === 'Rejected') {
    return (
      <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11, color: '#ef4444' }}>
        <XCircle size={13} /> Rejected {request.l1_status === 'rejected' ? 'at L1' : 'at L2'} — {request.rejection_reason}
      </div>
    )
  }
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
      {steps.map((step, i) => (
        <div key={step.key} style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
          <div style={{
            display: 'flex', alignItems: 'center', gap: 4, fontSize: 10, fontWeight: 600,
            padding: '2px 8px', borderRadius: 12,
            background: step.done ? 'rgba(16,185,129,0.15)' : step.active ? 'rgba(139,92,246,0.2)' : 'rgba(255,255,255,0.05)',
            color: step.done ? '#10b981' : step.active ? '#a78bfa' : 'var(--text-muted)',
            border: `1px solid ${step.done ? 'rgba(16,185,129,0.3)' : step.active ? 'rgba(139,92,246,0.4)' : 'rgba(255,255,255,0.08)'}`,
          }}>
            {step.done ? <CheckCircle size={10} /> : step.active ? <Clock size={10} /> : <div style={{ width: 8, height: 8, borderRadius: '50%', background: 'currentColor', opacity: 0.4 }} />}
            {step.label}
          </div>
          {i < steps.length - 1 && <div style={{ width: 16, height: 1, background: 'rgba(255,255,255,0.1)' }} />}
        </div>
      ))}
    </div>
  )
}

// ── Status Badge ───────────────────────────────────────────────────────────
function StatusBadge({ status }) {
  const cfg = STATUS_CONFIG[status] || STATUS_CONFIG.Pending
  const Icon = cfg.icon
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '3px 10px', borderRadius: 20, fontSize: 11, fontWeight: 700, background: cfg.bg, color: cfg.color, border: `1px solid ${cfg.color}40` }}>
      <Icon size={11} />{cfg.label}
    </span>
  )
}

// ── Main Page ──────────────────────────────────────────────────────────────
export default function ManpowerRequests() {
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'

  const [requests, setRequests]       = useState([])
  const [stats, setStats]             = useState({})
  const [loading, setLoading]         = useState(true)
  const [search, setSearch]           = useState('')
  const [filterStatus, setFilterStatus] = useState('All')
  const [showModal, setShowModal]     = useState(false)
  const [showApproveModal, setShowApproveModal] = useState(null) // { request, action }
  const [detailRequest, setDetailRequest] = useState(null)
  const [remarksInput, setRemarksInput]   = useState('')
  const [actionLoading, setActionLoading] = useState(false)

  const [form, setForm] = useState({
    department: '', position_title: '', number_of_posts: 1,
    priority: 'Medium', job_type: 'Full-time',
    required_by_date: '', justification: '',
  })

  const fetchAll = useCallback(async () => {
    setLoading(true)
    try {
      const [reqRes, statRes] = await Promise.all([
        hrApi.get('/manpower-requests'),
        hrApi.get('/manpower-requests/stats'),
      ])
      setRequests(reqRes.data?.data || reqRes.data || [])
      setStats(statRes.data?.data || statRes.data || {})
    } catch (e) { console.error(e) }
    finally { setLoading(false) }
  }, [])

  useEffect(() => { fetchAll() }, [fetchAll])

  const filtered = requests.filter(r => {
    const matchSearch = search === '' || r.position_title?.toLowerCase().includes(search.toLowerCase()) || r.department?.toLowerCase().includes(search.toLowerCase())
    const matchStatus = filterStatus === 'All' || r.status === filterStatus
    return matchSearch && matchStatus
  })

  // ── Form submit ────────────────────────────────────────────────────────
  const handleCreate = async () => {
    try {
      await hrApi.post('/manpower-requests', form)
      setShowModal(false)
      setForm({ department: '', position_title: '', number_of_posts: 1, priority: 'Medium', job_type: 'Full-time', required_by_date: '', justification: '' })
      fetchAll()
    } catch (e) { alert(e?.response?.data?.message || 'Failed to create') }
  }

  // ── Approval action ────────────────────────────────────────────────────
  const handleApprovalAction = async () => {
    if (!showApproveModal) return
    setActionLoading(true)
    const { request, action } = showApproveModal
    const endpointMap = {
      submit:     `/manpower-requests/${request.id}/submit`,
      'approve-l1': `/manpower-requests/${request.id}/approve-l1`,
      'reject-l1':  `/manpower-requests/${request.id}/reject-l1`,
      'approve-l2': `/manpower-requests/${request.id}/approve-l2`,
      'reject-l2':  `/manpower-requests/${request.id}/reject-l2`,
    }
    try {
      await hrApi.post(endpointMap[action], { remarks: remarksInput })
      setShowApproveModal(null)
      setRemarksInput('')
      fetchAll()
    } catch (e) { alert(e?.response?.data?.message || 'Action failed') }
    finally { setActionLoading(false) }
  }

  const openAction = (request, action) => { setShowApproveModal({ request, action }); setRemarksInput('') }

  const statCards = [
    { label: 'Total',       value: stats.total      || 0, color: '#7C3AED' },
    { label: 'Draft',       value: stats.draft       || 0, color: '#6b7280' },
    { label: 'Pending L1',  value: stats.pending_l1  || 0, color: '#f59e0b' },
    { label: 'Pending L2',  value: stats.pending_l2  || 0, color: '#8b5cf6' },
    { label: 'Approved',    value: stats.approved    || 0, color: '#10b981' },
    { label: 'Rejected',    value: stats.rejected    || 0, color: '#ef4444' },
  ]

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-main, #0b0b16)' }}>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 24 }}>
        <div>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>Manpower Requests</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '4px 0 0' }}>L1 → L2 approval workflow before HR posts the job</p>
        </div>
        <div style={{ display: 'flex', gap: 10 }}>
          <button onClick={fetchAll} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>
            <RefreshCw size={14} /> Refresh
          </button>
          <button onClick={() => setShowModal(true)} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 18px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }}>
            <Plus size={15} /> New Request
          </button>
        </div>
      </div>

      {/* Stats row */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(6,1fr)', gap: 12, marginBottom: 24 }}>
        {statCards.map(s => (
          <div key={s.label} className="kpi-3d" style={{ padding: 16, textAlign: 'center', cursor: 'pointer' }} onClick={() => setFilterStatus(s.label === 'Total' ? 'All' : s.label.replace(' ', '_'))}>
            <div style={{ fontSize: 26, fontWeight: 900, color: s.color }}>{s.value}</div>
            <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 600, marginTop: 2 }}>{s.label}</div>
          </div>
        ))}
      </div>

      {/* Filters */}
      <div className="card-3d" style={{ padding: '14px 18px', marginBottom: 18, display: 'flex', gap: 12, alignItems: 'center' }}>
        <div style={{ position: 'relative', flex: 1 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search position or department..." style={{ width: '100%', paddingLeft: 32, padding: '8px 12px 8px 32px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, outline: 'none' }} />
        </div>
        <select value={filterStatus} onChange={e => setFilterStatus(e.target.value)} style={{ padding: '8px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, cursor: 'pointer' }}>
          {['All','Draft','Pending_L1','Pending_L2','Approved','Rejected'].map(s => <option key={s} value={s}>{s === 'All' ? 'All Status' : STATUS_CONFIG[s]?.label || s}</option>)}
        </select>
      </div>

      {/* Requests List */}
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
                {/* Left info */}
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6, flexWrap: 'wrap' }}>
                    <span style={{ color: 'var(--text-h)', fontWeight: 700, fontSize: 15 }}>{req.position_title}</span>
                    <StatusBadge status={req.status} />
                    <span style={{ padding: '2px 8px', borderRadius: 10, fontSize: 11, fontWeight: 700, background: `${PRIORITY_COLORS[req.priority]}20`, color: PRIORITY_COLORS[req.priority], border: `1px solid ${PRIORITY_COLORS[req.priority]}40` }}>{req.priority}</span>
                    <span style={{ padding: '2px 8px', borderRadius: 10, fontSize: 11, background: 'rgba(124,58,237,0.1)', color: '#a78bfa', border: '1px solid rgba(124,58,237,0.2)' }}>{req.job_type}</span>
                  </div>
                  <div style={{ display: 'flex', gap: 16, color: 'var(--text-muted)', fontSize: 12, marginBottom: 10, flexWrap: 'wrap' }}>
                    <span>🏢 {req.department}</span>
                    <span>👥 {req.number_of_posts} post{req.number_of_posts > 1 ? 's' : ''}</span>
                    {req.required_by_date && <span>📅 By {new Date(req.required_by_date).toLocaleDateString('en-IN')}</span>}
                    <span>👤 {req.requester?.name || 'Unknown'}</span>
                    <span>🕐 {new Date(req.created_at).toLocaleDateString('en-IN')}</span>
                  </div>
                  {/* Approval Stepper */}
                  <ApprovalStepper request={req} />
                  {/* L1/L2 approver info */}
                  {(req.l1_approver_id || req.l2_approver_id) && (
                    <div style={{ display: 'flex', gap: 12, marginTop: 8, fontSize: 11, color: 'var(--text-muted)' }}>
                      {req.l1_approver_id && <span>✅ L1: <strong style={{ color: '#10b981' }}>{req.l1_approver?.name || 'Dept Head'}</strong></span>}
                      {req.l2_approver_id && <span>✅ L2: <strong style={{ color: '#10b981' }}>{req.l2_approver?.name || 'Management'}</strong></span>}
                    </div>
                  )}
                </div>

                {/* Action Buttons */}
                <div style={{ display: 'flex', flexDirection: 'column', gap: 6, flexShrink: 0 }}>
                  {/* Submit (Draft → L1) */}
                  {req.status === 'Draft' && (
                    <button onClick={() => openAction(req, 'submit')} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '6px 12px', borderRadius: 8, background: 'rgba(124,58,237,0.15)', border: '1px solid rgba(124,58,237,0.3)', color: '#a78bfa', cursor: 'pointer', fontSize: 12, fontWeight: 600 }}>
                      <Send size={12} /> Submit for L1
                    </button>
                  )}
                  {/* L1 actions (Admin can approve L1) */}
                  {req.status === 'Pending_L1' && isAdmin && (
                    <div style={{ display: 'flex', gap: 6 }}>
                      <button onClick={() => openAction(req, 'approve-l1')} style={{ display: 'flex', alignItems: 'center', gap: 5, padding: '6px 10px', borderRadius: 8, background: 'rgba(16,185,129,0.15)', border: '1px solid rgba(16,185,129,0.3)', color: '#10b981', cursor: 'pointer', fontSize: 12, fontWeight: 600 }}>
                        <ThumbsUp size={12} /> L1 Approve
                      </button>
                      <button onClick={() => openAction(req, 'reject-l1')} style={{ display: 'flex', alignItems: 'center', gap: 5, padding: '6px 10px', borderRadius: 8, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.3)', color: '#f87171', cursor: 'pointer', fontSize: 12, fontWeight: 600 }}>
                        <ThumbsDown size={12} /> Reject
                      </button>
                    </div>
                  )}
                  {/* L2 actions */}
                  {req.status === 'Pending_L2' && isAdmin && (
                    <div style={{ display: 'flex', gap: 6 }}>
                      <button onClick={() => openAction(req, 'approve-l2')} style={{ display: 'flex', alignItems: 'center', gap: 5, padding: '6px 10px', borderRadius: 8, background: 'rgba(16,185,129,0.15)', border: '1px solid rgba(16,185,129,0.3)', color: '#10b981', cursor: 'pointer', fontSize: 12, fontWeight: 600 }}>
                        <ThumbsUp size={12} /> L2 Approve
                      </button>
                      <button onClick={() => openAction(req, 'reject-l2')} style={{ display: 'flex', alignItems: 'center', gap: 5, padding: '6px 10px', borderRadius: 8, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.3)', color: '#f87171', cursor: 'pointer', fontSize: 12, fontWeight: 600 }}>
                        <ThumbsDown size={12} /> Reject
                      </button>
                    </div>
                  )}
                  {/* Approved — ready to post */}
                  {req.status === 'Approved' && (
                    <span style={{ display: 'flex', alignItems: 'center', gap: 5, padding: '6px 12px', borderRadius: 8, background: 'rgba(16,185,129,0.1)', border: '1px solid rgba(16,185,129,0.2)', color: '#10b981', fontSize: 12, fontWeight: 600 }}>
                      <CheckCircle size={12} /> Ready to Post Job
                    </span>
                  )}
                  <button onClick={() => setDetailRequest(req)} style={{ display: 'flex', alignItems: 'center', gap: 5, padding: '6px 12px', borderRadius: 8, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12 }}>
                    <Eye size={12} /> View
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* ── Create Modal ────────────────────────────────────────────────── */}
      {showModal && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.7)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }} onClick={e => e.target === e.currentTarget && setShowModal(false)}>
          <div className="card-3d" style={{ width: '100%', maxWidth: 580, maxHeight: '90vh', overflowY: 'auto', padding: 28 }}>
            <h2 style={{ color: 'var(--text-h)', margin: '0 0 20px', fontSize: 18, fontWeight: 800 }}>New Manpower Request</h2>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
              {[
                { key: 'department', label: 'Department', type: 'text', placeholder: 'e.g. Engineering' },
                { key: 'position_title', label: 'Position Title', type: 'text', placeholder: 'e.g. Senior Developer' },
                { key: 'number_of_posts', label: 'No. of Posts', type: 'number', placeholder: '1' },
                { key: 'required_by_date', label: 'Required By Date', type: 'date', placeholder: '' },
              ].map(f => (
                <div key={f.key}>
                  <label style={{ display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 6, textTransform: 'uppercase', letterSpacing: '0.05em' }}>{f.label}</label>
                  <input type={f.type} value={form[f.key]} onChange={e => setForm(p => ({ ...p, [f.key]: e.target.value }))} placeholder={f.placeholder}
                    style={{ width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }} />
                </div>
              ))}
              {[
                { key: 'priority', label: 'Priority', options: ['Low','Medium','High'] },
                { key: 'job_type', label: 'Job Type', options: ['Full-time','Part-time','Contract','Internship'] },
              ].map(f => (
                <div key={f.key}>
                  <label style={{ display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 6, textTransform: 'uppercase', letterSpacing: '0.05em' }}>{f.label}</label>
                  <select value={form[f.key]} onChange={e => setForm(p => ({ ...p, [f.key]: e.target.value }))}
                    style={{ width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, cursor: 'pointer', outline: 'none', boxSizing: 'border-box' }}>
                    {f.options.map(o => <option key={o} value={o}>{o}</option>)}
                  </select>
                </div>
              ))}
              <div style={{ gridColumn: '1/-1' }}>
                <label style={{ display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 6, textTransform: 'uppercase', letterSpacing: '0.05em' }}>Justification</label>
                <textarea value={form.justification} onChange={e => setForm(p => ({ ...p, justification: e.target.value }))} rows={3} placeholder="Why is this position required?"
                  style={{ width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, resize: 'vertical', outline: 'none', boxSizing: 'border-box' }} />
              </div>
            </div>
            <div style={{ display: 'flex', gap: 10, marginTop: 20, justifyContent: 'flex-end' }}>
              <button onClick={() => setShowModal(false)} style={{ padding: '9px 20px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>Cancel</button>
              <button onClick={handleCreate} style={{ padding: '9px 24px', borderRadius: 9, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }}>Create Request</button>
            </div>
          </div>
        </div>
      )}

      {/* ── Approval Action Modal ───────────────────────────────────────── */}
      {showApproveModal && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.75)', zIndex: 1001, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }} onClick={e => e.target === e.currentTarget && !actionLoading && setShowApproveModal(null)}>
          <div className="card-3d" style={{ width: '100%', maxWidth: 460, padding: 28 }}>
            {(() => {
              const isApprove = showApproveModal.action.startsWith('approve')
              const isSubmit  = showApproveModal.action === 'submit'
              const color = isApprove ? '#10b981' : isSubmit ? '#7C3AED' : '#ef4444'
              const label = { submit: 'Submit for L1 Approval', 'approve-l1': 'Approve L1 (Dept Head)', 'reject-l1': 'Reject at L1', 'approve-l2': 'Approve L2 (Management)', 'reject-l2': 'Reject at L2' }[showApproveModal.action]
              return <>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 16 }}>
                  {isApprove || isSubmit ? <CheckCircle size={22} color={color} /> : <XCircle size={22} color={color} />}
                  <h3 style={{ color: 'var(--text-h)', margin: 0, fontSize: 16, fontWeight: 800 }}>{label}</h3>
                </div>
                <p style={{ color: 'var(--text-muted)', fontSize: 13, marginBottom: 16 }}>
                  Request: <strong style={{ color: 'var(--text-h)' }}>{showApproveModal.request.position_title}</strong> — {showApproveModal.request.department}
                </p>
                {!isSubmit && (
                  <>
                    <label style={{ display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 6, textTransform: 'uppercase' }}>
                      {isApprove ? 'Remarks (optional)' : 'Reason for Rejection *'}
                    </label>
                    <textarea value={remarksInput} onChange={e => setRemarksInput(e.target.value)} rows={3} placeholder={isApprove ? 'Add any remarks...' : 'Enter rejection reason...'}
                      style={{ width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: `1px solid ${!isApprove && !remarksInput ? '#ef444480' : 'var(--border)'}`, borderRadius: 8, color: 'var(--text-h)', fontSize: 13, resize: 'vertical', outline: 'none', boxSizing: 'border-box' }} />
                  </>
                )}
                {isSubmit && <p style={{ color: 'var(--text-muted)', fontSize: 12, padding: '10px 14px', background: 'rgba(124,58,237,0.08)', borderRadius: 8, border: '1px solid rgba(124,58,237,0.2)' }}>
                  ℹ️ This will send the request to the <strong>Department Head (L1)</strong> for approval. After L1, it goes to <strong>Management (L2)</strong>. HR can post the job only after both approvals.
                </p>}
                <div style={{ display: 'flex', gap: 10, marginTop: 20, justifyContent: 'flex-end' }}>
                  <button onClick={() => !actionLoading && setShowApproveModal(null)} style={{ padding: '9px 20px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>Cancel</button>
                  <button onClick={handleApprovalAction} disabled={actionLoading || (!isApprove && !isSubmit && !remarksInput)}
                    style={{ padding: '9px 24px', borderRadius: 9, background: actionLoading ? 'rgba(124,58,237,0.4)' : `linear-gradient(135deg,${color},${color}cc)`, color: '#fff', fontWeight: 700, border: 'none', cursor: actionLoading ? 'not-allowed' : 'pointer', fontSize: 13, opacity: (!isApprove && !isSubmit && !remarksInput) ? 0.5 : 1 }}>
                    {actionLoading ? 'Processing...' : 'Confirm'}
                  </button>
                </div>
              </>
            })()}
          </div>
        </div>
      )}

    </div>
  )
}
