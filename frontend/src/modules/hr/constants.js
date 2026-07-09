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

export const PRIORITY_COLORS = { High: '#ef4444', Medium: '#f59e0b', Low: '#10b981' }

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
export const PRIORITIES = ['Low', 'Medium', 'High']

// Frontend role gating — mirrors User::canApproveL1/L2/canManageHrQueue on the
// backend (the backend is the source of truth; this only hides buttons).
export const canApproveL1 = (u) => u?.role === 'admin' || ['department_head', 'hiring_manager'].includes(u?.internal_role)
export const canApproveL2 = (u) => u?.role === 'admin' || ['project_manager', 'senior_executive'].includes(u?.internal_role)
export const canManageHrQueue = (u) => u?.role === 'admin' || u?.internal_role === 'hr_executive' || ['hr_recruiter', 'hr_executive'].includes(u?.internal_role)
