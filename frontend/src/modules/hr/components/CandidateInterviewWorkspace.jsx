import { useState, useMemo } from 'react'
import {
  CalendarClock, Video, MapPin, Users, Star, ChevronRight, ChevronDown,
  Rocket, Edit3, Ban, Eye, Link2, Play, CheckCircle2, SkipForward,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import ScheduleInterviewDrawer from '@/modules/hr/components/ScheduleInterviewDrawer'
import SkipRoundDialog from '@/modules/hr/components/SkipRoundDialog'
import InterviewFeedbackDrawer from '@/modules/hr/components/InterviewFeedbackDrawer'
import {
  buildTimeline, TIMELINE_STYLE, currentInterviewOf, nextActionFor, TONE_STYLE, isOnHold, isOptionalRound, isSkipped,
} from '@/modules/hr/components/interviewFlow'

const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const fmtTime = d => d ? new Date(d).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }) : ''

const Fact = ({ label, children }) => (
  <div>
    <p className="text-[9px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{label}</p>
    <div className="text-xs font-bold mt-0.5" style={{ color: 'var(--text-h)' }}>{children ?? '—'}</div>
  </div>
)

/**
 * The interview workspace inside Candidate Detail.
 *
 * Everything an interview needs happens here — schedule, reschedule, feedback,
 * cancel — through shared drawers. The recruiter never navigates to another page.
 *
 * Three blocks, in the order a recruiter reads them:
 *   1. Next Action   — the ONE primary CTA (the system decides it)
 *   2. Current Interview — the single live round
 *   3. Interview Timeline — the whole journey, expandable per round
 */
