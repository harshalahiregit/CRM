// Shared, pure interview-sequence logic.
//
// This is the single source of truth for "where is the candidate in the interview
// loop and what happens next". The Interviews queue page, the Schedule/Feedback
// drawers and the Candidate Detail workspace all derive from these helpers, so the
// gate, the timeline and the primary CTA can never disagree with each other.
//
// Pure functions only — no API calls, no component state. The backend
// (InterviewService) remains the source of truth; this only mirrors it for UX.

import { INTERVIEW_ROUNDS, canonicalRound } from '@/modules/hr/constants'

/* ── Feedback model ──────────────────────────────────────────────────────── */

export const EMPTY_FB = {
  outcome: 'Hire', result: 'Passed', recommendation: 'Hire', rating: 0,
  technical_score: '', communication_score: '', problem_solving_score: '',
  knowledge_score: '', confidence_score: '', ownership_score: '', learning_ability_score: '',
  decision_making_score: '', leadership_score: '', integrity_score: '', culture_fit_score: '',
  strengths: '', concerns: '', notes: '',
  // Completion metadata (Complete-Interview form).
  attendance: 'Present', duration: '', remarks: '',
}

// Attendance recorded when an interview is completed.
export const ATTENDANCE_OPTIONS = [
  { key: 'Present', color: '#10b981' },
  { key: 'Absent',  color: '#ef4444' },
  { key: 'No Show', color: '#f59e0b' },
]

// Enterprise "evaluate, don't decide" model — the recruiter rates the interview; the
// system maps the outcome to the pipeline step (Next Round / Offer / Hold / Reject).
export const OUTCOMES = [
  { key: 'Strong Hire', rec: 'Strong Hire', positive: true,  dot: '🟢', color: '#10b981', blurb: 'Candidate performed exceptionally.' },
  { key: 'Hire',        rec: 'Hire',        positive: true,  dot: '🟢', color: '#10b981', blurb: 'Candidate is recommended.' },
  { key: 'Hold',        rec: 'Neutral',     positive: false, dot: '🟡', color: '#f59e0b', blurb: 'Candidate remains under review.' },
  { key: 'Reject',      rec: 'No Hire',     positive: false, dot: '🔴', color: '#ef4444', blurb: 'Candidate will be rejected.' },
]

// Reverse-map a stored interview back to an outcome (for editing existing feedback).
export const outcomeFromIv = (iv) => iv.result === 'Failed' ? 'Reject'
  : iv.result === 'On Hold' ? 'Hold'
  : iv.recommendation === 'Strong Hire' ? 'Strong Hire'
  : iv.recommendation === 'No Hire' ? 'Reject'
  : iv.recommendation === 'Neutral' ? 'Hold'
  : 'Hire'

// Structured interviewer ratings — recorded alongside the existing
// Technical / Communication / Problem Solving scores.
export const STRUCTURED_RATINGS = [
  { k: 'knowledge_score', l: 'Knowledge' }, { k: 'confidence_score', l: 'Confidence' },
  { k: 'ownership_score', l: 'Ownership' }, { k: 'learning_ability_score', l: 'Learning Ability' },
  { k: 'decision_making_score', l: 'Decision Making' }, { k: 'leadership_score', l: 'Leadership' },
  { k: 'integrity_score', l: 'Integrity' }, { k: 'culture_fit_score', l: 'Culture Fit' },
]

/* ── Panel helpers ───────────────────────────────────────────────────────── */

export const initialsOf = (name) => (name || '?').split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase()
export const desigOf = (u) => (u?.designation || (u?.role && u.role !== 'staff' ? String(u.role).replace(/_/g, ' ') : '') || '')

// Ensure a stored Lead (interviewer_name) is reflected inside the panel, flagged
// and first — so the Lead is always part of the Interview Panel (also for reschedule).
export const normalizeLead = (interviewers, leadName) => {
  let list = (interviewers || []).map(p => ({ ...p, is_lead: false }))
  const name = (leadName || '').trim()
  if (name) {
    const idx = list.findIndex(p => p.name === name)
    if (idx < 0) list = [{ name, email: '', is_lead: true }, ...list]
    else list[idx] = { ...list[idx], is_lead: true }
  }
  return [...list].sort((a, b) => (b.is_lead ? 1 : 0) - (a.is_lead ? 1 : 0))
}

/* ── Sequence + gate ─────────────────────────────────────────────────────── */

export const sortRounds = (rounds) => [...(rounds || [])]
  .sort((a, b) => new Date(a.scheduled_at) - new Date(b.scheduled_at))

