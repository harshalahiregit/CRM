import { useState } from 'react'
import { CheckCircle2, XCircle, Clock, RotateCcw, Loader2, ShieldCheck, FileText } from 'lucide-react'
import AuditTimeline from '@/components/ui/AuditTimeline'
import { hrApi } from '@/services/hrApi'

/**
 * HR Pre-Offer Verification — section-by-section review of what the candidate
 * submitted from the portal.
 *
 * section_status is the single source of truth: this panel only reads it and calls
 * the existing verify endpoint. Progress comes from progress_percent (never
 * recomputed here) and every decision is audited by the existing trail.
 */

// The 18 onboarding sections, in the order the candidate fills them.
export const VERIFY_SECTIONS = [
  { key: 'application', title: 'Application' },
  { key: 'identity', title: 'Personal Information' },
  { key: 'contact', title: 'Contact Information' },
  { key: 'emergency', title: 'Emergency Contact' },
  { key: 'health', title: 'Health Information' },
  { key: 'joining', title: 'Experience & Joining' },
  { key: 'education', title: 'Education (all levels)' },
  { key: 'skills', title: 'Skills' },
  { key: 'experience', title: 'Employment History' },
  { key: 'references', title: 'References' },
  { key: 'bond', title: 'Bond & Statutory' },
  { key: 'bank', title: 'Bank Details' },
  { key: 'compliance', title: 'Statutory Compliance' },
  { key: 'documents', title: 'Documents' },
  { key: 'declaration', title: 'Declaration' },
  { key: 'orientation', title: 'Orientation Feedback' },
]

// Sections that must be Verified before the overall onboarding can be approved.
const MANDATORY = ['identity', 'contact', 'joining', 'education', 'documents', 'declaration']

const CHIP = {
  Verified: { c: '#059669', bg: 'rgba(16,185,129,0.12)', I: CheckCircle2 },
  Rejected: { c: '#dc2626', bg: 'rgba(239,68,68,0.10)', I: XCircle },
  'Correction Requested': { c: '#d97706', bg: 'rgba(245,158,11,0.12)', I: RotateCcw },
  Submitted: { c: '#2563eb', bg: 'rgba(37,99,235,0.10)', I: Clock },
  Draft: { c: 'var(--text-muted)', bg: 'var(--bg-input)', I: Clock },
}
const fmt = d => d ? new Date(d).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' }) : '—'

