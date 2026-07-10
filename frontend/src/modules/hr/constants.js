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
export const INTERVIEW_RESULT_COLORS = { Passed: '#10b981', Failed: '#ef4444', Pending: '#f59e0b' }

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

// Frontend role gating — mirrors User::canApproveL1/L2/canManageHrQueue on the
// backend (the backend is the source of truth; this only hides buttons).
export const canApproveL1 = (u) => u?.role === 'admin' || ['department_head', 'hiring_manager'].includes(u?.internal_role)
export const canApproveL2 = (u) => u?.role === 'admin' || ['project_manager', 'senior_executive'].includes(u?.internal_role)
export const canManageHrQueue = (u) => u?.role === 'admin' || u?.internal_role === 'hr_executive' || ['hr_recruiter', 'hr_executive'].includes(u?.internal_role)