// Next round in the configured interview sequence, or null when this is the final round.
export const nextRoundName = (roundName) => {
  const i = INTERVIEW_ROUNDS.indexOf(canonicalRound(roundName))
  return i >= 0 && i < INTERVIEW_ROUNDS.length - 1 ? INTERVIEW_ROUNDS[i + 1] : null
}

// Mirrors InterviewService::assertSequenceAllows so HR sees the block before
// submitting. `rounds` = this candidate's interviews.
export const blockingRoundsIn = (rounds, roundName, excludeId = null) => {
  const target = INTERVIEW_ROUNDS.indexOf(canonicalRound(roundName))
  if (target < 0) return []
  return sortRounds(rounds).filter(iv => {
    if (iv.status === 'Cancelled' || (excludeId && iv.id === excludeId)) return false
    const i = INTERVIEW_ROUNDS.indexOf(canonicalRound(iv.round_name))
    return i >= 0 && i < target && iv.status !== 'Completed'
  })
}

// Optional rounds may be skipped when the process does not require them. Add more
// round names here as the configured sequence evolves — nothing else needs changing.
export const OPTIONAL_ROUNDS = ['Client Round']
export const isOptionalRound = (roundName) => OPTIONAL_ROUNDS.includes(canonicalRound(roundName))

// A skipped round: closed as Completed with result "Skipped" — never Failed, never
// Cancelled. It satisfies the sequence gate so the next round unlocks immediately.
export const isSkipped = (iv) => iv?.result === 'Skipped'

// A paused round: feedback was recorded as "On Hold". Hold is reversible — it is
// never a final decision — but it must never unlock the next round.
export const isOnHold = (iv) => iv?.status === 'Completed' && iv?.result === 'On Hold'

// UX gate for a round:
//  ok         → schedule allowed
//  incomplete → an earlier round is still open (Scheduled) → complete it first
//  hold       → an earlier round is paused → resume it first
//  failed     → an earlier round Failed or was Cancelled → candidate cannot proceed
export const roundGateIn = (rounds, roundName, excludeId = null) => {
  const target = INTERVIEW_ROUNDS.indexOf(canonicalRound(roundName))
  if (target >= 0) {
    const earlier = sortRounds(rounds).filter(iv => {
      if (iv.id === excludeId) return false
      const i = INTERVIEW_ROUNDS.indexOf(canonicalRound(iv.round_name))
      return i >= 0 && i < target
    })
    const failed = earlier.find(iv => iv.status === 'Cancelled' || (iv.status === 'Completed' && iv.result === 'Failed'))
    if (failed) return { state: 'failed', round: failed }
    // Hold pauses the pipeline: later rounds stay locked until it is resumed and
    // completed with a positive outcome.
    const held = earlier.find(isOnHold)
    if (held) return { state: 'hold', round: held }
  }
  const blocked = blockingRoundsIn(rounds, roundName, excludeId)
  if (blocked.length) {
    const next = [...blocked].sort((a, b) => INTERVIEW_ROUNDS.indexOf(canonicalRound(a.round_name)) - INTERVIEW_ROUNDS.indexOf(canonicalRound(b.round_name)))[0]
    return { state: 'incomplete', round: next, all: blocked }
  }
  return { state: 'ok' }
}

/* ── Timeline + the single Next Action ───────────────────────────────────── */

// One row per configured round: Completed ✓ / Scheduled 🟡 / Current ● / Locked 🔒 / Failed ✕
export const buildTimeline = (rounds) => {
  const byRound = {}
  sortRounds(rounds).forEach(iv => { byRound[canonicalRound(iv.round_name)] = iv })
  const live = currentInterviewOf(rounds)
  const liveRound = live ? canonicalRound(live.round_name) : null
  return INTERVIEW_ROUNDS.map(r => {
    const iv = byRound[r]
    let state
    if (iv) {
      state = isSkipped(iv) ? 'skipped'
        : isOnHold(iv) ? 'hold'
        : iv.status === 'Completed' ? (iv.result === 'Failed' ? 'failed' : 'done')
        : iv.status === 'Cancelled' ? 'failed'
        : r === liveRound ? 'current' : 'scheduled'
    } else {
      // A round with no interview yet is simply upcoming → Locked (5-badge set).
      state = 'locked'
    }
    return { round: r, state, interview: iv || null }
  })
}

export const TIMELINE_STYLE = {
  done:      { mark: '✓',  color: '#10b981', label: 'Completed' },
  failed:    { mark: '✕',  color: '#f87171', label: 'Not cleared' },
  scheduled: { mark: '🟡', color: '#f59e0b', label: 'Scheduled' },
  current:   { mark: '●',  color: '#a78bfa', label: 'Current' },
  hold:      { mark: '🟡', color: '#f59e0b', label: 'On Hold' },
  skipped:   { mark: '⏭', color: 'var(--text-muted)', label: 'Skipped' },
  locked:    { mark: '🔒', color: 'var(--text-muted)', label: 'Locked' },
}

