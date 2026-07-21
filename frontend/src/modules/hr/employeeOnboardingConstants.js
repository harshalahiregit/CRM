// ── Employee Onboarding — shared frontend constants ─────────────────────────
// Keys mirror the backend App\Support\Hr\EmployeeOnboardingStage / …Status /
// OnboardingTaskCategory so the tracker, dashboard buckets and badges render
// identically to the server's source of truth. Isolated to this module.

// The 14-step lifecycle — order matches EmployeeOnboardingStage::ORDER.
export const ONB_STAGES = [
  { key: 'Offer_Accepted',          label: 'Offer Accepted' },
  { key: 'Employee_Created',        label: 'Employee Created' },
  { key: 'Personal_Information',    label: 'Personal Information' },
  { key: 'Education',               label: 'Education' },
  { key: 'Employment_History',      label: 'Employment History' },
  { key: 'Documents_Verification',  label: 'Documents Verification' },
  { key: 'Compliance_Verification', label: 'Compliance Verification' },
  { key: 'Bank_Payroll',            label: 'Bank & Payroll' },
  { key: 'IT_Asset_Allocation',     label: 'IT & Asset Allocation' },
  { key: 'Orientation',             label: 'Orientation' },
  { key: 'Training',                label: 'Training' },
  { key: 'HR_Approval',             label: 'HR Approval' },
  { key: 'Manager_Approval',        label: 'Manager Approval' },
  { key: 'Completed',               label: 'Completed' },
]

// Process status → badge config (label/color/bg) — drives the dashboard cards.
export const ONB_STATUS = {
  Pending:           { label: 'Pending',              color: '#f59e0b', bg: 'rgba(245,158,11,0.14)' },
  In_Progress:       { label: 'In Progress',          color: '#3b82f6', bg: 'rgba(59,130,246,0.14)' },
  Waiting_Documents: { label: 'Waiting for Documents', color: '#a78bfa', bg: 'rgba(124,58,237,0.14)' },
  Waiting_HR:        { label: 'Waiting for HR',        color: '#f472b6', bg: 'rgba(244,114,182,0.14)' },
  Waiting_Manager:   { label: 'Waiting for Manager',   color: '#22d3ee', bg: 'rgba(34,211,238,0.14)' },
  Completed:         { label: 'Completed',            color: '#10b981', bg: 'rgba(16,185,129,0.14)' },
  On_Hold:           { label: 'On Hold',              color: '#94a3b8', bg: 'rgba(148,163,184,0.14)' },
}
export const onbStatusCfg = (s) => ONB_STATUS[s] || ONB_STATUS.Pending
export const onbStatusLabel = (s) => ONB_STATUS[s]?.label || s || '—'

// Checklist task status → color.
export const TASK_STATUS = {
  'Pending':     { color: '#f59e0b', bg: 'rgba(245,158,11,0.14)' },
  'In Progress': { color: '#3b82f6', bg: 'rgba(59,130,246,0.14)' },
  'Completed':   { color: '#10b981', bg: 'rgba(16,185,129,0.14)' },
  'Rejected':    { color: '#ef4444', bg: 'rgba(239,68,68,0.1)' },
}
export const TASK_STATUSES = ['Pending', 'In Progress', 'Completed', 'Rejected']

// Task categories shown as sub-groups in the Orientation / Training / Checklist tabs.
export const TASK_CATEGORIES = [
  { key: 'Orientation',       label: 'Orientation' },
  { key: 'Training',          label: 'Training' },
  { key: 'IT_Setup',          label: 'IT Setup' },
  { key: 'HR_Checklist',      label: 'HR Checklist' },
  { key: 'Manager_Checklist', label: 'Manager Checklist' },
]
export const taskCatLabel = (k) => TASK_CATEGORIES.find(c => c.key === k)?.label || k

// Shared formatters (module-local, matching the HR pages' inline convention).
export const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
export const fmtDateTime = (d) => d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'
