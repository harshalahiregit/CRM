import { useState, useEffect } from 'react'
import { useTheme } from '@/context/ThemeContext'
import { Plus, X, Check, Download, FileText, ShieldCheck } from 'lucide-react'
import { hrApi } from '@/services/hrApi'

const VERIF_STYLE = (s) => s === 'Approved' ? { c: '#10b981', bg: 'rgba(16,185,129,0.12)' }
  : s === 'Submitted' ? { c: '#a78bfa', bg: 'rgba(124,58,237,0.12)' }
  : s === 'Rejected' ? { c: '#f87171', bg: 'rgba(239,68,68,0.1)' }
  : { c: '#fbbf24', bg: 'rgba(245,158,11,0.12)' }
const DOC_TYPE_LABEL = { aadhaar: 'Aadhaar', pan: 'PAN', resume: 'Resume', photo: 'Photo', address_proof: 'Address Proof', company_document: 'Company Doc', other: 'Other' }

// HR verification of a candidate's submitted onboarding (documents / background / medical).
function VerifyPanel({ record, onVerify }) {
  const [flags, setFlags] = useState({ doc_verified: !!record.doc_verified, background_verified: !!record.background_verified, medical_verified: !!record.medical_verified })
  const [notes, setNotes] = useState(record.verification_notes || '')
  const [reason, setReason] = useState('')
  const vs = record.verification_status
  const st = VERIF_STYLE(vs)
  const docs = record.documents || []
  const sub = record.submission || {}

  const download = async (doc) => {
    try {
      const blob = await hrApi.onboarding.documentBlob(record.id, doc.id)
      const url = URL.createObjectURL(blob)
      const a = window.document.createElement('a'); a.href = url; a.download = doc.original_name; a.click()
      setTimeout(() => URL.revokeObjectURL(url), 1500)
    } catch { /* ignore */ }
  }

  return (
    <div className="mt-4 pt-3 rounded-2xl" style={{ borderTop: '1px solid var(--border)' }}>
      <div className="flex items-center justify-between mb-3">
        <p className="text-xs font-bold flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}><ShieldCheck size={13} style={{ color: '#a78bfa' }} /> Candidate Onboarding Verification</p>
        <span className="text-[10px] font-bold px-2.5 py-1 rounded-xl" style={{ background: st.bg, color: st.c }}>{vs}</span>
      </div>

      {vs === 'Pending' && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Waiting for the candidate to submit their details via the onboarding link.</p>}

      {vs !== 'Pending' && (
        <>
          {/* Submitted details summary */}
          {(sub.personal_details || sub.bank_details || sub.address) && (
            <div className="grid grid-cols-2 md:grid-cols-3 gap-2 mb-3">
              {sub.personal_details?.dob && <Info k="DOB" v={sub.personal_details.dob} />}
              {sub.address?.city && <Info k="City" v={sub.address.city} />}
              {sub.emergency_contact?.name && <Info k="Emergency" v={`${sub.emergency_contact.name}${sub.emergency_contact.phone ? ' · ' + sub.emergency_contact.phone : ''}`} />}
              {sub.bank_details?.account_number && <Info k="Bank A/C" v={sub.bank_details.account_number} />}
              {sub.bank_details?.ifsc && <Info k="IFSC" v={sub.bank_details.ifsc} />}
            </div>
          )}

          {/* Documents */}
          {docs.length > 0 && (
            <div className="flex flex-wrap gap-2 mb-3">
              {docs.map(d => (
                <button key={d.id} onClick={() => download(d)} className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-[11px] font-semibold" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
                  <FileText size={11} style={{ color: '#a78bfa' }} /> {DOC_TYPE_LABEL[d.type] || d.type} <Download size={10} style={{ color: 'var(--text-muted)' }} />
                </button>
              ))}
            </div>
          )}

          {vs === 'Approved' ? (
            <p className="text-xs font-semibold" style={{ color: '#10b981' }}>✓ Verified &amp; approved — this candidate is ready for an offer letter.</p>
          ) : vs === 'Rejected' ? (
            <p className="text-xs" style={{ color: '#f87171' }}>Sent back to candidate{record.rejection_reason ? `: ${record.rejection_reason}` : ''}. Awaiting re-submission.</p>
          ) : (
            <>
              <div className="flex flex-wrap gap-3 mb-2">
                {[['doc_verified', 'Documents'], ['background_verified', 'Background'], ['medical_verified', 'Medical (optional)']].map(([k, l]) => (
                  <label key={k} className="flex items-center gap-1.5 text-xs cursor-pointer" style={{ color: 'var(--text-muted)' }}>
                    <input type="checkbox" checked={!!flags[k]} onChange={e => setFlags(f => ({ ...f, [k]: e.target.checked }))} /> {l}
                  </label>
                ))}
              </div>
              <input className="input-3d text-xs mb-2" placeholder="Verification notes (optional)" value={notes} onChange={e => setNotes(e.target.value)} />
              <div className="flex gap-2">
                <button onClick={() => onVerify(record.id, { ...flags, verification_notes: notes, decision: 'approve' })}
                  disabled={!flags.doc_verified || !flags.background_verified}
                  className="flex-1 py-2 rounded-xl text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#10b981,#059669)', opacity: (!flags.doc_verified || !flags.background_verified) ? 0.5 : 1 }}>
                  ✓ Approve Onboarding
                </button>
                <button onClick={() => { const rr = window.prompt('Reason to send back to the candidate:'); if (rr !== null) onVerify(record.id, { ...flags, decision: 'reject', rejection_reason: rr }) }}
                  className="flex-1 py-2 rounded-xl text-xs font-bold" style={{ background: 'rgba(239,68,68,0.1)', color: '#f87171' }}>
                  Send Back
                </button>
              </div>
              {(!flags.doc_verified || !flags.background_verified) && <p className="text-[10px] mt-1.5" style={{ color: 'var(--text-muted)' }}>Document &amp; background verification are required to approve.</p>}
            </>
          )}
        </>
      )}
    </div>
  )
}
const Info = ({ k, v }) => (
  <div className="px-2.5 py-1.5 rounded-lg" style={{ background: 'var(--bg-input)' }}>
    <p className="text-[9px]" style={{ color: 'var(--text-muted)' }}>{k}</p>
    <p className="text-[11px] font-semibold truncate" style={{ color: 'var(--text-h)' }}>{v}</p>
  </div>
)

