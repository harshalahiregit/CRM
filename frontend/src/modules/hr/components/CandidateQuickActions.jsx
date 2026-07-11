import { useState, useEffect, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { MoreVertical, Eye, UserPlus, ArrowRightCircle, CalendarPlus, XCircle, X, Loader2 } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { CANDIDATE_STAGES, STAGE_COLORS, INTERVIEW_ROUNDS, canManageHrQueue } from '@/modules/hr/constants'

const currentUser = () => { try { return JSON.parse(localStorage.getItem('crm_user') || 'null') } catch { return null } }

// Stage order used to offer only forward moves (backend enforces the same clamp).
const STAGE_ORDER = CANDIDATE_STAGES.reduce((m, s, i) => ({ ...m, [s]: i }), {})

/**
 * Reusable candidate quick-actions menu + its modals. Dropped onto kanban cards,
 * list rows and the profile header. All mutations are role-gated (mirrors the
 * backend canManageHrQueue guard) and report back through onChanged / onToast.
 *
 * @param {object}   candidate   the candidate row
 * @param {Array}    recruiters  assignable recruiters (fetched once by the parent)
 * @param {function} onChanged   (partialCandidate) => void — merge into parent state
 * @param {function} onToast     (msg, type) => void
 * @param {boolean}  hideView    hide the "View" item (e.g. already on the profile)
 */
export default function CandidateQuickActions({ candidate, recruiters = [], onChanged, onToast, hideView = false }) {
  const navigate = useNavigate()
  const [open, setOpen]   = useState(false)
  const [modal, setModal] = useState(null) // 'assign' | 'stage' | 'interview' | 'reject'
  const [busy, setBusy]   = useState(false)
  const menuRef = useRef(null)

  const user    = currentUser()
  const canManage = canManageHrQueue(user)

  // form state
  const [recruiterId, setRecruiterId] = useState(candidate.assigned_recruiter_id || '')
  const [stage, setStage]             = useState('')
  const [round, setRound]             = useState({ round_name: INTERVIEW_ROUNDS[0], interviewer_name: '', scheduled_at: '' })
  const [rejectReason, setRejectReason] = useState('')

  useEffect(() => {
    const close = (e) => { if (menuRef.current && !menuRef.current.contains(e.target)) setOpen(false) }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [])

  const toast = (m, t = 'success') => onToast?.(m, t)
  const closeAll = () => { setModal(null); setBusy(false) }

  const doAssign = async () => {
    setBusy(true)
    try {
      const updated = await hrApi.candidates.assign(candidate.id, recruiterId || null)
      onChanged?.({ assigned_recruiter_id: updated.assigned_recruiter_id, assigned_recruiter: updated.assigned_recruiter })
      toast(recruiterId ? 'Recruiter assigned' : 'Recruiter cleared')
      closeAll()
    } catch (e) { toast(e.response?.data?.message || 'Failed to assign', 'error'); setBusy(false) }
  }

  const doStage = async () => {
    if (!stage) return
    setBusy(true)
    try {
      const updated = await hrApi.candidates.updateStage(candidate.id, stage)
      onChanged?.({ stage: updated.stage })
      toast(`Moved to ${updated.stage}`)
      closeAll()
    } catch (e) { toast(e.response?.data?.message || 'Failed to move stage', 'error'); setBusy(false) }
  }

  const doInterview = async () => {
    if (!round.scheduled_at) return toast('Pick a date & time', 'error')
    setBusy(true)
    try {
      await hrApi.interviews.schedule({ candidate_id: candidate.id, ...round })
      onChanged?.({ stage: ['Applied', 'Screening', 'Assessment'].includes(candidate.stage) ? 'Interview' : candidate.stage })
      toast('Interview scheduled')
      closeAll()
    } catch (e) { toast(e.response?.data?.message || 'Failed to schedule', 'error'); setBusy(false) }
  }

  const doReject = async () => {
    setBusy(true)
    try {
      if (rejectReason.trim()) { try { await hrApi.candidates.notes.add(candidate.id, `Rejection reason: ${rejectReason.trim()}`) } catch {} }
      const updated = await hrApi.candidates.updateDecision(candidate.id, 'Rejected')
      onChanged?.({ stage: 'Rejected', final_decision: updated.final_decision })
      toast('Candidate rejected')
      closeAll()
    } catch (e) { toast(e.response?.data?.message || 'Failed to reject', 'error'); setBusy(false) }
  }

  const item = (icon, label, onClick, danger = false) => (
    <button onClick={(e) => { e.stopPropagation(); setOpen(false); onClick() }}
      className="w-full flex items-center gap-2.5 px-3 py-2 text-xs font-semibold text-left transition-colors"
      style={{ color: danger ? '#f87171' : 'var(--text-body, var(--text-h))' }}
      onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
      onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
      {icon} {label}
    </button>
  )

  const forwardStages = CANDIDATE_STAGES.filter(s => s !== 'Rejected' && STAGE_ORDER[s] > (STAGE_ORDER[candidate.stage] ?? 0))

  return (
    <div className="relative" ref={menuRef}>
      <button onClick={(e) => { e.stopPropagation(); setOpen(o => !o) }}
        className="p-1.5 rounded-lg transition-colors" title="Quick actions"
        style={{ color: 'var(--text-muted)' }}
        onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
        onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
        <MoreVertical size={15} />
      </button>

      {open && (
        <div className="absolute right-0 mt-1 w-52 rounded-xl overflow-hidden z-50 py-1"
          style={{ background: 'var(--bg-card, var(--bg-input))', border: '1px solid var(--border)', boxShadow: '0 12px 30px rgba(0,0,0,0.25)' }}>
          {!hideView && item(<Eye size={13} />, 'View Profile', () => navigate(`/app/hr/candidates/${candidate.id}`))}
          {canManage ? (
            <>
              {item(<UserPlus size={13} />,        'Assign Recruiter',   () => { setRecruiterId(candidate.assigned_recruiter_id || ''); setModal('assign') })}
              {item(<ArrowRightCircle size={13} />, 'Move Stage',         () => { setStage(''); setModal('stage') })}
              {item(<CalendarPlus size={13} />,     'Schedule Interview', () => setModal('interview'))}
              {item(<XCircle size={13} />,          'Reject',             () => { setRejectReason(''); setModal('reject') }, true)}
            </>
          ) : (
            <p className="px-3 py-2 text-[10px]" style={{ color: 'var(--text-muted)' }}>View only — HR role required to act</p>
          )}
        </div>
      )}

      {/* ── Modals ─────────────────────────────────────────────── */}
      {modal && (
        <div className="modal-backdrop" onClick={closeAll}>
          <div className="modal-box max-w-md" onClick={e => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-black text-base" style={{ color: 'var(--text-h)' }}>
                {modal === 'assign' && 'Assign Recruiter'}
                {modal === 'stage' && 'Move Stage'}
                {modal === 'interview' && 'Schedule Interview'}
                {modal === 'reject' && 'Reject Candidate'}
              </h2>
              <button onClick={closeAll} style={{ color: 'var(--text-muted)' }}><X size={18} /></button>
            </div>
            <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>{candidate.name}</p>

            {modal === 'assign' && (
              <select className="input-3d text-sm w-full" value={recruiterId} onChange={e => setRecruiterId(e.target.value)}>
                <option value="">— Unassigned —</option>
                {recruiters.map(r => <option key={r.id} value={r.id}>{r.name}{r.internal_role ? ` · ${r.internal_role.replace(/_/g, ' ')}` : ''}</option>)}
              </select>
            )}

            {modal === 'stage' && (
              forwardStages.length ? (
                <div className="space-y-2">
                  {forwardStages.map(s => (
                    <button key={s} onClick={() => setStage(s)} className="w-full flex items-center gap-2 px-3 py-2.5 rounded-xl text-sm font-semibold transition-all"
                      style={{ border: `1px solid ${stage === s ? STAGE_COLORS[s] : 'var(--border)'}`, background: stage === s ? `${STAGE_COLORS[s]}18` : 'var(--bg-input)', color: stage === s ? STAGE_COLORS[s] : 'var(--text-h)' }}>
                      <span className="w-2 h-2 rounded-full" style={{ background: STAGE_COLORS[s] }} /> {s}
                    </button>
                  ))}
                </div>
              ) : <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Candidate is already at the final stage. Use Reject to decline.</p>
            )}

            {modal === 'interview' && (
              <div className="space-y-3">
                <div>
                  <label className="label">Round</label>
                  <select className="input-3d text-sm" value={round.round_name} onChange={e => setRound({ ...round, round_name: e.target.value })}>
                    {INTERVIEW_ROUNDS.map(r => <option key={r}>{r}</option>)}
                  </select>
                </div>
                <div>
                  <label className="label">Interviewer</label>
                  <input className="input-3d text-sm" placeholder="e.g. Vikram Singh" value={round.interviewer_name} onChange={e => setRound({ ...round, interviewer_name: e.target.value })} />
                </div>
                <div>
                  <label className="label">Date & Time *</label>
                  <input type="datetime-local" className="input-3d text-sm" value={round.scheduled_at} onChange={e => setRound({ ...round, scheduled_at: e.target.value })} />
                </div>
                <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>A Google Meet link is generated automatically for non-telephonic rounds.</p>
              </div>
            )}

            {modal === 'reject' && (
              <div>
                <label className="label">Reason (optional — saved to notes)</label>
                <textarea className="input-3d text-sm" rows={3} placeholder="e.g. Skills mismatch for the role" value={rejectReason} onChange={e => setRejectReason(e.target.value)} />
              </div>
            )}

            <div className="flex gap-3 pt-5">
              <button onClick={closeAll} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
              <button
                onClick={modal === 'assign' ? doAssign : modal === 'stage' ? doStage : modal === 'interview' ? doInterview : doReject}
                disabled={busy || (modal === 'stage' && !stage) || (modal === 'stage' && !forwardStages.length)}
                className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2"
                style={{ background: modal === 'reject' ? 'linear-gradient(135deg,#f87171,#ef4444)' : 'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity: busy ? 0.7 : 1 }}>
                {busy && <Loader2 size={14} className="animate-spin" />}
                {modal === 'assign' && 'Assign'}
                {modal === 'stage' && 'Move'}
                {modal === 'interview' && 'Schedule'}
                {modal === 'reject' && 'Confirm Reject'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