// The one interview the recruiter is working on right now: the earliest still-open
// round, otherwise the most recently completed one.
export const currentInterviewOf = (rounds) => {
  const list = sortRounds(rounds)
  const open = list.find(iv => iv.status !== 'Completed' && iv.status !== 'Cancelled')
  if (open) return open
  // A skipped round is history, not the current interview.
  const done = list.filter(iv => iv.status === 'Completed' && !isSkipped(iv))
  if (done.length) return done[done.length - 1]
  const rest = list.filter(iv => !isSkipped(iv))
  return rest.length ? rest[rest.length - 1] : null
}

// THE single primary CTA. Everything else on the screen is secondary.
// Returns { key, label, tone, interview, round, hint }.
//   key ∈ schedule | join | feedback | offer | rejected | resume
// Derived ONLY from backend fields (status / result / mode / meet_link). The frontend
// never invents a workflow state.
export const nextActionFor = (candidate, rounds) => {
  const list = sortRounds(rounds)
  const stage = candidate?.stage

  if (stage === 'Rejected') {
    const failed = [...list].reverse().find(iv => iv.result === 'Failed')
    return { key: 'rejected', label: 'View Rejection Reason', tone: 'bad', interview: failed || null, hint: 'Candidate was rejected — future rounds are locked.' }
  }
  if (candidate?.offer) return { key: 'offer', label: 'Open Offer', tone: 'good', hint: 'Offer already generated.' }

  const open = list.find(iv => iv.status !== 'Completed' && iv.status !== 'Cancelled')
  if (open) {
    // A Scheduled round. Joining is offered when there is something to join, but
    // joining is NOT a workflow event — only recorded feedback completes a round,
    // which is why "Complete Interview" is always available as a secondary action.
    const canJoin = open.mode !== 'offline' && !!open.meet_link
    if (canJoin) {
      return { key: 'join', label: 'Join Interview', tone: 'info', interview: open, hint: `${open.round_name} is scheduled. Joining does not complete the interview.` }
    }
    return { key: 'feedback', label: 'Complete Interview', tone: 'info', interview: open, hint: `${open.round_name} is scheduled — record the outcome to complete it.` }
  }

  const done = list.filter(iv => iv.status === 'Completed')
  if (!done.length) {
    const first = INTERVIEW_ROUNDS[0]
    return { key: 'schedule', label: `Schedule ${first}`, tone: 'info', round: first, hint: 'No interview scheduled yet.' }
  }

  // Take the round furthest along the CONFIGURED sequence, not the most recently
  // dated one: a skipped round can carry an earlier timestamp than the round before
  // it, which would otherwise send the recruiter back to the round they just skipped.
  const last = [...done].sort(
    (a, b) => INTERVIEW_ROUNDS.indexOf(canonicalRound(a.round_name)) - INTERVIEW_ROUNDS.indexOf(canonicalRound(b.round_name)),
  ).pop()
  if (last.result === 'Failed')  return { key: 'rejected', label: 'View Rejection Reason', tone: 'bad', interview: last, hint: `Candidate did not clear ${last.round_name}.` }
  // Hold is a pause, never a dead end — the recruiter can always resume.
  if (isOnHold(last)) return { key: 'resume', label: 'Resume Interview', tone: 'warn', interview: last, round: last.round_name, hint: `${last.round_name} is on hold — resume it to continue the process.` }

  const next = nextRoundName(last.round_name)
  if (last.result === 'Passed' || !next) {
    return { key: 'offer', label: 'Generate Offer', tone: 'good', hint: 'All interview rounds cleared.' }
  }
  return { key: 'schedule', label: `Schedule ${next}`, tone: 'info', round: next, hint: `${last.round_name} cleared.` }
}

export const TONE_STYLE = {
  good: { bg: 'linear-gradient(135deg,#10b981,#059669)', shadow: 'rgba(16,185,129,0.35)' },
  info: { bg: 'linear-gradient(135deg,#7C3AED,#5b21b6)', shadow: 'rgba(124,58,237,0.35)' },
  warn: { bg: 'linear-gradient(135deg,#f59e0b,#d97706)', shadow: 'rgba(245,158,11,0.35)' },
  bad:  { bg: 'linear-gradient(135deg,#ef4444,#b91c1c)', shadow: 'rgba(239,68,68,0.35)' },
}