const STEPS = [
  { key: 'doc_verification', label: 'Document Verification', icon: '1' },
  { key: 'joining_confirmed', label: 'Joining Date Confirmed', icon: '2' },
  { key: 'emp_id_generated', label: 'Employee ID Generated', icon: '3' },
  { key: 'dept_assigned', label: 'Department Assigned', icon: '4' },
  { key: 'manager_assigned', label: 'Reporting Manager Assigned', icon: '5' },
  { key: 'record_created', label: 'Employee Record Created', icon: '6' }
]

const STATUS_STYLE = (s) => {
  if (s === 'Completed') return { c: '#10b981', bg: 'rgba(16,185,129,0.12)' }
  if (s === 'In Progress') return { c: '#a78bfa', bg: 'rgba(124,58,237,0.12)' }
  return { c: '#fbbf24', bg: 'rgba(245,158,11,0.12)' }
}

const formatDate = (d) => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

export default function Onboarding() {
  const { isDark } = useTheme()
  const [records, setRecords] = useState([])
  const [loading, setLoading] = useState(true)
  const [filterS, setFilterS] = useState('All')
  const [expanded, setExpanded] = useState(null)
  const [showModal, setShowModal] = useState(false)
  const [form, setForm] = useState({ candidate_name: '', position: '', joining_date: '', department: '' })
  const [saving, setSaving] = useState(false)
  const [toast, setToast] = useState(null)

  const showToast = (msg, type = 'success') => {
    setToast({ msg, type })
    setTimeout(() => setToast(null), 3000)
  }

  const fetchData = async () => {
    setLoading(true)
    try {
      const params = filterS !== 'All' ? { status: filterS } : {}
      const data = await hrApi.onboarding.list(params)
      setRecords(data)
    } catch {
      showToast('Failed to load onboarding', 'error')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    fetchData()
  }, [filterS])

  const DOC_ITEMS = ['offer_signed', 'id_proof', 'educational_certs', 'prev_employment_docs', 'bank_details', 'passport_photos']
  const DOC_LABELS = {
    offer_signed: 'Offer Letter (Signed)',
    id_proof: 'ID Proof (Aadhaar/PAN)',
    educational_certs: 'Educational Certificates',
    prev_employment_docs: 'Previous Employment Docs',
    bank_details: 'Bank Account Details',
    passport_photos: 'Passport Size Photos'
  }

  const handleToggle = async (id, step) => {
    try {
      const updated = await hrApi.onboarding.toggleStep(id, step)
      setRecords(prev => prev.map(r => r.id === id ? updated : r))
      if (updated.status === 'Completed') showToast('Onboarding complete! Employee record created.')
    } catch {
      showToast('Failed to update step', 'error')
    }
  }

  const handleDocToggle = async (id, docKey, current) => {
    const rec = records.find(r => r.id === id)
    if (!rec) return
    const checklist = { ...(rec.document_checklist || {}), [docKey]: !current }
    try {
      const updated = await hrApi.onboarding.updateChecklist(id, checklist)
      setRecords(prev => prev.map(r => r.id === id ? updated : r))
    } catch {
      showToast('Failed', 'error')
    }
  }

  const handleCreate = async () => {
    if (!form.candidate_name || !form.position || !form.joining_date) {
      return showToast('Name, position and date required', 'error')
    }
    setSaving(true)
    try {
      const rec = await hrApi.onboarding.start(form)
      setRecords(prev => [rec, ...prev])
      setShowModal(false)
      setForm({ candidate_name: '', position: '', joining_date: '', department: '' })
      showToast('Onboarding started!')
    } catch (e) {
      showToast(e.response?.data?.message || 'Failed', 'error')
    } finally {
      setSaving(false)
    }
  }

  const handleVerify = async (id, payload) => {
    try {
      const updated = await hrApi.onboarding.verify(id, payload)
      setRecords(prev => prev.map(r => r.id === id ? updated : r))
      showToast(payload.decision === 'approve' ? 'Onboarding approved — offer can now be generated.' : payload.decision === 'reject' ? 'Onboarding sent back to candidate.' : 'Verification saved')
    } catch (e) { showToast(e.response?.data?.message || 'Failed to verify', 'error') }
  }

  const getStepDone = (r, key) => r[`step_${key}`] || false
  const getDoneCount = (r) => STEPS.filter(s => getStepDone(r, s.key)).length

  const stats = {
    total: records.length,
    inProgress: records.filter(r => r.status === 'In Progress').length,
    completed: records.filter(r => r.status === 'Completed').length,
    pending: records.filter(r => r.status === 'Pending').length
  }

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && (
        <div
          className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl"
          style={{ background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}
        >
          {toast.msg}
        </div>
      )}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1">HR Module</p>
          <h1 className="font-black" style={{ fontSize: 'clamp(1.3rem,2vw,1.7rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
            Onboarding <span className="text-gradient">Tracker</span>
          </h1>
        </div>
        <button
          onClick={() => setShowModal(true)}
          className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white"
          style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow: '0 4px 14px rgba(124,58,237,0.4)' }}
        >
          <Plus size={15} /> Start Onboarding
        </button>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[
          { l: 'Total', v: stats.total, c: '#7C3AED' },
          { l: 'In Progress', v: stats.inProgress, c: '#a78bfa' },
          { l: 'Completed', v: stats.completed, c: '#10b981' },
          { l: 'Pending', v: stats.pending, c: '#fbbf24' }
        ].map(k => (
          <div key={k.l} className="kpi-3d">
            <p className="text-3xl font-black" style={{ color: k.c }}>{k.v}</p>
            <p className="text-sm font-medium mt-1" style={{ color: 'var(--text-muted)' }}>{k.l}</p>
          </div>
        ))}
      </div>

      <div className="card-3d" style={{ padding: '18px' }}>
        <p className="text-xs font-bold mb-3" style={{ color: 'var(--text-h)' }}>Onboarding Process</p>
        <div className="flex gap-2 overflow-x-auto scrollbar-hide">
          {STEPS.map((s, i) => (
            <div key={s.key} className="flex-shrink-0 flex items-center gap-2 px-3 py-2 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
              <div className="w-6 h-6 rounded-lg flex items-center justify-center text-xs font-black text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
                {i + 1}
              </div>
              <span className="text-[10px] font-semibold" style={{ color: 'var(--text-h)' }}>{s.label}</span>
            </div>
          ))}
        </div>
      </div>

      <div className="flex gap-2">
        {['All', 'In Progress', 'Completed', 'Pending'].map(f => (
          <button
            key={f}
            onClick={() => setFilterS(f)}
            className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
            style={{
              background: filterS === f ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)',
              color: filterS === f ? '#fff' : 'var(--text-muted)',
              border: `1px solid ${filterS === f ? 'transparent' : 'var(--border)'}`
            }}
          >
            {f}
          </button>
        ))}
      </div>

      {loading ? (
        <div className="text-center py-12" style={{ color: 'var(--text-muted)' }}>Loading...</div>
      ) : (
        <div className="space-y-4">
          {records.map(r => {
            const ss = STATUS_STYLE(r.status)
            const done = getDoneCount(r)
            const initials = (r.candidate_name || '?').split(' ').map(x => x[0]).join('').toUpperCase()

            return (
              <div key={r.id} className="card-3d" style={{ padding: '20px' }}>
                <div className="flex items-start justify-between gap-4 flex-wrap mb-3">
                  <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-2xl flex items-center justify-center text-sm font-black text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
                      {initials}
                    </div>
                    <div>
                      <p className="font-bold" style={{ color: 'var(--text-h)' }}>{r.candidate_name}</p>
                      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                        {r.position}{r.department ? ` · ${r.department}` : ''} · Joining: {formatDate(r.joining_date)}
                      </p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="text-[10px] font-bold px-2.5 py-1 rounded-xl" style={{ background: ss.bg, color: ss.c }}>
                      {r.status}
                    </span>
                    <button
                      onClick={() => setExpanded(expanded === r.id ? null : r.id)}
                      className="text-xs px-3 py-1.5 rounded-xl font-semibold"
                      style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}
                    >
                      {expanded === r.id ? 'Hide' : 'Steps'}
                    </button>
                  </div>
                </div>

                <div>
                  <div className="flex gap-0.5 mb-1.5">
                    {STEPS.map(s => (
                      <div
                        key={s.key}
                        className="flex-1 h-2 rounded-full transition-all"
                        style={{ background: getStepDone(r, s.key) ? 'linear-gradient(90deg,#a78bfa,#7C3AED)' : 'var(--bg-input)' }}
                      />
                    ))}
                  </div>
                  <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                    {done}/{STEPS.length} steps completed
                  </p>
                </div>

                {/* Candidate onboarding verification (Sprint 2 flow) */}
                {r.verification_status && <VerifyPanel record={r} onVerify={handleVerify} />}

                {expanded === r.id && (
                  <>
                    <div className="mt-4 grid grid-cols-2 md:grid-cols-3 gap-2.5">
                      {STEPS.map(s => {
                        const isDone = getStepDone(r, s.key)
                        return (
                        <div key={s.key} onClick={()=>handleToggle(r.id, s.key)} className="px-3 py-2.5 rounded-xl cursor-pointer transition-all"
                          style={{ background:isDone?'rgba(16,185,129,0.1)':'var(--bg-input)', border:`1px solid ${isDone?'rgba(16,185,129,0.3)':'var(--border)'}` }}>
                          <div className="flex items-center gap-2 mb-1">
                            <span className="text-base">{s.icon}</span>
                            <div className="w-5 h-5 rounded-lg flex items-center justify-center" style={{ background:isDone?'rgba(16,185,129,0.2)':'var(--bg-card)' }}>
                              {isDone?<Check size={10} style={{ color:'#10b981' }}/>:<div className="w-2 h-2 rounded-full" style={{ background:'var(--border)' }}/>}
                            </div>
                            <p className="text-[10px] font-bold" style={{ color: isDone ? '#10b981' : 'var(--text-muted)' }}>
                              {s.label}
                            </p>
                          </div>
                        </div>
                        )
                      })}
                    </div>

                  <div className="mt-4 pt-3" style={{ borderTop:'1px solid var(--border)' }}>
                    <p className="text-xs font-bold mb-2" style={{ color:'var(--text-h)' }}>📋 Document Checklist</p>
                    <div className="grid grid-cols-2 gap-2">
                      {DOC_ITEMS.map(docKey=>{
                        const checked = !!(r.document_checklist?.[docKey])
                        return(
                          <div key={docKey} onClick={()=>handleDocToggle(r.id,docKey,checked)} className="flex items-center gap-2 px-2.5 py-2 rounded-xl cursor-pointer transition-all"
                            style={{ background:checked?'rgba(16,185,129,0.08)':'var(--bg-input)', border:`1px solid ${checked?'rgba(16,185,129,0.25)':'var(--border)'}` }}>
                            <div className="w-4 h-4 rounded flex items-center justify-center flex-shrink-0" style={{ background:checked?'rgba(16,185,129,0.2)':'var(--bg-card)', border:`1px solid ${checked?'#10b981':'var(--border)'}` }}>
                              {checked && <Check size={8} style={{ color:'#10b981' }}/>}
                            </div>
                            <span className="text-[10px] font-semibold" style={{ color:checked?'#10b981':'var(--text-muted)' }}>{DOC_LABELS[docKey]}</span>
                          </div>
                        )
                      })}
                    </div>
                  </div>
                  </>
                )}
                  </div>
                )
          })}
                {records.length === 0 && (
                  <p className="text-center py-10" style={{ color: 'var(--text-muted)' }}>No onboarding records found.</p>
                )}
              </div>
            )
          }

      { showModal && (
              <div className="modal-backdrop" onClick={() => setShowModal(false)}>
                <div className="modal-box max-w-md" onClick={e => e.stopPropagation()}>
                  <div className="flex items-center justify-between mb-5">
                    <h2 className="font-black text-lg" style={{ color: 'var(--text-h)' }}>Start Onboarding</h2>
                    <button onClick={() => setShowModal(false)} style={{ color: 'var(--text-muted)' }}>
                      <X size={18} />
                    </button>
                  </div>
                  <div className="space-y-3">
                    <div>
                      <label className="label">Candidate Name *</label>
                      <input
                        className="input-3d text-sm"
                        placeholder="Full name"
                        value={form.candidate_name}
                        onChange={e => setForm({ ...form, candidate_name: e.target.value })}
                      />
                    </div>
                    <div>
                      <label className="label">Position *</label>
                      <input
                        className="input-3d text-sm"
                        placeholder="Job title"
                        value={form.position}
                        onChange={e => setForm({ ...form, position: e.target.value })}
                      />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                      <div>
                        <label className="label">Department</label>
                        <input
                          className="input-3d text-sm"
                          placeholder="Department"
                          value={form.department}
                          onChange={e => setForm({ ...form, department: e.target.value })}
                        />
                      </div>
                      <div>
                        <label className="label">Joining Date *</label>
                        <input
                          type="date"
                          className="input-3d text-sm"
                          value={form.joining_date}
                          onChange={e => setForm({ ...form, joining_date: e.target.value })}
                        />
                      </div>
                    </div>
                    <div className="flex gap-3 pt-1">
                      <button
                        onClick={() => setShowModal(false)}
                        className="flex-1 py-2.5 rounded-xl text-sm font-semibold"
                        style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}
                      >
                        Cancel
                      </button>
                      <button
                        onClick={handleCreate}
                        disabled={saving}
                        className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white"
                        style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity: saving ? 0.7 : 1 }}
                      >
                        {saving ? 'Starting...' : 'Start Onboarding'}
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            )}
        </div>
      )
      }
