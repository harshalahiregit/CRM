import { useState, useEffect } from 'react'
import { Loader2, Rocket, CalendarClock } from 'lucide-react'
import Drawer from '@/components/ui/Drawer'
import StarRating from '@/modules/hr/components/StarRating'
import { hrApi } from '@/services/hrApi'
import {
  EMPTY_FB, OUTCOMES, outcomeFromIv, STRUCTURED_RATINGS, nextRoundName,
} from '@/modules/hr/components/interviewFlow'

/**
 * Interview Feedback — the ONLY place interview feedback is captured.
 *
 * Mounted by both the Interviews queue and the Candidate Detail workspace, so the
 * recruiter never navigates to another page to record an outcome.
 *
 * The recruiter only EVALUATES (Strong Hire / Hire / Hold / Reject); this component
 * maps the outcome onto the existing backend result WITHOUT touching the stage engine:
 *   • Strong Hire / Hire, non-final round → "Next Round"  (stays in Interview)
 *   • Strong Hire / Hire, final round     → "Passed"      (→ Offer stage)
 *   • Hold                                → "On Hold"     (stays)
 *   • Reject                              → "Failed"      (→ Rejected)
 *
 * After a positive outcome it switches to a "what next" step with a single CTA
 * (Schedule next round / Proceed to Offer) instead of opening a second modal.
 */