export default function OnboardingVerificationPanel({ id, data, reload, toast, editable = true }) {
  const statuses = data.section_status || {}
  const rowOf = (key) => (Array.isArray(statuses) ? statuses.find(s => s.section === key) : statuses[key]) || {}

  const [open, setOpen] = useState(null)
  const [remarks, setRemarks] = useState({})
  const [busy, setBusy] = useState(null)

  const act = async (section, status) => {
    const note = (remarks[section] || '').trim()
    if ((status === 'Rejected' || status === 'Correction Requested') && !note) {
      return toast?.('Remarks are required when rejecting or requesting a correction.', 'error')
    }
    setBusy(`${section}:${status}`)
    try {
      await hrApi.employeeOnboarding.verifySection(id, section, status, note || null)
      toast?.(`${section} — ${status}`)
      setRemarks(r => ({ ...r, [section]: '' }))
      await reload()
    } catch (e) {
      toast?.(e.response?.data?.message || 'Could not update the section.', 'error')
    } finally { setBusy(null) }
  }

  const [approving, setApproving] = useState(false)
  const candOnbId = data.onboarding?.candidate_onboarding_id
  const overallStatus = data.onboarding?.verification_status || 'Pending'

  // Reuses the EXISTING overall approval endpoint — the same one that gates
  // Generate Offer. No new API, no duplicated verification logic.
  const approveAll = async () => {
    setApproving(true)
    try {
      await hrApi.onboarding.verify(candOnbId, {
        doc_verified: true, background_verified: true, medical_verified: true,
        decision: 'approve', verification_notes: 'All onboarding sections verified.',
      })
      toast?.('Onboarding verification approved — Generate Offer is now available.')
      await reload()
    } catch (e) {
      toast?.(e.response?.data?.message || 'Could not approve the verification.', 'error')
    } finally { setApproving(false) }
  }

  const countBy = (st) => VERIFY_SECTIONS.filter(s => rowOf(s.key).status === st).length
  const docs = data.documents || []
  const docsVerified = docs.filter(d => d.status === 'Verified').length

  const verifiedCount = VERIFY_SECTIONS.filter(s => rowOf(s.key).status === 'Verified').length
  const pendingMandatory = MANDATORY.filter(k => rowOf(k).status !== 'Verified')
  const anyRejected = VERIFY_SECTIONS.some(s => ['Rejected', 'Correction Requested'].includes(rowOf(s.key).status))
  const canApproveAll = pendingMandatory.length === 0 && !anyRejected

  return (
    <div className="space-y-3">
      {/* Summary — progress comes straight from the backend */}
      <div className="card-3d" style={{ padding: 16 }}>
        <div className="flex items-center justify-between flex-wrap gap-3">
          <div>
            <p className="text-[10px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Verification</p>
            <p className="text-sm font-black mt-0.5" style={{ color: 'var(--text-h)' }}>
              {verifiedCount} of {VERIFY_SECTIONS.length} sections verified
            </p>
          </div>
          <span className="text-xs font-black" style={{ color: '#a78bfa' }}>{data.onboarding?.progress_percent ?? 0}% complete</span>
        </div>
        <div className="h-2 rounded-full mt-3" style={{ background: 'var(--bg-input)' }}>
          <div className="h-full rounded-full transition-all" style={{ width: `${data.onboarding?.progress_percent ?? 0}%`, background: 'linear-gradient(90deg,#7C3AED,#a78bfa)' }} />
        </div>
        {!canApproveAll && (
          <p className="text-[11px] mt-3" style={{ color: 'var(--text-muted)' }}>
            {anyRejected
              ? 'A section is rejected or awaiting correction — overall verification stays Pending until the candidate resubmits.'
              : <>Pending mandatory sections: <b style={{ color: 'var(--text-h)' }}>{pendingMandatory.join(', ')}</b></>}
          </p>
        )}
        {/* Counts derived entirely from backend section_status / documents */}
        <div className="grid grid-cols-3 md:grid-cols-6 gap-2 mt-3">
          <Stat k="Verified" v={countBy('Verified')} c="#10b981" />
          <Stat k="Submitted" v={countBy('Submitted')} c="#2563eb" />
          <Stat k="Rejected" v={countBy('Rejected')} c="#f87171" />
          <Stat k="Correction" v={countBy('Correction Requested')} c="#f59e0b" />
          <Stat k="Draft" v={countBy('Draft')} c="var(--text-muted)" />
          <Stat k="Docs Verified" v={`${docsVerified}/${docs.length}`} c="#a78bfa" />
        </div>

        <div className="flex items-center justify-between gap-3 mt-3 pt-3 flex-wrap" style={{ borderTop: '1px solid var(--border)' }}>
          <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
            Verification Status: <b style={{ color: overallStatus === 'Approved' ? '#10b981' : '#f59e0b' }}>{overallStatus}</b>
          </span>
          {overallStatus !== 'Approved' && canApproveAll && candOnbId && (
            <button onClick={approveAll} disabled={approving}
              className="flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold text-white"
              style={{ background: 'linear-gradient(135deg,#10b981,#059669)', opacity: approving ? 0.7 : 1 }}>
              {approving ? <Loader2 size={13} className="animate-spin" /> : <ShieldCheck size={13} />} Approve Onboarding Verification
            </button>
          )}
        </div>
      </div>

      {/* Documents — reuses the existing verification data; no second implementation */}
      <div className="card-3d" style={{ padding: 16 }}>
        <h3 className="font-bold text-sm mb-3 flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
          <FileText size={14} style={{ color: '#a78bfa' }} /> Documents ({docsVerified}/{docs.length} verified)
        </h3>
        {docs.length === 0
          ? <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No documents uploaded yet.</p>
          : (
            <div className="space-y-1.5">
              {docs.map(d => {
                const chip = CHIP[d.status] || CHIP.Draft
                return (
                  <div key={d.id} className="flex items-center gap-3 px-3 py-2 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    <chip.I size={13} style={{ color: chip.c, flexShrink: 0 }} />
                    <span className="flex-1 min-w-0">
                      <span className="block text-xs font-bold truncate" style={{ color: 'var(--text-h)' }}>{d.doc_type || d.category}</span>
                      <span className="block text-[10px] truncate" style={{ color: 'var(--text-muted)' }}>{d.original_name}{d.remarks ? ` · ${d.remarks}` : ''}</span>
                    </span>
                    <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg flex-shrink-0" style={{ color: chip.c, background: chip.bg }}>{d.status || 'Pending'}</span>
                  </div>
                )
              })}
            </div>
          )}
      </div>

      {/* Section list */}
      <div className="card-3d" style={{ padding: 16 }}>
        <div className="space-y-1.5">
          {VERIFY_SECTIONS.map(s => {
            const row = rowOf(s.key)
            const st = row.status || 'Draft'
            const chip = CHIP[st] || CHIP.Draft
            const isOpen = open === s.key
            return (
              <div key={s.key}>
                <button onClick={() => setOpen(isOpen ? null : s.key)}
                  className="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-left"
                  style={{ background: isOpen ? 'rgba(124,58,237,0.06)' : 'var(--bg-input)', border: `1px solid ${isOpen ? 'rgba(124,58,237,0.3)' : 'var(--border)'}`, cursor: 'pointer' }}>
                  <chip.I size={14} style={{ color: chip.c, flexShrink: 0 }} />
                  <span className="flex-1 min-w-0">
                    <span className="block text-xs font-bold truncate" style={{ color: 'var(--text-h)' }}>{s.title}</span>
                    {row.verified_at && <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>Verified {fmt(row.verified_at)}</span>}
                  </span>
                  <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg flex-shrink-0" style={{ color: chip.c, background: chip.bg }}>{st}</span>
                </button>

                {isOpen && (
                  <div className="mx-3 mt-1 mb-2 px-3 py-3 rounded-xl" style={{ background: 'var(--bg-card,transparent)', border: '1px solid var(--border)' }}>
                    <div className="grid grid-cols-2 gap-x-4 gap-y-1.5 mb-3">
                      <Meta k="Status" v={st} />
                      <Meta k="Submitted" v={fmt(row.submitted_at)} />
                      <Meta k="Verified By" v={row.verified_by ? `User #${row.verified_by}` : '—'} />
                      <Meta k="Verified At" v={fmt(row.verified_at)} />
                    </div>
                    {row.remarks && (
                      <p className="text-[11px] mb-3 px-2.5 py-2 rounded-lg" style={{ background: 'rgba(245,158,11,0.08)', color: 'var(--text-h)' }}>
                        <b>Remarks:</b> {row.remarks}
                      </p>
                    )}

                    {editable && (
                      <>
                        <textarea rows={2} className="input-3d text-sm resize-none" placeholder="Remarks (required to reject or request a correction)"
                          value={remarks[s.key] || ''} onChange={e => setRemarks(r => ({ ...r, [s.key]: e.target.value }))} />
                        <div className="flex gap-2 mt-2 flex-wrap">
                          <Btn onClick={() => act(s.key, 'Verified')} busy={busy === `${s.key}:Verified`} c="#10b981" bg="rgba(16,185,129,0.1)" I={CheckCircle2}>Approve</Btn>
                          <Btn onClick={() => act(s.key, 'Rejected')} busy={busy === `${s.key}:Rejected`} c="#f87171" bg="rgba(239,68,68,0.08)" I={XCircle}>Reject</Btn>
                          <Btn onClick={() => act(s.key, 'Correction Requested')} busy={busy === `${s.key}:Correction Requested`} c="#f59e0b" bg="rgba(245,158,11,0.1)" I={RotateCcw}>Request Correction</Btn>
                        </div>
                      </>
                    )}
                  </div>
                )}
              </div>
            )
          })}
        </div>
      </div>

      {/* Compact audit timeline — the existing audit data, existing component */}
      <div className="card-3d" style={{ padding: 16 }}>
        <h3 className="font-bold text-sm mb-3" style={{ color: 'var(--text-h)' }}>Verification Timeline</h3>
        <AuditTimeline entries={(data.audit_logs || []).slice(0, 12)} newestFirst />
      </div>
    </div>
  )
}

const Stat = ({ k, v, c }) => (
  <div className="px-2.5 py-2 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
    <p className="text-[9px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{k}</p>
    <p className="text-sm font-black mt-0.5" style={{ color: c }}>{v}</p>
  </div>
)

const Meta = ({ k, v }) => (
  <div>
    <p className="text-[9px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{k}</p>
    <p className="text-[11px] font-semibold mt-0.5" style={{ color: 'var(--text-h)' }}>{v || '—'}</p>
  </div>
)

const Btn = ({ onClick, busy, c, bg, I, children }) => (
  <button onClick={onClick} disabled={busy}
    className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-[11px] font-bold"
    style={{ background: bg, color: c, border: `1px solid ${c}33`, opacity: busy ? 0.6 : 1 }}>
    {busy ? <Loader2 size={12} className="animate-spin" /> : <I size={12} />} {children}
  </button>
)
