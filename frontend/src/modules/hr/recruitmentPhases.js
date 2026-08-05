// ── #14 — "Highlight clearly to go to the next PHASE" ───────────────────────
//
// One map from any recruitment record's EXISTING status onto the nine-phase
// hiring pipeline, so six different screens agree on where a hire has reached.
//
// This is NOT a workflow engine. It stores nothing, decides nothing and can
// advance nothing — each module keeps its own states and its own transitions,
// and this only reads them. That is the whole point: a second engine would
// eventually disagree with the first about what "approved" means.

import { MR_STATUS, CANDIDATE_STAGES } from './constants'

export const PHASES = [
  { key: 'manpower',   label: 'Manpower Request', short: 'Request' },
  { key: 'approval',   label: 'Approval',         short: 'Approval' },
  { key: 'job',        label: 'Job Posting',      short: 'Job' },
  { key: 'candidate',  label: 'Candidate',        short: 'Candidate' },
  { key: 'screening',  label: 'Screening',        short: 'Screening' },
  { key: 'interview',  label: 'Interview',        short: 'Interview' },
  { key: 'offer',      label: 'Offer',            short: 'Offer' },
  { key: 'onboarding', label: 'Onboarding',       short: 'Onboarding' },
  { key: 'employee',   label: 'Employee',         short: 'Employee' },
]

export const phaseIndex = (key) => PHASES.findIndex(p => p.key === key)

// What to do next, per phase. Shown beside the pipeline so "next phase" is an
// instruction rather than just a highlighted box.
const NEXT_ACTION = {
  manpower:   'Submit the request for approval',
  approval:   'Approve at L1 and L2',
  job:        'Publish the job and start sourcing',
  candidate:  'Screen the applicants',
  screening:  'Shortlist and schedule interviews',
  interview:  'Complete the rounds and record feedback',
  offer:      'Release the offer and get it accepted',
  onboarding: 'Finish onboarding and verify documents',
  employee:   'Hire complete — nothing further',
}

export const nextActionFor = (key) => NEXT_ACTION[key] || null

/* ── Resolvers: one per module, each reading only that module's own state ── */

/** Manpower request → its phase. Rejected/closed are terminal, not phases. */
export function phaseFromManpower(status) {
  switch (status) {
    case MR_STATUS.DRAFT:              return { key: 'manpower', terminal: null }
    case MR_STATUS.L1_PENDING:
    case MR_STATUS.L2_PENDING:         return { key: 'approval', terminal: null }
    case MR_STATUS.READY_FOR_HR:
    case MR_STATUS.CONVERTED_TO_JD:    return { key: 'approval', terminal: null, complete: true }
    case MR_STATUS.JOB_POSTED:         return { key: 'job', terminal: null }
    case MR_STATUS.HIRING_IN_PROGRESS: return { key: 'candidate', terminal: null }
    case MR_STATUS.CLOSED:             return { key: 'employee', terminal: 'closed' }
    case MR_STATUS.REJECTED:           return { key: 'approval', terminal: 'rejected' }
    default:                           return { key: 'manpower', terminal: null }
  }
}

/** Job posting → its phase. A live job is already sourcing candidates. */
export function phaseFromJob(status) {
  if (['Published', 'Hiring', 'Partially_Filled'].includes(status)) return { key: 'candidate', terminal: null }
  if (['Closed', 'Filled'].includes(status)) return { key: 'employee', terminal: 'closed' }
  if (status === 'On_Hold') return { key: 'job', terminal: 'on_hold' }
  return { key: 'job', terminal: null }
}

/**
 * Candidate stage → its phase.
 *
 * Screening and Assessment both sit in the Screening phase: they are two steps
 * of the same "is this person worth interviewing" question.
 */
export function phaseFromCandidate(stage) {
  switch (stage) {
    case 'Applied':    return { key: 'candidate', terminal: null }
    case 'Screening':
    case 'Assessment': return { key: 'screening', terminal: null }
    case 'Interview':  return { key: 'interview', terminal: null }
    case 'Offer':      return { key: 'offer', terminal: null }
    case 'Hired':      return { key: 'employee', terminal: null, complete: true }
    case 'Rejected':   return { key: 'screening', terminal: 'rejected' }
    default:           return { key: 'candidate', terminal: null }
  }
}

/** An interview round is always the Interview phase; its result colours it. */
export function phaseFromInterview(round) {
  const result = (round?.result || '').toLowerCase()
  if (result === 'selected' || result === 'pass' || result === 'passed') {
    return { key: 'interview', terminal: null, complete: true }
  }
  if (result === 'rejected' || result === 'fail' || result === 'failed') {
    return { key: 'interview', terminal: 'rejected' }
  }
  return { key: 'interview', terminal: null }
}

/**
 * Offer status → phase. Values are HrOffer's own: Draft · Pending Approval ·
 * Approved · Generated · Sent · Viewed · Accepted · Declined · Rejected ·
 * Expired · Withdrawn · Completed.
 *
 * `Completed` is the terminal state HrOffer moves to once joining is confirmed
 * and the employee is created — so it is the END of the pipeline, not the offer
 * phase.
 */
export function phaseFromOffer(offer) {
  const s = (offer?.status || '').toLowerCase()
  if (s === 'completed') return { key: 'employee', terminal: null, complete: true }
  if (s === 'accepted') return { key: 'onboarding', terminal: null }
  if (['declined', 'rejected', 'withdrawn'].includes(s)) return { key: 'offer', terminal: 'rejected' }
  if (s === 'expired') return { key: 'offer', terminal: 'expired' }
  return { key: 'offer', terminal: null }
}

/** Onboarding status → phase. HrOnboarding uses: Pending · In Progress · Completed. */
export function phaseFromOnboarding(onboarding) {
  const s = (onboarding?.status || '').toLowerCase()
  if (s === 'completed') return { key: 'onboarding', terminal: null, complete: true }
  if (['cancelled', 'withdrawn'].includes(s)) return { key: 'onboarding', terminal: 'cancelled' }
  return { key: 'onboarding', terminal: null }
}

/** An employee record is the end of the pipeline by definition. */
export function phaseFromEmployee() {
  return { key: 'employee', terminal: null, complete: true }
}

/**
 * Resolve any supported record to { key, terminal, complete }.
 *
 * `complete` means the CURRENT phase is finished but the next has not started —
 * which is exactly the state the comment wants highlighted.
 */
export function resolvePhase(kind, record) {
  switch (kind) {
    case 'manpower':   return phaseFromManpower(record?.status)
    case 'job':        return phaseFromJob(record?.status)
    case 'candidate':  return phaseFromCandidate(record?.stage)
    case 'interview':  return phaseFromInterview(record)
    case 'offer':      return phaseFromOffer(record)
    case 'onboarding': return phaseFromOnboarding(record)
    case 'employee':   return phaseFromEmployee()
    default:           return { key: 'manpower', terminal: null }
  }
}

/** Ordered candidate stages, re-exported so screens need one import. */
export { CANDIDATE_STAGES }