export default function InterviewFeedbackDrawer({
  open, interview, onClose, onSaved, onSchedule, onOffer, showToast, readOnly = false,
}) {
  const [fb, setFb] = useState(EMPTY_FB)
  const [saving, setSaving] = useState(false)
  const [done, setDone] = useState(null)   // { mode:'schedule'|'offer', nextRound, name }

  // Load the interview into the form when the drawer opens for a DIFFERENT round.
  // Keyed on the interview id (not object identity) so a parent re-render — e.g. the
  // candidate reloading after save — can never wipe the success state.
  useEffect(() => {
    if (!open || !interview) return
    setDone(null)
    setFb({
      ...EMPTY_FB,
      outcome: outcomeFromIv(interview),
      rating: interview.rating || 0,
      technical_score: interview.technical_score ?? '',
      communication_score: interview.communication_score ?? '',
      problem_solving_score: interview.problem_solving_score ?? '',
      ...Object.fromEntries(STRUCTURED_RATINGS.map(s => [s.k, interview[s.k] ?? ''])),
      strengths: interview.strengths || '', concerns: interview.concerns || '', notes: interview.notes || '',
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, interview?.id])

  if (!open || !interview) return null

  const nextRound = nextRoundName(interview.round_name)
  const isFinal = nextRound === null
  const oc = OUTCOMES.find(o => o.key === fb.outcome) || OUTCOMES[1]

  const overallPreview = (() => {
    const vals = [fb.technical_score, fb.communication_score, fb.problem_solving_score].filter(Boolean).map(Number)
    return vals.length ? Math.round((vals.reduce((a, b) => a + b, 0) / vals.length) * 10) : null
  })()

  const submit = async () => {
    if (fb.outcome === 'Reject' && !(fb.concerns?.trim() || fb.notes?.trim())) {
      return showToast?.('Please add a reason for rejection.', 'error')
    }
    const backendResult = fb.outcome === 'Reject' ? 'Failed'
      : fb.outcome === 'Hold' ? 'On Hold'
      : isFinal ? 'Passed' : 'Next Round'
    setSaving(true)
    try {
      const payload = {
        result: backendResult, recommendation: oc.rec, rating: fb.rating || undefined, notes: fb.notes, status: 'Completed',
        technical_score: fb.technical_score ? Number(fb.technical_score) : undefined,
        communication_score: fb.communication_score ? Number(fb.communication_score) : undefined,
        problem_solving_score: fb.problem_solving_score ? Number(fb.problem_solving_score) : undefined,
        ...Object.fromEntries(STRUCTURED_RATINGS.map(s => [s.k, fb[s.k] ? Number(fb[s.k]) : undefined])),
        strengths: fb.strengths || undefined,
        concerns: fb.concerns || undefined,
      }
      const updated = await hrApi.interviews.recordFeedback(interview.id, payload)
      onSaved?.(updated)
      // The success state NEVER auto-dismisses — it stays until the recruiter
      // clicks a button. No setTimeout, no auto-close, no auto-navigate.
      setDone({
        outcome: oc, rating: fb.rating, overall: overallPreview,
        round: interview.round_name, name: interview.candidate?.name, nextRound,
        mode: fb.outcome === 'Reject' ? 'rejected'
          : fb.outcome === 'Hold' ? 'hold'
          : isFinal ? 'offer' : 'schedule',
      })
    } catch (e) { showToast?.(e.response?.data?.message || 'Failed to save feedback', 'error') }
    finally { setSaving(false) }
  }

  /* ── Interview Completed — a persistent confirmation. It stays on screen until
        the recruiter chooses an action; nothing here dismisses it automatically. ── */
  if (done) {
    const nextLabel = done.mode === 'schedule' ? `Schedule ${done.nextRound}`
      : done.mode === 'offer' ? 'Generate Offer'
      : done.mode === 'hold' ? 'No further action — candidate stays under review'
      : 'No further action — future rounds are locked'
    const Row = ({ label, children }) => (
      <div className="flex items-center justify-between px-3 py-2 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
        <span className="text-[10px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{label}</span>
        <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>{children}</span>
      </div>
    )
    return (
      <Drawer
        open onClose={onClose} title="Interview Completed" width="min(600px, 95vw)"
        footer={(
          <div className="flex gap-3">
            <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Close</button>
            {done.mode === 'schedule' && (
              <button onClick={() => { onClose?.(); onSchedule?.(done.nextRound) }} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
                <CalendarClock size={14} /> Schedule {done.nextRound}
              </button>
            )}
            {done.mode === 'offer' && (
              <button onClick={() => { onClose?.(); onOffer?.() }} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background: 'linear-gradient(135deg,#10b981,#059669)' }}>
                <Rocket size={14} /> Generate Offer
              </button>
            )}
          </div>
        )}
      >
        <div className="text-center pt-2 pb-5">
          <div style={{ fontSize: 40, lineHeight: 1 }}>{done.mode === 'offer' ? '🎉' : done.mode === 'rejected' ? '🔴' : done.mode === 'hold' ? '🟡' : '✅'}</div>
          <h3 className="font-black text-lg mt-3" style={{ color: 'var(--text-h)' }}>Interview Completed</h3>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            <b style={{ color: 'var(--text-h)' }}>{done.name}</b> · {done.round}
          </p>
        </div>

        <div className="space-y-2">
          <Row label="Outcome"><span style={{ color: done.outcome.color }}>{done.outcome.dot} {done.outcome.key}</span></Row>
          <Row label="Overall Rating">
            {done.rating ? <span className="inline-flex items-center gap-2"><StarRating value={done.rating} readOnly /> <span>{done.rating}/5</span></span> : '—'}
          </Row>
          {done.overall !== null && <Row label="Overall Score"><span style={{ color: '#a78bfa' }}>{done.overall}%</span></Row>}
          <Row label="Timeline"><span style={{ color: '#10b981' }}>✓ Updated</span></Row>
        </div>

        <div className="mt-4 rounded-xl px-3 py-3" style={{ background: 'rgba(124,58,237,0.08)', border: '1px solid rgba(124,58,237,0.3)' }}>
          <p className="text-[9px] font-bold uppercase tracking-wide mb-1" style={{ color: '#a78bfa' }}>Next Recommended Action</p>
          <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{nextLabel}</p>
        </div>
      </Drawer>
    )
  }

  const set = (k) => (e) => setFb(f => ({ ...f, [k]: e.target.value }))

  return (
    <Drawer
      open onClose={onClose}
      title={readOnly ? 'Interview Feedback' : 'Submit Feedback'}
      width="min(680px, 95vw)"
      footer={!readOnly && (
        <div className="flex gap-3">
          <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          <button onClick={submit} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity: saving ? 0.7 : 1 }}>
            {saving && <Loader2 size={14} className="animate-spin" />}Submit Feedback
          </button>
        </div>
      )}
    >
      <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
        {interview.candidate?.name} · <b style={{ color: 'var(--text-h)' }}>{interview.round_name}</b>
      </p>

      <div className="space-y-3">
        {/* Interview Outcome — the recruiter evaluates; the system decides the next step */}
        <div>
          <label className="label">Interview Outcome *</label>
          <div className="grid grid-cols-2 gap-2">
            {OUTCOMES.map(o => {
              const sel = fb.outcome === o.key
              return (
                <button key={o.key} type="button" disabled={readOnly} onClick={() => setFb(f => ({ ...f, outcome: o.key }))}
                  className="flex items-center gap-2 px-3 py-2.5 rounded-xl text-sm font-bold transition-all"
                  style={{ background: sel ? `${o.color}1a` : 'var(--bg-input)', color: sel ? o.color : 'var(--text-muted)', border: `1.5px solid ${sel ? o.color : 'var(--border)'}` }}>
                  <span style={{ fontSize: 14 }}>{o.dot}</span> {o.key}
                </button>
              )
            })}
          </div>
          <p className="text-[9.5px] mt-1.5" style={{ color: 'var(--text-muted)' }}>Evaluate the interview — the system decides the next pipeline step.</p>
        </div>

        {/* Live Interview Outcome Summary */}
        <div className="rounded-xl px-3 py-2.5" style={{ background: `${oc.color}12`, border: `1px solid ${oc.color}40` }}>
          <p className="text-[9px] font-bold uppercase tracking-wide mb-1" style={{ color: oc.color }}>Interview Outcome Summary</p>
          <p className="text-[11px] font-semibold" style={{ color: oc.color }}>{oc.dot} {oc.blurb}</p>
          {oc.positive && <p className="text-[10.5px] mt-1" style={{ color: 'var(--text-muted)' }}>Next step: <b style={{ color: 'var(--text-h)' }}>{nextRound || 'Offer Stage'}</b></p>}
          {oc.key === 'Hold' && <p className="text-[10.5px] mt-1" style={{ color: 'var(--text-muted)' }}>Candidate stays in the Interview stage — nothing progresses.</p>}
          {oc.key === 'Reject' && <p className="text-[10.5px] mt-1" style={{ color: 'var(--text-muted)' }}>Future interview rounds will be <b>locked</b>. Reason for rejection is required.</p>}
        </div>

        <div>
          <label className="label">Overall Rating</label>
          <StarRating value={fb.rating} readOnly={readOnly} onChange={n => setFb(f => ({ ...f, rating: n }))} />
        </div>

        <p className="text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>Scores (out of 10)</p>
        <div className="grid grid-cols-3 gap-2">
          {[{ k: 'technical_score', l: 'Technical' }, { k: 'communication_score', l: 'Communication' }, { k: 'problem_solving_score', l: 'Problem Solving' }].map(s => (
            <div key={s.k}><label className="label">{s.l}</label><input type="number" min="0" max="10" disabled={readOnly} className="input-3d text-sm" placeholder="0-10" value={fb[s.k]} onChange={set(s.k)} /></div>
          ))}
        </div>
        {overallPreview !== null && (
          <div className="px-3 py-2 rounded-xl text-center" style={{ background: 'rgba(124,58,237,0.1)' }}>
            <span className="text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>Overall Score: </span>
            <span className="font-black text-sm" style={{ color: '#a78bfa' }}>{overallPreview}%</span>
          </div>
        )}

        <p className="text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>Structured Ratings (out of 10)</p>
        <div className="grid grid-cols-4 gap-2">
          {STRUCTURED_RATINGS.map(s => (
            <div key={s.k}><label className="label">{s.l}</label><input type="number" min="0" max="10" disabled={readOnly} className="input-3d text-sm" placeholder="0-10" value={fb[s.k]} onChange={set(s.k)} /></div>
          ))}
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div><label className="label">Strengths</label><textarea rows={2} disabled={readOnly} className="input-3d text-sm resize-none" placeholder="What stood out?" value={fb.strengths} onChange={set('strengths')} /></div>
          <div>
            <label className="label">{fb.outcome === 'Reject' ? 'Reason for Rejection *' : 'Concerns'}</label>
            <textarea rows={2} disabled={readOnly} className="input-3d text-sm resize-none"
              placeholder={fb.outcome === 'Reject' ? 'Required — why is the candidate rejected?' : 'Any reservations?'}
              value={fb.concerns} onChange={set('concerns')}
              style={fb.outcome === 'Reject' && !fb.concerns?.trim() ? { borderColor: 'rgba(248,113,113,0.5)' } : undefined} />
          </div>
        </div>

        <div><label className="label">Comments</label><textarea rows={2} disabled={readOnly} className="input-3d text-sm resize-none" value={fb.notes} onChange={set('notes')} /></div>
      </div>
    </Drawer>
  )
}
