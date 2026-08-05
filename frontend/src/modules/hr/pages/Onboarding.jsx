import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTheme } from '@/context/ThemeContext'
import { Plus, X, Check, Download, FileText, ShieldCheck, ExternalLink, Copy, Eye, Send } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { useMasterData } from '@/modules/hr/useMasterData'
import { DOC_LABEL, mandatoryDocKeys, isDocMandatory } from '@/config/onboardingDocs'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import WorkflowProgress from '@/components/ui/WorkflowProgress'
import { ONBOARDING_DOC_ITEMS, ONBOARDING_DOC_LABELS, computeOnboardingChecklist } from '@/modules/hr/constants'

// Build the public candidate-portal URL from the token already stored in
// hr_onboarding — never generates a new token.
const portalUrl = (token) => `${window.location.origin}/onboarding/${token}`

const VERIF_STYLE = (s) => s === 'Approved' ? { c: '#10b981', bg: 'rgba(16,185,129,0.12)' }
  : s === 'Submitted' ? { c: '#a78bfa', bg: 'rgba(124,58,237,0.12)' }
  : s === 'Rejected' ? { c: '#f87171', bg: 'rgba(239,68,68,0.1)' }
  : { c: '#fbbf24', bg: 'rgba(245,158,11,0.12)' }
const DOC_TYPE_LABEL = DOC_LABEL

// HR verification of a candidate's submitted onboarding (documents / background / medical).
const DOC_ST = (s) => s === 'Verified' ? { c: '#10b981', bg: 'rgba(16,185,129,0.12)' } : s === 'Rejected' ? { c: '#f87171', bg: 'rgba(239,68,68,0.1)' } : { c: '#fbbf24', bg: 'rgba(245,158,11,0.12)' }

// The Document Checklist auto-completes from the verification workflow — no manual
// ticking. Now shared with the Candidate 360° onboarding stage so both screens
// derive the checklist identically and can never disagree.
const computeChecklist = (r) => computeOnboardingChecklist(r)