export default function CandidateInterviewWorkspace({
  candidate, rounds = [], canManage = false, showToast, onChanged, onOpenOffer,
}) {
  const [sched, setSched] = useState(null)   // { mode, interview, defaultRound }
  const [fbIv, setFbIv] = useState(null)
  const [fbReadOnly, setFbReadOnly] = useState(false)
  const [openRound, setOpenRound] = useState(null)
  const [busy, setBusy] = useState(false)
  const [skip, setSkip] = useState(null)          // { interview } while the dialog is open
  // Stable identity — an inline object here would remount/reset the feedback drawer
  // (and wipe its success state) on every parent re-render.
  const fbInterview = useMemo(
    () => (fbIv ? { ...fbIv, candidate: fbIv.candidate || candidate } : null),
    [fbIv, candidate],
  )

  const live = currentInterviewOf(rounds)
  const onHold = isOnHold(live)
  const next = nextActionFor(candidate, rounds)
  const timeline = buildTimeline(rounds)
  const tone = TONE_STYLE[next.tone] || TONE_STYLE.info

  const refresh = () => onChanged?.()

  /* ── The single primary CTA ── */
  const runNext = () => {
    switch (next.key) {
      case 'schedule':
        return setSched({ mode: 'schedule', defaultRound: next.round })
      case 'resume':
        // Re-opens the SAME round: InterviewService::reschedule() already flips the
        // status back to 'Scheduled' and writes the audit trail. No backend change.
        return setSched({ mode: 'reschedule', interview: next.interview })
      case 'feedback':
        setFbReadOnly(false); return setFbIv(next.interview)
      case 'join':
        // Opening the meeting is not a workflow event — it changes no state at all.
        return next.interview?.meet_link && window.open(next.interview.meet_link, '_blank', 'noopener')
      case 'offer':
        return onOpenOffer?.()
      case 'rejected':
        if (next.interview) { setFbReadOnly(true); setFbIv(next.interview) }
        return
      default:
        return
    }
  }

  const cancelInterview = async (iv) => {
    // eslint-disable-next-line no-alert
    const reason = window.prompt('Reason for cancelling this interview?')
    if (reason == null) return
    setBusy(true)
    try { await hrApi.interviews.cancel(iv.id, reason || 'Cancelled by HR'); showToast?.('Interview cancelled'); refresh() }
    catch (e) { showToast?.(e.response?.data?.message || 'Could not cancel', 'error') }
    finally { setBusy(false) }
  }

  const panelCount = (iv) => {
    const extra = Array.isArray(iv?.interviewers) ? iv.interviewers.filter(p => p?.name).length : 0
    return extra || (iv?.interviewer_name ? 1 : 0)
  }

  return (
    <div className="space-y-4">
      {/* ── 1. NEXT ACTION — one recommendation, one button ── */}
      <div className="card-3d" style={{ padding: 18 }}>
        <div className="flex items-center justify-between gap-4 flex-wrap">
          <div className="min-w-0">
            <p className="text-[9px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Next Action</p>
            <p className="text-sm font-black mt-1" style={{ color: 'var(--text-h)' }}>{next.label}</p>
            {next.hint && <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{next.hint}</p>}
          </div>
          {canManage && next.key !== 'hold' && (
            <button onClick={runNext} disabled={busy}
              className="flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold text-white flex-shrink-0"
              style={{ background: tone.bg, boxShadow: `0 4px 14px ${tone.shadow}` }}>
              {next.key === 'schedule' && <CalendarClock size={14} />}
              {next.key === 'feedback' && <CheckCircle2 size={14} />}
              {next.key === 'join' && <Video size={14} />}
              {next.key === 'offer' && <Rocket size={14} />}
              {next.key === 'resume' && <Play size={14} />}
              {next.key === 'rejected' && <Eye size={14} />}
              {next.label}
            </button>
          )}
        </div>
      </div>

      {/* ── 2. CURRENT INTERVIEW — exactly one highlighted card ── */}
      {live && (
        <div className="card-3d" style={{ padding: 18, borderLeft: `3px solid ${onHold ? '#f59e0b' : '#a78bfa'}` }}>
          <div className="flex items-center justify-between mb-4 flex-wrap gap-2">
            <h3 className="font-bold text-sm flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
              {onHold
                ? <><span style={{ fontSize: 13 }}>🟡</span> Candidate On Hold</>
                : <><CalendarClock size={14} style={{ color: '#a78bfa' }} /> Current Interview</>}
            </h3>
            <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg"
              style={{ background: onHold ? 'rgba(245,158,11,0.14)' : 'rgba(124,58,237,0.12)', color: onHold ? '#f59e0b' : '#a78bfa' }}>
              {/* Only a completed round reports an outcome — a resumed round keeps a
                  stale `result` in the payload, which must not be shown. */}
              {onHold ? 'Paused — reversible'
                : live.status === 'Completed' && live.result && live.result !== 'Pending'
                  ? `${live.status} · ${live.result}`
                  : live.status}
            </span>
          </div>

          <div className="grid grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-3">
            <Fact label="Round">{live.round_name}</Fact>
            <Fact label="Date">{fmtDate(live.scheduled_at)}{live.scheduled_at ? <span style={{ color: 'var(--text-muted)', fontWeight: 600 }}> · {fmtTime(live.scheduled_at)}</span> : null}</Fact>
            <Fact label="Lead Interviewer">{live.interviewer_name || '—'}</Fact>
            <Fact label="Panel">{panelCount(live) ? `${panelCount(live)} member${panelCount(live) > 1 ? 's' : ''}` : '—'}</Fact>
            <Fact label="Mode">{live.mode === 'offline'
              ? <span className="inline-flex items-center gap-1"><MapPin size={11} /> Offline</span>
              : <span className="inline-flex items-center gap-1"><Video size={11} /> Online</span>}</Fact>
            {onHold && <Fact label="Hold Date">{fmtDate(live.updated_at || live.completed_at || live.scheduled_at)}</Fact>}
            <Fact label={live.mode === 'offline' ? 'Venue' : 'Meeting'}>
              {live.mode === 'offline'
                ? (live.venue || '—')
                : live.meet_link
                  ? <a href={live.meet_link} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1" style={{ color: '#60a5fa' }}><Link2 size={11} /> Join link</a>
                  : 'Not generated'}
            </Fact>
          </div>

          {/* Hold reason — recorded with the feedback that paused the round */}
          {onHold && (
            <div className="mt-3 px-3 py-2 rounded-xl" style={{ background: 'rgba(245,158,11,0.08)', border: '1px solid rgba(245,158,11,0.28)' }}>
              <p className="text-[9px] font-bold uppercase tracking-wide" style={{ color: '#f59e0b' }}>Reason for Hold</p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-h)' }}>{live.concerns || live.notes || 'No reason recorded.'}</p>
            </div>
          )}

          {/* Secondary actions while paused — the Resume CTA lives above */}
          {canManage && onHold && (
            <div className="flex gap-2 mt-4 pt-3 flex-wrap" style={{ borderTop: '1px solid var(--border)' }}>
              <button onClick={() => { setFbReadOnly(false); setFbIv(live) }} disabled={busy}
                className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-[11px] font-bold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                <Edit3 size={12} /> Edit Feedback
              </button>
              <button onClick={() => { setFbReadOnly(true); setFbIv(live) }} disabled={busy}
                className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-[11px] font-bold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                <Eye size={12} /> View Feedback
              </button>
            </div>
          )}

          {/* Secondary actions — derived ONLY from the backend status.
              "Complete Interview" is always available on a Scheduled round: the
              recruiter decides when the interview has finished, and recording
              feedback is the single operation that completes it. */}
          {canManage && !onHold && live.status !== 'Completed' && live.status !== 'Cancelled' && (
            <div className="flex gap-2 mt-4 pt-3 flex-wrap items-center" style={{ borderTop: '1px solid var(--border)' }}>
              <button onClick={() => { setFbReadOnly(false); setFbIv(live) }} disabled={busy}
                className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-[11px] font-bold" style={{ background: 'rgba(16,185,129,0.1)', color: '#10b981', border: '1px solid rgba(16,185,129,0.25)' }}>
                <CheckCircle2 size={12} /> Complete Interview
              </button>
              <button onClick={() => setSched({ mode: 'reschedule', interview: live })} disabled={busy}
                className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-[11px] font-bold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                <Edit3 size={12} /> Reschedule
              </button>
              {/* Skip: OPTIONAL round only (Client Round), still Scheduled, no outcome
                  recorded yet. Mandatory rounds and Completed / Cancelled / Failed
                  interviews never reach this branch. */}
              {isOptionalRound(live.round_name)
                && live.status === 'Scheduled'
                && (!live.result || live.result === 'Pending') && (
                <button onClick={() => setSkip({ interview: live })} disabled={busy}
                  className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-[11px] font-bold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                  <SkipForward size={12} /> Skip {live.round_name}
                </button>
              )}
              <button onClick={() => cancelInterview(live)} disabled={busy}
                className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-[11px] font-bold" style={{ background: 'rgba(239,68,68,0.08)', color: '#f87171', border: '1px solid rgba(239,68,68,0.2)' }}>
                <Ban size={12} /> Cancel
              </button>
            </div>
          )}
        </div>
      )}

      {/* ── 3. INTERVIEW TIMELINE — the whole journey, expandable in place ── */}
      <div className="card-3d" style={{ padding: 18 }}>
        <h3 className="font-bold text-sm mb-4 flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
          <Users size={14} style={{ color: '#a78bfa' }} /> Interview Timeline
        </h3>
        <div className="space-y-1.5">
          {timeline.map(({ round, state, interview }, i) => {
            const s = TIMELINE_STYLE[state]
            const expandable = !!interview
            const isOpen = openRound === round
            return (
              <div key={round}>
                <button
                  onClick={() => expandable && setOpenRound(isOpen ? null : round)}
                  className="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-left"
                  style={{
                    background: state === 'current' ? 'rgba(124,58,237,0.08)' : 'var(--bg-input)',
                    border: `1px solid ${state === 'current' ? 'rgba(124,58,237,0.3)' : 'var(--border)'}`,
                    cursor: expandable ? 'pointer' : 'default',
                  }}>
                  <span style={{ fontSize: 13, width: 18, flexShrink: 0 }}>{s.mark}</span>
                  <span className="flex-1 min-w-0">
                    <span className="block text-xs font-bold truncate" style={{ color: state === 'locked' ? 'var(--text-muted)' : 'var(--text-h)' }}>{round}</span>
                    {interview?.scheduled_at && <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{fmtDate(interview.scheduled_at)}{interview.interviewer_name ? ` · ${interview.interviewer_name}` : ''}</span>}
                  </span>
                  <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg flex-shrink-0" style={{ color: s.color, background: state === 'locked' ? 'transparent' : `${s.color}1a` }}>
                    {/* Completed → the outcome; open → its status; none yet → Locked.
                        The mark + highlight already say which one is current. */}
                    {!interview ? s.label
                      : interview.status === 'Completed'
                        ? (interview.result && interview.result !== 'Pending' ? interview.result : 'Completed')
                        : interview.status}
                  </span>
                  {expandable && (isOpen ? <ChevronDown size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} /> : <ChevronRight size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />)}
                </button>

                {/* Accordion — the round's feedback, in place. Never another page. */}
                {isOpen && interview && (
                  <div className="mx-3 mt-1 mb-2 px-3 py-3 rounded-xl" style={{ background: 'var(--bg-card,transparent)', border: '1px solid var(--border)' }}>
                    <div className="flex flex-wrap gap-3 mb-2">
                      {interview.overall_score != null && <span className="text-[10.5px]" style={{ color: 'var(--text-muted)' }}>Overall: <b style={{ color: '#a78bfa' }}>{interview.overall_score}%</b></span>}
                      {interview.technical_score != null && <span className="text-[10.5px]" style={{ color: 'var(--text-muted)' }}>Tech: <b style={{ color: 'var(--text-h)' }}>{interview.technical_score}/10</b></span>}
                      {interview.communication_score != null && <span className="text-[10.5px]" style={{ color: 'var(--text-muted)' }}>Comm: <b style={{ color: 'var(--text-h)' }}>{interview.communication_score}/10</b></span>}
                      {interview.problem_solving_score != null && <span className="text-[10.5px]" style={{ color: 'var(--text-muted)' }}>Problem: <b style={{ color: 'var(--text-h)' }}>{interview.problem_solving_score}/10</b></span>}
                      {interview.recommendation && <span className="text-[10px] font-bold px-1.5 py-0.5 rounded" style={{ background: 'rgba(124,58,237,0.12)', color: '#a78bfa' }}>{interview.recommendation}</span>}
                    </div>
                    {interview.strengths && <p className="text-[11px]" style={{ color: '#10b981' }}>✓ Strengths: <span style={{ color: 'var(--text-muted)' }}>{interview.strengths}</span></p>}
                    {interview.concerns && <p className="text-[11px] mt-0.5" style={{ color: '#f87171' }}>△ Concerns: <span style={{ color: 'var(--text-muted)' }}>{interview.concerns}</span></p>}
                    {interview.notes && <p className="text-[11px] mt-1 italic" style={{ color: 'var(--text-muted)' }}>"{interview.notes}"</p>}
                    {!interview.strengths && !interview.concerns && !interview.notes && interview.status !== 'Completed' && (
                      <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>No feedback recorded yet.</p>
                    )}
                    {canManage && interview.status === 'Completed' && (
                      <button onClick={() => { setFbReadOnly(true); setFbIv(interview) }} className="mt-2 text-[10.5px] font-bold" style={{ color: '#a78bfa', background: 'none', border: 'none', cursor: 'pointer', padding: 0 }}>
                        View full feedback →
                      </button>
                    )}
                  </div>
                )}
                {i < timeline.length - 1 && <div style={{ height: 6 }} />}
              </div>
            )
          })}
        </div>
      </div>

      {/* The one shared skip dialog — also mounted inside the Schedule drawer */}
      <SkipRoundDialog
        open={!!skip}
        roundName={skip?.interview?.round_name}
        interview={skip?.interview || null}
        onClose={() => setSkip(null)}
        onDone={refresh}
        showToast={showToast}
      />

      {/* Shared drawers — the same components the Interviews queue uses */}
      <ScheduleInterviewDrawer
        open={!!sched}
        mode={sched?.mode || 'schedule'}
        interview={sched?.interview || null}
        candidateId={candidate?.id}
        defaultRound={sched?.defaultRound || null}
        interviews={rounds.map(r => ({ ...r, candidate_id: r.candidate_id ?? candidate?.id }))}
        showSequence={false}
        onClose={() => setSched(null)}
        onSaved={() => { setSched(null); refresh() }}
        onOpenFeedback={(iv) => { setSched(null); setFbReadOnly(false); setFbIv(iv) }}
        onResume={(iv) => setSched({ mode: 'reschedule', interview: iv })}
        onSkipped={() => { setSched(null); refresh() }}
        showToast={showToast}
      />

      <InterviewFeedbackDrawer
        open={!!fbIv}
        interview={fbInterview}
        readOnly={fbReadOnly}
        onClose={() => { setFbIv(null); setFbReadOnly(false) }}
        onSaved={refresh}
        onSchedule={(round) => setSched({ mode: 'schedule', defaultRound: round })}
        onOffer={() => onOpenOffer?.()}
        showToast={showToast}
      />
    </div>
  )
}
