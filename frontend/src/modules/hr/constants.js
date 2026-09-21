// ── HR Recruitment shared constants ─────────────────────────────────────────
// Single source of truth for Manpower Request statuses, labels and colours so
// the queue, the dashboard and any badge render identically. Keys match the
// backend App\Support\Hr\ManpowerRequestStatus values.

export const MR_STATUS = {
  DRAFT:              'Draft',
  L1_PENDING:         'L1_Pending',
  L2_PENDING:         'L2_Pending',
  READY_FOR_HR:       'Ready_for_HR',
  CONVERTED_TO_JD:    'Converted_to_JD',
  JOB_POSTED:         'Job_Posted',
  HIRING_IN_PROGRESS: 'Hiring_in_Progress',
  CLOSED:             'Closed',
  REJECTED:           'Rejected',
}

// status → { label, color, bg }
export const STATUS_CONFIG = {
  Draft:              { label: 'Draft',              color: '#6b7280', bg: 'rgba(107,114,128,0.15)' },
  L1_Pending:         { label: 'L1 Pending',         color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  L2_Pending:         { label: 'L2 Pending',         color: '#8b5cf6', bg: 'rgba(139,92,246,0.15)' },
  Ready_for_HR:       { label: 'Ready for HR',       color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  Converted_to_JD:    { label: 'Converted to JD',    color: '#6366f1', bg: 'rgba(99,102,241,0.15)' },
  Job_Posted:         { label: 'Job Posted',         color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Hiring_in_Progress: { label: 'Hiring in Progress', color: '#14b8a6', bg: 'rgba(20,184,166,0.15)' },
  Closed:             { label: 'Closed',             color: '#64748b', bg: 'rgba(100,116,139,0.15)' },
  Rejected:           { label: 'Rejected',           color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  // Legacy aliases (pre-enhancement data / back-compat)
  Pending_L1:         { label: 'L1 Pending',         color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Pending_L2:         { label: 'L2 Pending',         color: '#8b5cf6', bg: 'rgba(139,92,246,0.15)' },
  Approved:           { label: 'Ready for HR',       color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  Pending:            { label: 'Pending',            color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
}

export const statusLabel = (s) => STATUS_CONFIG[s]?.label || s || '—'
export const statusColor = (s) => STATUS_CONFIG[s] || STATUS_CONFIG.Draft

export const PRIORITY_COLORS = { Critical: '#b91c1c', High: '#ef4444', Medium: '#f59e0b', Low: '#10b981' }

// ── Job Posting lifecycle (mirrors backend App\Support\Hr\JobPostingStatus) ──
export const JOB_STATUS = {
  DRAFT:            'Draft',
  READY_FOR_HR:     'Ready_for_HR',
  PUBLISHED:        'Published',
  HIRING:           'Hiring',
  PARTIALLY_FILLED: 'Partially_Filled',
  COMPLETED:        'Completed',
  CLOSED:           'Closed',
  CANCELLED:        'Cancelled',
  ON_HOLD:          'On_Hold',
}

export const JOB_STATUS_CONFIG = {
  Draft:            { label: 'Draft',            color: '#6b7280', bg: 'rgba(107,114,128,0.15)' },
  Ready_for_HR:     { label: 'Ready for HR',     color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  Published:        { label: 'Published',        color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Hiring:           { label: 'Hiring',           color: '#14b8a6', bg: 'rgba(20,184,166,0.15)' },
  Partially_Filled: { label: 'Partially Filled', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Completed:        { label: 'Completed',        color: '#22c55e', bg: 'rgba(34,197,94,0.15)' },
  Closed:           { label: 'Closed',           color: '#64748b', bg: 'rgba(100,116,139,0.15)' },
  Cancelled:        { label: 'Cancelled',        color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  On_Hold:          { label: 'On Hold',          color: '#a855f7', bg: 'rgba(168,85,247,0.15)' },
  Active:           { label: 'Published',        color: '#10b981', bg: 'rgba(16,185,129,0.15)' }, // legacy alias
}

// ── Candidate & interview vocab (shared with Candidates/Interviews pages) ────
export const CANDIDATE_STAGES = ['Applied', 'Screening', 'Assessment', 'Interview', 'Offer', 'Hired', 'Rejected']
export const STAGE_COLORS = { Applied: '#3b82f6', Screening: '#f59e0b', Assessment: '#a855f7', Interview: '#6366f1', Offer: '#10b981', Hired: '#059669', Rejected: '#ef4444' }
export const DECISION_COLORS = { Selected: '#10b981', Hold: '#f59e0b', Rejected: '#ef4444', Pending: '#6b7280' }
export const INTERVIEW_STATUS_COLORS = { Scheduled: '#f59e0b', Completed: '#10b981', Cancelled: '#ef4444', Rescheduled: '#8b5cf6' }
export const INTERVIEW_RESULT_COLORS = { Passed: '#10b981', Failed: '#ef4444', Pending: '#f59e0b', 'On Hold': '#fbbf24', 'Next Round': '#6366f1' }

// Standard interview pipeline rounds (round_name is free-text; these are the presets).
// SPK-1 interview sequence: HR → Technical → Manager → Client → Final.
export const INTERVIEW_ROUNDS = ['HR Round', 'Technical Round', 'Manager Round', 'Client Round', 'Final Round']
export const ROUND_COLORS = {
  'HR Round': '#7C3AED', 'Technical Round': '#3b82f6', 'Manager Round': '#f59e0b',
  'Client Round': '#06b6d4', 'Final Round': '#10b981',
  // Legacy round names — already-scheduled interviews keep their colour/label.
  'HR Screening': '#7C3AED', Technical: '#3b82f6', 'Technical L2': '#0ea5e9', Managerial: '#f59e0b', 'HR Final': '#10b981',
}
export const roundColor = (r) => ROUND_COLORS[r] || '#7C3AED'
// Map a free-text round_name onto the SPK-1 sequence so historic rounds
// ("Technical L1", "Manager L2", "HR Screening", …) still line up on the tracker.
// Pattern-based rather than an exact list — round_name has always been free text.
export const canonicalRound = (r) => {
  if (!r) return r
  if (INTERVIEW_ROUNDS.includes(r)) return r
  const s = String(r).toLowerCase()
  if (s.includes('final')) return 'Final Round'          // before HR/manager: "HR Final"
  if (s.includes('client')) return 'Client Round'
  if (s.includes('manager') || s.includes('managerial')) return 'Manager Round'
  if (s.includes('technical') || s.includes('tech')) return 'Technical Round'
  if (s.includes('hr') || s.includes('screening')) return 'HR Round'
  return r                                                // unknown → stays off the tracker
}
export const INTERVIEW_MODES = ['online', 'offline']
export const INTERVIEW_RESULTS = ['Pending', 'Passed', 'Failed', 'On Hold', 'Next Round']
export const RECOMMENDATIONS = ['Strong Hire', 'Hire', 'Neutral', 'No Hire']
export const RECOMMENDATION_COLORS = { 'Strong Hire': '#059669', Hire: '#10b981', Neutral: '#f59e0b', 'No Hire': '#ef4444' }

// Candidate sources — used for the Add form dropdown and the source badge.
export const CANDIDATE_SOURCES = ['LinkedIn', 'Naukri', 'Career Page', 'Internal Portal', 'Employee Referral', 'Walk-in', 'Direct']
export const SOURCE_COLORS = {
  LinkedIn: '#0077b5', Naukri: '#f97316', 'Career Page': '#7C3AED', 'Internal Portal': '#3b82f6',
  'Employee Referral': '#10b981', 'Walk-in': '#6b7280', Direct: '#8b5cf6',
}
export const sourceColor = (s) => SOURCE_COLORS[s] || '#7C3AED'

// Candidate document categories — mirrors HrCandidateDocument::TYPES on the backend.
export const DOCUMENT_TYPES = [
  { key: 'resume',      label: 'Resume' },
  { key: 'offer',       label: 'Offer Letter' },
  { key: 'id_proof',    label: 'ID Proof' },
  { key: 'certificate', label: 'Certificate' },
  { key: 'other',       label: 'Other' },
]
export const documentTypeLabel = (t) => DOCUMENT_TYPES.find(d => d.key === t)?.label || 'Other'

// Onboarding document checklist. Shared by the Onboarding page and the
// Candidate 360° onboarding stage so both read the same items and labels.
export const ONBOARDING_DOC_ITEMS = ['offer_signed', 'id_proof', 'educational_certs', 'prev_employment_docs', 'bank_details', 'passport_photos']
export const ONBOARDING_DOC_LABELS = {
  offer_signed: 'Offer Letter (Signed)',
  id_proof: 'ID Proof (Aadhaar/PAN)',
  educational_certs: 'Educational Certificates',
  prev_employment_docs: 'Previous Employment Docs',
  bank_details: 'Bank Account Details',
  passport_photos: 'Passport Size Photos',
}

// The checklist auto-completes from the verification workflow — it is DERIVED from
// verified documents + offer status, never read from hr_onboarding.document_checklist
// (that column lags reality and can read 0% on a fully approved onboarding).
const _docVerified = (r, type) => (r?.documents || []).some(d => d.type === type && d.status === 'Verified')
export const computeOnboardingChecklist = (r, offer = null) => {
  const approved = r?.verification_status === 'Approved'
  return {
    offer_signed:         (offer ?? r?.candidate?.offer)?.status === 'Accepted' || (offer ?? r?.candidate?.offer)?.status === 'Completed',
    id_proof:             _docVerified(r, 'aadhaar') || _docVerified(r, 'pan'),
    educational_certs:    _docVerified(r, 'educational_certificate'),
    prev_employment_docs: _docVerified(r, 'experience_document'),
    bank_details:         !!(r?.submission?.bank_details?.account_number) && approved,
    passport_photos:      _docVerified(r, 'photo'),
  }
}

// ── AI job-fit presentation ────────────────────────────────────────────────
// aiBand() lived here and derived a label from the score with its own thresholds
// (90/70/50). It was one of four competing vocabularies, which is why an 87% could
// read "Recommended" on one page and "Strongly Recommended" on another. The verdict
// is now decided once, by the backend RecommendationEngine, and shipped with the
// score. All that remains on this side is colour, which is presentation.
//
// Keys are RecommendationEngine's vocabulary. Anything unrecognised falls through
// to the neutral style rather than being scored into a band locally.
export const AI_NEUTRAL_STYLE = { color: 'var(--text-muted)', bg: 'var(--bg-input)' }

export const AI_RECOMMENDATION_STYLE = {
  'Highly Recommended': { color: '#10b981', bg: 'rgba(16,185,129,0.12)' },
  'Recommended':        { color: '#a78bfa', bg: 'rgba(124,58,237,0.12)' },
  'Consider':           { color: '#fbbf24', bg: 'rgba(245,158,11,0.12)' },
  'Not Recommended':    { color: '#f87171', bg: 'rgba(239,68,68,0.10)' },
  'Insufficient Data':  AI_NEUTRAL_STYLE,
}

export const aiRecommendationStyle = (recommendation) =>
  AI_RECOMMENDATION_STYLE[recommendation] || AI_NEUTRAL_STYLE

// Bar colour per dimension, keyed on the engine's dimension keys. Presentation
// only — the score, the label and the reason all arrive from the backend.
export const AI_DIMENSION_COLOR = {
  skills: '#fbbf24', experience: '#f97316', jd: '#60a5fa', interview: '#c084fc',
  education: '#22d3ee', location: '#34d399', salary: '#4ade80',
  notice: '#f472b6', screening: '#38bdf8', resume: '#a78bfa',
}
export const aiDimensionColor = (key) => AI_DIMENSION_COLOR[key] || '#a78bfa'

/**
 * The engine's verdict for a candidate from a LIST payload, where calling the score
 * endpoint per row would be N+1. ScoreRecorder mirrors the score, confidence and
 * recommendation onto hr_candidates.ai_breakdown, so this is a pure read — no
 * arithmetic and no thresholds.
 *
 * `score === null` is a real state, not missing data: the confidence floor withholds
 * a score built on too little evidence. It must render as "—", never as 0%.
 */
export const candidateScore = (c) => {
  const breakdown = (c && c.ai_breakdown) || {}
  const score = c?.ai_score ?? null
  const recommendation = breakdown.recommendation ?? null

  // The engine RAN (even if it withheld a number). This, not the score, is what
  // "AI screening completed" means -- mirrors HrCandidate::hasAiScreening().
  const isScreened = !!breakdown.engine

  // A score is only real if the ENGINE produced it. Rows carrying a pre-engine
  // ai_score (seeded or written by the removed heuristics) have no `engine` stamp
  // and no air_candidate_scores row, so the detail page correctly reports them as
  // unscored. Without this check a list would show "87%" beside a profile saying
  // "Not scored" — the exact list-vs-detail disagreement this engine exists to end.
  const isScored = isScreened && score !== null

  return {
    isScreened,
    isScored,
    score: isScored ? score : null,
    confidence: breakdown.confidence ?? null,
    recommendation,
    style: aiRecommendationStyle(recommendation),
    display: isScored ? `${score}%` : '—',
  }
}

// Compact INR money formatter for CTC display (accepts lakh-style numbers).
export const formatCTC = (v) => {
  if (v === null || v === undefined || v === '' || isNaN(Number(v))) return null
  const n = Number(v)
  if (n >= 10000000) return `₹${(n / 10000000).toFixed(2).replace(/\.00$/, '')} Cr`
  if (n >= 100000)   return `₹${(n / 100000).toFixed(2).replace(/\.00$/, '')} L`
  if (n >= 1000)     return `₹${(n / 1000).toFixed(0)}K`
  return `₹${n}`
}

export const JOB_STATUSES = Object.values(JOB_STATUS)
export const jobStatusLabel = (s) => JOB_STATUS_CONFIG[s]?.label || s || '—'
export const jobStatusColor = (s) => JOB_STATUS_CONFIG[s] || JOB_STATUS_CONFIG.Draft
export const JOB_TYPE_COLORS = { 'Full-time': '#7C3AED', 'Part-time': '#3b82f6', Contract: '#f59e0b', Remote: '#10b981', Internship: '#ec4899' }

// Statuses that belong to the HR queue (both approvals complete)
export const HR_QUEUE_STATUSES = [
  MR_STATUS.READY_FOR_HR, MR_STATUS.CONVERTED_TO_JD,
  MR_STATUS.JOB_POSTED, MR_STATUS.HIRING_IN_PROGRESS,
]

// Ordered workflow stages for the stepper
export const WORKFLOW_STEPS = [
  { key: MR_STATUS.DRAFT,              label: 'Draft' },
  { key: MR_STATUS.L1_PENDING,         label: 'L1 · Dept Head' },
  { key: MR_STATUS.L2_PENDING,         label: 'L2 · Management' },
  { key: MR_STATUS.READY_FOR_HR,       label: 'HR Queue' },
  { key: MR_STATUS.CONVERTED_TO_JD,    label: 'JD' },
  { key: MR_STATUS.JOB_POSTED,         label: 'Posted' },
  { key: MR_STATUS.HIRING_IN_PROGRESS, label: 'Hiring' },
  { key: MR_STATUS.CLOSED,             label: 'Closed' },
]

// Dropdown option lists for the enhanced request form
export const EMPLOYEE_LEVELS = ['Intern', 'Junior', 'Mid-level', 'Senior', 'Lead', 'Manager', 'Director']
export const EMPLOYMENT_TYPES = ['Full-time', 'Part-time', 'Contract', 'Internship']
export const PRIORITIES = ['Low', 'Medium', 'High', 'Critical']

// Frontend gating for the three HR authority questions. The backend remains the
// source of truth; these only decide whether a button is worth showing.
//
// They used to REBUILD the rules here from role strings, which was a fair copy
// when written and stopped being one twice over:
//
//   • canManageHrQueue gained a fourth clause — hr_employees:view_global — so a
//     CUSTOM ROLE configured in HR Settings passed the server while this hid the
//     buttons. A role you can create but not use is worse than no roles at all.
//   • canApproveL1/L2 gained the account-type guard, so a portal login carrying
//     'department_head' was still offered approval buttons it would be refused.
//
// So they now read the server's own answer, sent on /auth/me as
// permissions.capabilities. The signature is unchanged — every existing call
// site still passes the user object — and a new role works with no deploy.
//
// Undefined until /auth/me returns, which coerces to false: the closed state
// shows first, because a button that appears and then vanishes is worse than one
// that appears a moment late.
const capability = (u, key) => !!u?.permissions?.capabilities?.[key]

export const canApproveL1 = (u) => capability(u, 'approve_l1')
export const canApproveL2 = (u) => capability(u, 'approve_l2')
export const canManageHrQueue = (u) => capability(u, 'hr_manage')

// Job identifier + apply links (SPK-1) — shared by the card and table views
// so the same job always reads identically in both.
export const jobCode = (id) => id ? `JOB-${String(id).padStart(4, '0')}` : '—'
export const publicApplyUrl = (slug, id) => (slug && id) ? `${window.location.origin}/careers/${slug}/jobs/${id}` : null
export const internalApplyUrl = (id) => id ? `${window.location.origin}/app/hr/jobs/${id}` : null

/*
|------------------------------------------------------------------------------
| Attendance clock times
|------------------------------------------------------------------------------
|
| Storage is UTC and stays UTC — config/app.php says so, and warns that setting
| it to Asia/Kolkata "to fix attendance times" broke numbering and localisation
| instead. So the conversion belongs here, in presentation, done once.
|
| It was not done once. The Attendance Register parsed the timestamp and let the
| browser localise it; four other screens took a substring of the ISO string,
| which is the UTC clock face with the date cut off. Same record, two answers,
| 5 hours 30 minutes apart — a punch at 10:53 IST read 05:23 on the dashboard.
|
| TWO SHAPES ARRIVE HERE, and telling them apart is the whole job:
|
|   hr_attendance.check_in            datetime, cast, serialised "…T09:28:00Z"
|                                     → an INSTANT. Must be converted.
|   hr_attendance_corrections
|     .requested_check_in             a `time` column, value "09:15"
|                                     → a WALL-CLOCK time. Already local; it
|                                       carries no date and no zone, so parsing
|                                       it as an instant would invent both.
|
| Converting the second would be the same bug pointing the other way, which is
| why this does not simply call new Date() on everything.
*/

/** Does this value carry a date, and therefore a zone? */
const isInstant = (v) => /^\d{4}-\d{2}-\d{2}[T ]/.test(String(v))

/**
 * An attendance clock time as HH:MM in the reader's local zone.
 *
 * Accepts either shape above. Bare times pass through trimmed to HH:MM;
 * timestamps are parsed and localised — the same conversion the Attendance
 * Register already did, now shared so the screens cannot disagree again.
 *
 * An unparseable timestamp returns the dash rather than "Invalid Date": a
 * clock face is read at a glance and a wrong one is worse than an absent one.
 */
export const hrTime = (v) => {
  if (v === null || v === undefined || v === '') return '—'

  const s = String(v)

  if (!isInstant(s)) {
    // "09:15", "09:15:00" → "09:15". Anything else is not a time we know.
    const m = s.match(/^(\d{1,2}):(\d{2})/)
    return m ? `${m[1].padStart(2, '0')}:${m[2]}` : '—'
  }

  const d = new Date(s)
  if (Number.isNaN(d.getTime())) return '—'

  return d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: false })
}

/**
 * Two attendance times compared on the same footing.
 *
 * The correction queue shows "now → asked for", where `now` is a stored
 * timestamp and `asked for` is a wall-clock time the employee typed. Comparing
 * their raw strings compared a UTC clock face against a local one: an approver
 * reviewing a request to change nothing saw a five-and-a-half hour move, and
 * the "(no change)" hint never fired. Normalising both through hrTime() first
 * is what makes the two comparable at all.
 */
export const hrTimeEquals = (a, b) => hrTime(a) === hrTime(b)