function VerifyPanel({ record, onVerify, onUpdated, showToast }) {
  const [flags, setFlags] = useState({ doc_verified: !!record.doc_verified, background_verified: !!record.background_verified, medical_verified: !!record.medical_verified })
  const [notes, setNotes] = useState(record.verification_notes || '')
  const [message, setMessage] = useState('')
  const [sending, setSending] = useState(false)
  const [viewer, setViewer] = useState(null)     // { url, name, isImage }
  const [rejecting, setRejecting] = useState(null) // { doc, reason }
  const [busyDoc, setBusyDoc] = useState(null)
  const vs = record.verification_status
  const st = VERIF_STYLE(vs)
  const docs = record.documents || []
  const sub = record.submission || {}

  // Approve gate: every mandatory document (dynamic — Previous Employment Docs
  // only when the candidate has prior experience) must be present and Verified.
  const docByType = Object.fromEntries(docs.map(d => [d.type, d]))
  const MAND = mandatoryDocKeys(record.candidate?.experience_years)

  // Uploaded-document breakdown (informational — includes optional docs).
  const total = docs.length
  const verifiedCount = docs.filter(d => d.status === 'Verified').length
  const rejectedCount = docs.filter(d => d.status === 'Rejected').length
  const pendingCount = total - verifiedCount - rejectedCount

  // Mandatory-completeness — the SINGLE source of truth shared by the progress
  // bar and the Approval Readiness card, so the two can never contradict.
  const mandTotal = MAND.length
  const mandVerified = MAND.filter(t => docByType[t]?.status === 'Verified').length
  const mandRejected = MAND.filter(t => docByType[t]?.status === 'Rejected').length
  const mandPending = MAND.filter(t => docByType[t] && !['Verified', 'Rejected'].includes(docByType[t].status)).length
  const mandMissing = MAND.filter(t => !docByType[t]).length
  const mandPct = mandTotal ? Math.round((mandVerified / mandTotal) * 100) : 100

  // Concrete reasons the onboarding cannot be approved yet — never a silent disable.
  const blockers = []
  MAND.forEach(t => {
    const d = docByType[t]
    if (!d) blockers.push(`${DOC_TYPE_LABEL[t] || t} not uploaded`)
    else if (d.status === 'Rejected') blockers.push(`${DOC_TYPE_LABEL[t] || t} rejected`)
    else if (d.status !== 'Verified') blockers.push(`${DOC_TYPE_LABEL[t] || t} pending verification`)
  })
  if (!flags.background_verified) blockers.push('Background verification incomplete')
  const canApprove = blockers.length === 0

  const download = async (doc) => {
    try {
      const blob = await hrApi.onboarding.documentBlob(record.id, doc.id)
      const url = URL.createObjectURL(blob)
      const a = window.document.createElement('a'); a.href = url; a.download = doc.original_name; a.click()
      setTimeout(() => URL.revokeObjectURL(url), 1500)
    } catch { showToast?.('Failed to download', 'error') }
  }

  const view = async (doc) => {
    try {
      const blob = await hrApi.onboarding.documentBlob(record.id, doc.id)
      const url = URL.createObjectURL(blob)
      setViewer({ url, name: doc.original_name, isImage: /^image\//.test(blob.type) || /\.(jpe?g|png|gif|webp)$/i.test(doc.original_name || '') })
    } catch { showToast?.('Failed to open document', 'error') }
  }
  const closeViewer = () => { if (viewer?.url) URL.revokeObjectURL(viewer.url); setViewer(null) }

  const setDocStatus = async (doc, status, remarks = null) => {
    setBusyDoc(doc.id)
    try { const updated = await hrApi.onboarding.verifyDocument(record.id, doc.id, { status, remarks }); onUpdated?.(updated); showToast?.(`Document ${status.toLowerCase()}`) }
    catch { showToast?.('Failed to update document', 'error') }
    finally { setBusyDoc(null) }
  }
  const confirmReject = async () => {
    if (!rejecting?.reason?.trim()) return
    await setDocStatus(rejecting.doc, 'Rejected', rejecting.reason.trim())
    setRejecting(null)
  }

  const sendMessage = async () => {
    if (!message.trim() || !record.candidate_id) return
    setSending(true)
    try { await hrApi.candidates.notes.add(record.candidate_id, message.trim(), true); setMessage(''); showToast?.('Message sent to candidate') }
    catch { showToast?.('Failed to send message', 'error') }
    finally { setSending(false) }
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
          {/* ── Approval Readiness — auto-computed ─────────────────────────── */}
          {(() => {
            const ready = canApprove
            return (
              <div className="mb-3 rounded-xl p-3" style={{ background: 'var(--bg-input)', border: `1px solid ${ready ? 'rgba(16,185,129,0.35)' : 'rgba(245,158,11,0.3)'}` }}>
                <p className="text-[11px] font-black mb-2 flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}><ShieldCheck size={12} style={{ color: '#a78bfa' }} /> Approval Readiness</p>
                <div className="grid grid-cols-2 md:grid-cols-3 gap-2 mb-2">
                  <div className="px-2.5 py-1.5 rounded-lg" style={{ background: 'var(--bg-card)' }}>
                    <p className="text-[9px]" style={{ color: 'var(--text-muted)' }}>Mandatory Documents</p>
                    <p className="text-[12px] font-black" style={{ color: mandVerified === mandTotal ? '#10b981' : '#fbbf24' }}>{mandVerified} / {mandTotal} Verified</p>
                  </div>
                  <div className="px-2.5 py-1.5 rounded-lg" style={{ background: 'var(--bg-card)' }}>
                    <p className="text-[9px]" style={{ color: 'var(--text-muted)' }}>Background Verification</p>
                    <p className="text-[12px] font-black" style={{ color: flags.background_verified ? '#10b981' : '#fbbf24' }}>{flags.background_verified ? 'Completed' : 'Pending'}</p>
                  </div>
                  <div className="px-2.5 py-1.5 rounded-lg" style={{ background: 'var(--bg-card)' }}>
                    <p className="text-[9px]" style={{ color: 'var(--text-muted)' }}>Medical</p>
                    <p className="text-[12px] font-black" style={{ color: flags.medical_verified ? '#10b981' : 'var(--text-muted)' }}>{flags.medical_verified ? 'Verified' : 'Optional'}</p>
                  </div>
                </div>
                <div className="flex items-start gap-2 flex-wrap">
                  <span className="text-[11px] font-black px-2.5 py-1 rounded-lg" style={{ background: ready ? 'rgba(16,185,129,0.12)' : 'rgba(239,68,68,0.1)', color: ready ? '#10b981' : '#f87171' }}>
                    {ready ? '🟢 Ready for Approval' : '🔴 Cannot Approve'}
                  </span>
                  {!ready && blockers.length > 0 && (
                    <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>Missing: {blockers.join(' · ')}</span>
                  )}
                </div>
              </div>
            )
          })()}

          {/* ── Candidate Documents — progress measured against MANDATORY docs
                 (same source as Approval Readiness, so they never contradict). */}
          {mandTotal > 0 && (
            <div className="mb-3">
              <div className="flex items-center justify-between mb-1">
                <p className="text-[11px] font-bold" style={{ color: 'var(--text-h)' }}>Candidate Documents <span style={{ color: 'var(--text-muted)' }}>· {mandVerified}/{mandTotal} mandatory verified</span></p>
                <p className="text-[10px] font-semibold" style={{ color: mandPct === 100 ? '#10b981' : 'var(--text-muted)' }}>{mandPct}%</p>
              </div>
              <div className="h-2 rounded-full overflow-hidden flex" style={{ background: 'var(--bg-input)' }}>
                <div style={{ width: `${(mandVerified / mandTotal) * 100}%`, background: 'linear-gradient(90deg,#34d399,#10b981)' }} />
                <div style={{ width: `${(mandPending / mandTotal) * 100}%`, background: '#fbbf24' }} />
                <div style={{ width: `${(mandRejected / mandTotal) * 100}%`, background: '#f87171' }} />
                <div style={{ width: `${(mandMissing / mandTotal) * 100}%`, background: 'var(--border)' }} />
              </div>
              <p className="text-[9px] mt-1" style={{ color: 'var(--text-muted)' }}>
                Mandatory: <span style={{ color: '#10b981' }}>{mandVerified} verified</span> · <span style={{ color: '#fbbf24' }}>{mandPending} pending</span> · <span style={{ color: '#f87171' }}>{mandRejected} rejected</span> · <span>{mandMissing} not uploaded</span>
                <span style={{ color: 'var(--text-muted)' }}> — {total} uploaded incl. optional ({verifiedCount} verified, {pendingCount} pending, {rejectedCount} rejected)</span>
              </p>
            </div>
          )}

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

          {/* Documents — per-document actions: View · Download · Verify · Reject */}
          {docs.length > 0 && (
            <div className="space-y-1.5 mb-3">
              {docs.map(d => {
                const dst = DOC_ST(d.status)
                const mandatory = isDocMandatory(d.type, record.candidate?.experience_years)
                return (
                  <div key={d.id} className="flex items-center gap-2 px-2.5 py-1.5 rounded-lg flex-wrap" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    <FileText size={12} style={{ color: '#a78bfa' }} />
                    <span className="text-[11px] font-semibold" style={{ color: 'var(--text-h)' }}>{DOC_TYPE_LABEL[d.type] || d.type}</span>
                    <span className="text-[8px] font-bold px-1.5 py-0.5 rounded" style={{ background: mandatory ? 'rgba(239,68,68,0.1)' : 'rgba(16,185,129,0.1)', color: mandatory ? '#f87171' : '#10b981' }} title={mandatory ? 'Mandatory' : 'Optional'}>{mandatory ? '🔴 Mandatory' : '🟢 Optional'}</span>
                    <span className="text-[9px] font-bold px-1.5 py-0.5 rounded" style={{ background: dst.bg, color: dst.c }}>{d.status || 'Pending'}</span>
                    {d.status === 'Rejected' && d.remarks && <span className="text-[10px] truncate" style={{ color: '#f87171', maxWidth: 200 }} title={d.remarks}>· {d.remarks}</span>}
                    <div className="ml-auto flex items-center gap-1">
                      <button onClick={() => view(d)} title="View" className="flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded" style={{ background: 'rgba(59,130,246,0.1)', color: '#60a5fa' }}><Eye size={11} /> View</button>
                      <button onClick={() => download(d)} title="Download" className="flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded" style={{ background: 'var(--bg-card,rgba(124,58,237,0.08))', color: 'var(--text-muted)', border: '1px solid var(--border)' }}><Download size={11} /> Download</button>
                      {vs !== 'Approved' && <>
                        <button onClick={() => setDocStatus(d, 'Verified')} disabled={busyDoc === d.id || d.status === 'Verified'} className="text-[10px] font-bold px-2 py-0.5 rounded" style={{ background: 'rgba(16,185,129,0.12)', color: '#10b981', opacity: (busyDoc === d.id || d.status === 'Verified') ? 0.5 : 1 }}>Verify</button>
                        <button onClick={() => setRejecting({ doc: d, reason: d.remarks || '' })} disabled={busyDoc === d.id} className="text-[10px] font-bold px-2 py-0.5 rounded" style={{ background: 'rgba(239,68,68,0.1)', color: '#f87171' }}>Reject</button>
                      </>}
                    </div>
                  </div>
                )
              })}
            </div>
          )}

          {/* Message the candidate (appears in their portal) */}
          <div className="flex gap-2 mb-3">
            <input className="input-3d text-xs flex-1" placeholder="Message the candidate (shown in their portal)…" value={message} onChange={e => setMessage(e.target.value)} onKeyDown={e => e.key === 'Enter' && sendMessage()} />
            <button onClick={sendMessage} disabled={sending || !message.trim()} className="px-3 rounded-xl text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity: (sending || !message.trim()) ? 0.6 : 1 }}>Send</button>
          </div>

          {vs === 'Approved' ? (
            <p className="text-xs font-semibold" style={{ color: '#10b981' }}>✓ Verified &amp; approved — this candidate is ready for an offer letter.</p>
          ) : vs === 'Rejected' ? (
            <p className="text-xs" style={{ color: '#f87171' }}>Sent back to candidate{record.rejection_reason ? `: ${record.rejection_reason}` : ''}. Awaiting re-submission.</p>
          ) : (
            <>
              <div className="flex flex-wrap gap-3 mb-2">
                {[['background_verified', 'Background verified'], ['medical_verified', 'Medical verified (optional)']].map(([k, l]) => (
                  <label key={k} className="flex items-center gap-1.5 text-xs cursor-pointer" style={{ color: 'var(--text-muted)' }}>
                    <input type="checkbox" checked={!!flags[k]} onChange={e => setFlags(f => ({ ...f, [k]: e.target.checked }))} /> {l}
                  </label>
                ))}
              </div>
              <input className="input-3d text-xs mb-2" placeholder="Verification notes (optional)" value={notes} onChange={e => setNotes(e.target.value)} />
              <div className="flex gap-2">
                <button onClick={() => onVerify(record.id, { ...flags, doc_verified: true, verification_notes: notes, decision: 'approve' })}
                  disabled={!canApprove}
                  title={canApprove ? 'Approve onboarding and generate the offer letter' : `Cannot approve because:\n${blockers.map(b => '• ' + b).join('\n')}`}
                  className="flex-1 py-2 rounded-xl text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#10b981,#059669)', opacity: canApprove ? 1 : 0.5, cursor: canApprove ? 'pointer' : 'not-allowed' }}>
                  ✓ Approve Onboarding
                </button>
                <button onClick={() => { const rr = window.prompt('Reason to send back to the candidate:'); if (rr !== null) onVerify(record.id, { ...flags, decision: 'reject', rejection_reason: rr }) }}
                  className="flex-1 py-2 rounded-xl text-xs font-bold" style={{ background: 'rgba(239,68,68,0.1)', color: '#f87171' }}>
                  Send Back
                </button>
              </div>
              {!canApprove && (
                <div className="text-[10px] mt-1.5" style={{ color: 'var(--text-muted)' }}>
                  <span className="font-bold">Cannot approve because:</span>
                  <ul className="mt-0.5 space-y-0.5">{blockers.map((b, i) => <li key={i}>• {b}</li>)}</ul>
                </div>
              )}
            </>
          )}
        </>
      )}

      {/* Document viewer modal */}
      {viewer && (
        <div className="modal-backdrop" onClick={closeViewer}>
          <div className="modal-box" onClick={e => e.stopPropagation()} style={{ maxWidth: 820, width: '92vw' }}>
            <div className="flex items-center justify-between mb-3">
              <p className="text-sm font-bold truncate" style={{ color: 'var(--text-h)' }}>{viewer.name}</p>
              <button onClick={closeViewer} style={{ color: 'var(--text-muted)' }}><X size={18} /></button>
            </div>
            <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)', background: '#fff' }}>
              {viewer.isImage
                ? <img src={viewer.url} alt={viewer.name} style={{ width: '100%', maxHeight: '72vh', objectFit: 'contain', display: 'block' }} />
                : <iframe src={viewer.url} title={viewer.name} style={{ width: '100%', height: '72vh', border: 'none' }} />}
            </div>
          </div>
        </div>
      )}

      {/* Reject-document modal (reason required, shown to candidate) */}
      {rejecting && (
        <div className="modal-backdrop" onClick={() => setRejecting(null)}>
          <div className="modal-box max-w-md" onClick={e => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-black text-base" style={{ color: 'var(--text-h)' }}>Reject {DOC_TYPE_LABEL[rejecting.doc.type] || rejecting.doc.type}</h2>
              <button onClick={() => setRejecting(null)} style={{ color: 'var(--text-muted)' }}><X size={18} /></button>
            </div>
            <p className="text-xs mb-2" style={{ color: 'var(--text-muted)' }}>The reason is shown to the candidate in their portal so they can re-upload.</p>
            <textarea rows={3} className="input-3d text-sm resize-none" placeholder="e.g. Scan is blurry / wrong document uploaded" value={rejecting.reason} onChange={e => setRejecting(r => ({ ...r, reason: e.target.value }))} autoFocus />
            <div className="flex gap-3 pt-4">
              <button onClick={() => setRejecting(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
              <button onClick={confirmReject} disabled={!rejecting.reason.trim() || busyDoc === rejecting.doc.id} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#f87171,#ef4444)', opacity: (!rejecting.reason.trim() || busyDoc === rejecting.doc.id) ? 0.5 : 1 }}>Reject Document</button>
            </div>
          </div>
        </div>
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
  const navigate = useNavigate()
  const [records, setRecords] = useState([])
  const [loading, setLoading] = useState(true)
  const { masters } = useMasterData()
  const [filterS, setFilterS] = useState('All')
  // #3 — hiring manager, resolved through candidate → job posting → requisition.
  const [mgrF, setMgrF] = useState('All')
  const [expanded, setExpanded] = useState(null)
  const [showModal, setShowModal] = useState(false)
  // #17 — onboarding starts FROM a candidate. The name is no longer typed; it
  // comes from the chosen candidate, so an onboarding can never exist for
  // somebody the candidate database has never heard of.
  const [form, setForm] = useState({ candidate_id: '', candidate_name: '', position: '', joining_date: '', department: '' })
  const [candidates, setCandidates] = useState([])
  const [saving, setSaving] = useState(false)
  const [toast, setToast] = useState(null)
  const [approved, setApproved] = useState(null)      // { candidateName, offer } — success modal after approval
  const [sendingOffer, setSendingOffer] = useState(false)

  const showToast = (msg, type = 'success') => {
    setToast({ msg, type })
    setTimeout(() => setToast(null), 3000)
  }

  const fetchData = async () => {
    setLoading(true)
    try {
      const params = {
        ...(filterS !== 'All' ? { status: filterS } : {}),
        ...(mgrF !== 'All' ? { hiring_manager_id: mgrF } : {}),
      }
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
  }, [filterS, mgrF])

  // #17 — the candidate database is the only source for who can be onboarded.
  // Loaded once; a failure leaves the picker empty and the modal says so rather
  // than silently falling back to free-text entry.
  useEffect(() => {
    hrApi.candidates.list()
      .then(r => setCandidates(Array.isArray(r) ? r : (r?.data ?? [])))
      .catch(() => setCandidates([]))
  }, [])

  // Open / copy the candidate onboarding portal using the existing token.
  const openPortal = (token) => window.open(portalUrl(token), '_blank', 'noopener,noreferrer')
  const copyLink = async (token) => {
    const url = portalUrl(token)
    try { await navigator.clipboard.writeText(url) } catch { /* fallback below */ }
    showToast('Candidate portal link copied!')
  }

  // Shared with the Candidate 360° onboarding stage — one definition, one source.
  const DOC_ITEMS = ONBOARDING_DOC_ITEMS
  const DOC_LABELS = ONBOARDING_DOC_LABELS

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

  // #17 — a candidate is now the first thing required, not a typed name.
  const handleCreate = async () => {
    if (!form.candidate_id || !form.position || !form.joining_date) {
      return showToast('Candidate, position and joining date are required', 'error')
    }
    setSaving(true)
    try {
      const rec = await hrApi.onboarding.start(form)
      setRecords(prev => [rec, ...prev])
      setShowModal(false)
      setForm({ candidate_id: '', candidate_name: '', position: '', joining_date: '', department: '' })
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
      if (payload.decision === 'approve') {
        // Offer is auto-generated on approval — surface the next step.
        setApproved({ candidateName: updated.candidate_name || updated.candidate?.name, offer: updated.candidate?.offer })
        showToast('Onboarding approved — offer letter generated.')
      } else {
        showToast(payload.decision === 'reject' ? 'Onboarding sent back to candidate.' : 'Verification saved')
      }
    } catch (e) { showToast(e.response?.data?.message || 'Failed to verify', 'error') }
  }

  // Send the auto-generated offer straight from the success modal (Email + WhatsApp + portal).
  const sendOfferNow = async () => {
    if (!approved?.offer?.id) return
    setSendingOffer(true)
    try {
      const sent = await hrApi.offers.send(approved.offer.id)
      setApproved(a => ({ ...a, offer: { ...a.offer, ...sent } }))
      showToast('Offer sent via Email, WhatsApp & candidate portal.')
    } catch (e) { showToast(e.response?.data?.message || 'Failed to send offer', 'error') }
    finally { setSendingOffer(false) }
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

      {/* Approval success modal — offer auto-generated, prompt to send */}
      {approved && (() => {
        const offer = approved.offer
        const alreadySent = offer && offer.status && offer.status !== 'Generated'
        return (
          <div className="modal-backdrop" onClick={() => setApproved(null)}>
            <div className="modal-box max-w-md text-center" onClick={e => e.stopPropagation()}>
              <div className="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-3" style={{ background: 'rgba(16,185,129,0.12)' }}>
                <Check size={28} style={{ color: '#10b981' }} />
              </div>
              <h2 className="font-black text-lg mb-1" style={{ color: 'var(--text-h)' }}>Onboarding Approved</h2>
              <p className="text-sm mb-1" style={{ color: 'var(--text-muted)' }}>Offer Letter has been generated.</p>
              <p className="text-sm mb-1" style={{ color: 'var(--text-h)' }}>Candidate: <span className="font-bold">{approved.candidateName || '—'}</span></p>
              <p className="text-xs mb-5" style={{ color: 'var(--text-muted)' }}>{alreadySent ? `Offer status: ${offer.status}.` : 'Next Step: Send the Offer Letter.'}</p>
              <div className="flex gap-3">
                <button onClick={() => { setApproved(null); navigate('/app/hr/offers') }}
                  className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                  {alreadySent ? 'View Offer' : 'Go to Offer Letters'}
                </button>
                {!alreadySent && (
                  <button onClick={sendOfferNow} disabled={sendingOffer || !offer?.id}
                    className="flex-1 flex items-center justify-center gap-1.5 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity: (sendingOffer || !offer?.id) ? 0.6 : 1 }}>
                    <Send size={13} /> {sendingOffer ? 'Sending…' : 'Send Offer Now'}
                  </button>
                )}
              </div>
            </div>
          </div>
        )
      })()}

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

      <div className="flex gap-2 items-center flex-wrap">
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
        {/* #3 — hiring manager, applied server-side. */}
        <select className="input-3d text-xs ml-auto" style={{ maxWidth: 210 }} value={mgrF} onChange={e => setMgrF(e.target.value)}>
          <option value="All">All Hiring Managers</option>
          {(masters.managers || []).map(m => <option key={m.id} value={m.id}>{m.name}</option>)}
        </select>
      </div>

      {loading ? (
        <HrLoading label="Loading onboarding…" />
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
                  <div className="flex items-center gap-2 flex-wrap justify-end">
                    <span className="text-[10px] font-bold px-2.5 py-1 rounded-xl" style={{ background: ss.bg, color: ss.c }}>
                      {r.status}
                    </span>
                    {/* Candidate Portal — reuses the token already in hr_onboarding */}
                    {r.access_token ? (
                      <>
                        <button
                          onClick={() => openPortal(r.access_token)}
                          className="flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-xl font-bold text-white"
                          style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}
                          title="Open the candidate's onboarding portal in a new tab"
                        >
                          <ExternalLink size={12} /> Open Candidate Portal
                        </button>
                        <button
                          onClick={() => copyLink(r.access_token)}
                          className="flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-xl font-semibold"
                          style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}
                          title="Copy the onboarding link to share via email/WhatsApp"
                        >
                          <Copy size={12} /> Copy Link
                        </button>
                      </>
                    ) : (
                      <button disabled className="flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-xl font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)', opacity: 0.55, cursor: 'not-allowed' }} title="This onboarding has no candidate portal token">
                        Portal link not available.
                      </button>
                    )}
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
                {r.verification_status && <VerifyPanel record={r} onVerify={handleVerify} onUpdated={(u)=>setRecords(prev=>prev.map(x=>x.id===u.id?u:x))} showToast={showToast} />}

                {expanded === r.id && (
                  <>
                    {/* #14 — where this hire has reached, shown on the record
                        rather than the list header so it tracks one person. */}
                    <div className="mt-4 rounded-xl px-3 py-2.5" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                      <WorkflowProgress kind="onboarding" record={r} />
                    </div>

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
                    <p className="text-xs font-bold mb-2 flex items-center gap-1.5" style={{ color:'var(--text-h)' }}>📋 Document Checklist <span className="text-[9px] font-medium" style={{ color:'var(--text-muted)' }}>· updates automatically from verification</span></p>
                    <div className="grid grid-cols-2 gap-2">
                      {(() => { const cl = computeChecklist(r); return DOC_ITEMS.map(docKey=>{
                        const checked = !!cl[docKey]
                        return(
                          <div key={docKey} className="flex items-center gap-2 px-2.5 py-2 rounded-xl transition-all"
                            style={{ background:checked?'rgba(16,185,129,0.08)':'var(--bg-input)', border:`1px solid ${checked?'rgba(16,185,129,0.25)':'var(--border)'}` }}>
                            <div className="w-4 h-4 rounded flex items-center justify-center flex-shrink-0" style={{ background:checked?'rgba(16,185,129,0.2)':'var(--bg-card)', border:`1px solid ${checked?'#10b981':'var(--border)'}` }}>
                              {checked && <Check size={8} style={{ color:'#10b981' }}/>}
                            </div>
                            <span className="text-[10px] font-semibold" style={{ color:checked?'#10b981':'var(--text-muted)' }}>{DOC_LABELS[docKey]}</span>
                          </div>
                        )
                      })})()}
                    </div>
                  </div>
                  </>
                )}
                  </div>
                )
          })}
                {records.length === 0 && (
                  <HrEmpty icon={ShieldCheck} title="No onboarding records" hint="Onboarding starts automatically when a candidate is selected — records will appear here." />
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
                    {/* #17 — chosen from the candidate database, never typed.
                        Position and department prefill from the candidate's job
                        posting so the onboarding matches what they applied for. */}
                    <div>
                      <label className="label">Candidate *</label>
                      <select
                        className="input-3d text-sm"
                        value={form.candidate_id}
                        onChange={e => {
                          const c = candidates.find(x => String(x.id) === e.target.value)
                          setForm(f => ({
                            ...f,
                            candidate_id: e.target.value,
                            candidate_name: c?.name || '',
                            position: f.position || c?.job_posting?.title || c?.position_applied || '',
                            department: f.department || c?.job_posting?.department || '',
                          }))
                        }}
                      >
                        <option value="">Select a candidate…</option>
                        {candidates.map(c => (
                          <option key={c.id} value={c.id}>
                            {c.name}{c.email ? ` — ${c.email}` : ''}
                          </option>
                        ))}
                      </select>
                      {candidates.length === 0 && (
                        <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>
                          No candidates are ready to onboard yet. Add them in the Candidates pipeline first.
                        </p>
                      )}
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
