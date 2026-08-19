// TPV module — shared enums, status config, helpers.
// Values mirror the backend Support classes (they are the API contract).

// ── Onboarding — App\Support\Tpv\TpvOnboardingStatus ─────────────────────────
export const OB_STATUS = {
  DRAFT:             'Draft',
  IN_PROGRESS:       'In_Progress',
  SUBMITTED:         'Submitted',
  UNDER_REVIEW:      'Under_Review',
  RESUBMIT_REQUIRED: 'Resubmit_Required',
  ON_HOLD:           'On_Hold',
  APPROVED:          'Approved',
  REJECTED:          'Rejected',
}
export const OB_STATUS_CONFIG = {
  [OB_STATUS.DRAFT]:             { label: 'Draft',             color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  [OB_STATUS.IN_PROGRESS]:       { label: 'In Progress',       color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  [OB_STATUS.SUBMITTED]:         { label: 'Awaiting Review',   color: '#3b82f6', bg: 'rgba(59,130,246,0.15)' },
  [OB_STATUS.UNDER_REVIEW]:      { label: 'Under Review',      color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [OB_STATUS.RESUBMIT_REQUIRED]: { label: 'Resubmit Required', color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  [OB_STATUS.ON_HOLD]:           { label: 'On Hold',           color: '#b45309', bg: 'rgba(245,158,11,0.15)' },
  [OB_STATUS.APPROVED]:          { label: 'Approved',          color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [OB_STATUS.REJECTED]:          { label: 'Rejected',          color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const obStatusCfg = (s) => OB_STATUS_CONFIG[s] || { label: s || 'Draft', color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' }

// Statuses where the vendor may still edit (mirrors TpvOnboardingStatus::EDITABLE).
export const OB_EDITABLE = [OB_STATUS.DRAFT, OB_STATUS.IN_PROGRESS, OB_STATUS.RESUBMIT_REQUIRED]
export const isOnboardingEditable = (s) => OB_EDITABLE.includes(s)

// ── Document — App\Support\Vendor\VendorDocumentStatus ───────────────────────
export const DOC_STATUS = {
  UNDER_REVIEW: 'Under_Review',
  APPROVED:     'Approved',
  REJECTED:     'Rejected',
  EXPIRED:      'Expired',
}
export const DOC_STATUS_CONFIG = {
  [DOC_STATUS.UNDER_REVIEW]: { label: 'Under Review', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [DOC_STATUS.APPROVED]:     { label: 'Approved',     color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [DOC_STATUS.REJECTED]:     { label: 'Rejected',     color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  [DOC_STATUS.EXPIRED]:      { label: 'Expired',      color: '#64748b', bg: 'rgba(100,116,139,0.15)' },
}
// A row with no document yet has status null — render it as "Not Uploaded".
export const docStatusCfg = (s) => DOC_STATUS_CONFIG[s] || { label: 'Not Uploaded', color: '#94a3b8', bg: 'rgba(148,163,184,0.12)' }

// ── Vendor — App\Support\Vendor\VendorStatus ─────────────────────────────────
export const VENDOR_STATUS_CONFIG = {
  Draft:            { label: 'Draft',            color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  Pending_Approval: { label: 'Pending Approval', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Active:           { label: 'Active',           color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  On_Hold:          { label: 'On Hold',          color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Suspended:        { label: 'Suspended',        color: '#f97316', bg: 'rgba(249,115,22,0.15)' },
  Rejected:         { label: 'Rejected',         color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  Inactive:         { label: 'Inactive',         color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  Offboarded:       { label: 'Offboarded',       color: '#64748b', bg: 'rgba(100,116,139,0.15)' },
  Blacklisted:      { label: 'Blacklisted',      color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const vendorStatusCfg = (s) => VENDOR_STATUS_CONFIG[s] || VENDOR_STATUS_CONFIG.Draft

// ── Worker — App\Support\Tpv\TpvWorkerStatus ─────────────────────────────────
export const WORKER_STATUS = {
  DRAFT:      'Draft',
  ACTIVE:     'Active',
  SUSPENDED:  'Suspended',
  TERMINATED: 'Terminated',
}
export const WORKER_STATUS_CONFIG = {
  [WORKER_STATUS.DRAFT]:      { label: 'Draft',      color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  [WORKER_STATUS.ACTIVE]:     { label: 'Active',     color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [WORKER_STATUS.SUSPENDED]:  { label: 'Suspended',  color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [WORKER_STATUS.TERMINATED]: { label: 'Terminated', color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const workerStatusCfg = (s) => WORKER_STATUS_CONFIG[s] || WORKER_STATUS_CONFIG[WORKER_STATUS.DRAFT]
export const isWorkerEditable = (s) => s === WORKER_STATUS.DRAFT

// ── Vendor contact — App\Support\Tpv\TpvContactStatus ────────────────────────
export const CONTACT_STATUS = {
  ACTIVE:   'Active',
  INACTIVE: 'Inactive',
}
export const CONTACT_STATUS_CONFIG = {
  [CONTACT_STATUS.ACTIVE]:   { label: 'Active',   color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [CONTACT_STATUS.INACTIVE]: { label: 'Inactive', color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
}
export const contactStatusCfg = (s) => CONTACT_STATUS_CONFIG[s] || CONTACT_STATUS_CONFIG[CONTACT_STATUS.INACTIVE]

// ── Medical fitness — App\Support\Tpv\TpvMedicalFitness ──────────────────────
export const FITNESS = [
  ['Fit', 'Fit'],
  ['Fit_With_Restrictions', 'Fit with Restrictions'],
  ['Unfit', 'Unfit'],
]
export const FITNESS_CONFIG = {
  Fit:                   { label: 'Fit',                   color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Fit_With_Restrictions: { label: 'Fit with Restrictions', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Unfit:                 { label: 'Unfit',                 color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const fitnessCfg = (s) => FITNESS_CONFIG[s] || { label: 'Not recorded', color: '#94a3b8', bg: 'rgba(148,163,184,0.12)' }

// Screening bands are derived server-side from the score (bandForScore).
export const BAND_COLORS = { Low: '#10b981', Moderate: '#f59e0b', High: '#ef4444' }

// PPE items are Inventory products now, not a fixed list here. The catalogue,
// its labels and its stock all come from the PPE endpoints.

export const SKILL_CATEGORIES = [
  ['Skilled', 'Skilled'], ['Semi_Skilled', 'Semi-Skilled'], ['Unskilled', 'Unskilled'], ['Supervisor', 'Supervisor'],
]
export const GENDERS = ['Male', 'Female', 'Other', 'Prefer not to say']

// ── Gate decision — App\Support\Tpv\GateDecision ─────────────────────────────
// The three lights a guard reads: green admit, amber admit-with-warning, red stop.
export const GATE_DECISION = { ADMIT: 'Admit', WARN: 'Warn', DENY: 'Deny' }
export const GATE_DECISION_CONFIG = {
  [GATE_DECISION.ADMIT]: { label: 'Admit',              short: 'ADMIT',  color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [GATE_DECISION.WARN]:  { label: 'Admit with Warning', short: 'ADMIT',  color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [GATE_DECISION.DENY]:  { label: 'Do Not Admit',       short: 'STOP',   color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const gateDecisionCfg = (d) => GATE_DECISION_CONFIG[d] || GATE_DECISION_CONFIG[GATE_DECISION.DENY]

// ── Strike severity — App\Support\Tpv\StrikeSeverity ─────────────────────────
export const STRIKE_LIMIT = 3   // mirrors StrikeSeverity::LIMIT
export const SEVERITIES = [['Minor', 'Minor'], ['Major', 'Major'], ['Critical', 'Critical']]
export const SEVERITY_CONFIG = {
  Minor:    { label: 'Minor',    color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Major:    { label: 'Major',    color: '#f97316', bg: 'rgba(249,115,22,0.15)' },
  // Critical terminates on its own — the UI must make that obvious before issuing.
  Critical: { label: 'Critical', color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const severityCfg = (s) => SEVERITY_CONFIG[s] || SEVERITY_CONFIG.Minor

export const fmtTime = (d) => (d ? new Date(d).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: false }) : '—')
export const fmtDateTime = (d) => (d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false }) : '—')

// Role helpers — approval and document review are admin-only on the backend
// (route middleware role:admin), so the UI only offers them to admins.
export const canApproveTpv = (user) => user?.role === 'admin'
export const canManageTpv  = (user) => user?.role === 'admin' || user?.role === 'staff'

export const fmtDate = (d) =>
  d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
