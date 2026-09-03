/**
 * Purchase kickoff constants — Purchase-owned mirror of App\Support\Purchase\
 * PurchaseKickoffStatus. Deliberately self-contained: the Purchase kickoff engine
 * never shares code or tables with the shared/TPV kickoff engine, so these live in
 * modules/purchase and import nothing from modules/shared or modules/tpv.
 */

// ── Status — App\Support\Purchase\PurchaseKickoffStatus ──────────────────────
export const PK_STATUS = {
  DRAFT: 'Draft',
  SCHEDULED: 'Scheduled',
  DELAYED: 'Delayed',
  COMPLETED: 'Completed',
  CANCELLED: 'Cancelled',
}

export const PK_STATUS_CONFIG = {
  [PK_STATUS.DRAFT]:     { label: 'Draft',     color: '#a78bfa', bg: 'rgba(167,139,250,0.15)' },
  [PK_STATUS.SCHEDULED]: { label: 'Scheduled', color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  [PK_STATUS.DELAYED]:   { label: 'Delayed',   color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [PK_STATUS.COMPLETED]: { label: 'Completed', color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [PK_STATUS.CANCELLED]: { label: 'Cancelled', color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
}
export const pkStatusCfg = (s) => PK_STATUS_CONFIG[s] || PK_STATUS_CONFIG[PK_STATUS.SCHEDULED]

export const isPkDraft  = (s) => s === PK_STATUS.DRAFT
export const isPkOpen   = (s) => s === PK_STATUS.DRAFT || s === PK_STATUS.SCHEDULED || s === PK_STATUS.DELAYED
export const isPkClosed = (s) => s === PK_STATUS.COMPLETED || s === PK_STATUS.CANCELLED

/** Permitted moves — mirrors PurchaseKickoffStatus::TRANSITIONS. Draft → Scheduled is "Publish". */
export const PK_TRANSITIONS = {
  [PK_STATUS.DRAFT]:     [PK_STATUS.SCHEDULED, PK_STATUS.CANCELLED],
  [PK_STATUS.SCHEDULED]: [PK_STATUS.DELAYED, PK_STATUS.COMPLETED, PK_STATUS.CANCELLED],
  [PK_STATUS.DELAYED]:   [PK_STATUS.SCHEDULED, PK_STATUS.COMPLETED, PK_STATUS.CANCELLED],
  [PK_STATUS.COMPLETED]: [],
  [PK_STATUS.CANCELLED]: [PK_STATUS.SCHEDULED],
}
export const pkNextStatuses = (s) => PK_TRANSITIONS[s] || []

// ── MOM approval lifecycle — App\Support\Purchase\PurchaseMomApprovalStatus ──
export const PK_MOM_STATUS = {
  DRAFT: 'Draft',
  PENDING: 'Pending_Approval',          // awaiting organizer
  PENDING_CHAIR: 'Pending_Chairperson', // awaiting chairperson
  APPROVED: 'Approved',
  DISTRIBUTED: 'Distributed',
}

export const PK_MOM_CONFIG = {
  [PK_MOM_STATUS.DRAFT]:         { label: 'Draft',              color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  [PK_MOM_STATUS.PENDING]:       { label: 'Pending Organizer',  color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [PK_MOM_STATUS.PENDING_CHAIR]: { label: 'Pending Chairperson', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [PK_MOM_STATUS.APPROVED]:      { label: 'Approved',           color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [PK_MOM_STATUS.DISTRIBUTED]:   { label: 'Distributed',        color: '#7C3AED', bg: 'rgba(124,58,237,0.15)' },
}
export const pkMomCfg = (s) => PK_MOM_CONFIG[s] || PK_MOM_CONFIG[PK_MOM_STATUS.DRAFT]

/** Minutes are ready to distribute only once approved. */
export const pkMomDistributable = (s) => s === PK_MOM_STATUS.APPROVED || s === PK_MOM_STATUS.DISTRIBUTED
/** Awaiting an approval decision. */
export const pkMomAwaitingDecision = (s) => s === PK_MOM_STATUS.PENDING || s === PK_MOM_STATUS.PENDING_CHAIR

// ── MOM action lifecycle — App\Support\Purchase\PurchaseMomActionStatus ──────
export const PK_ACTION_STATUS = {
  OPEN: 'Open',
  IN_PROGRESS: 'In_Progress',
  PENDING_VERIFICATION: 'Pending_Verification',
  CLOSED: 'Closed',
  REOPENED: 'Reopened',
  CANCELLED: 'Cancelled',
}

export const PK_ACTION_CONFIG = {
  [PK_ACTION_STATUS.OPEN]:                 { label: 'Open',                 color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  [PK_ACTION_STATUS.IN_PROGRESS]:          { label: 'In Progress',          color: '#7C3AED', bg: 'rgba(124,58,237,0.15)' },
  [PK_ACTION_STATUS.PENDING_VERIFICATION]: { label: 'Pending Verification', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [PK_ACTION_STATUS.CLOSED]:               { label: 'Closed',               color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [PK_ACTION_STATUS.REOPENED]:             { label: 'Reopened',             color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [PK_ACTION_STATUS.CANCELLED]:            { label: 'Cancelled',            color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
}
export const pkActionCfg = (s) => PK_ACTION_CONFIG[s] || PK_ACTION_CONFIG[PK_ACTION_STATUS.OPEN]

/** Permitted moves — mirrors PurchaseMomActionStatus::TRANSITIONS. */
export const PK_ACTION_TRANSITIONS = {
  [PK_ACTION_STATUS.OPEN]:                 [PK_ACTION_STATUS.IN_PROGRESS, PK_ACTION_STATUS.PENDING_VERIFICATION, PK_ACTION_STATUS.CANCELLED],
  [PK_ACTION_STATUS.IN_PROGRESS]:          [PK_ACTION_STATUS.OPEN, PK_ACTION_STATUS.PENDING_VERIFICATION, PK_ACTION_STATUS.CANCELLED],
  [PK_ACTION_STATUS.PENDING_VERIFICATION]: [PK_ACTION_STATUS.IN_PROGRESS, PK_ACTION_STATUS.CLOSED, PK_ACTION_STATUS.REOPENED, PK_ACTION_STATUS.CANCELLED],
  [PK_ACTION_STATUS.CLOSED]:               [PK_ACTION_STATUS.REOPENED],
  [PK_ACTION_STATUS.REOPENED]:             [PK_ACTION_STATUS.IN_PROGRESS, PK_ACTION_STATUS.PENDING_VERIFICATION, PK_ACTION_STATUS.CLOSED, PK_ACTION_STATUS.CANCELLED],
  [PK_ACTION_STATUS.CANCELLED]:            [PK_ACTION_STATUS.REOPENED],
}
export const pkActionNext = (s) => PK_ACTION_TRANSITIONS[s] || []

export const PK_ACTION_PRIORITIES = ['Low', 'Medium', 'High', 'Urgent']

// ── MOM issue lifecycle — App\Support\Purchase\PurchaseMomIssueStatus ────────
export const PK_ISSUE_STATUS = {
  OPEN: 'Open',
  IN_PROGRESS: 'In_Progress',
  RESOLVED: 'Resolved',
  CLOSED: 'Closed',
  REOPENED: 'Reopened',
  CANCELLED: 'Cancelled',
}

export const PK_ISSUE_CONFIG = {
  [PK_ISSUE_STATUS.OPEN]:        { label: 'Open',        color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  [PK_ISSUE_STATUS.IN_PROGRESS]: { label: 'In Progress', color: '#7C3AED', bg: 'rgba(124,58,237,0.15)' },
  [PK_ISSUE_STATUS.RESOLVED]:    { label: 'Resolved',    color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [PK_ISSUE_STATUS.CLOSED]:      { label: 'Closed',      color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [PK_ISSUE_STATUS.REOPENED]:    { label: 'Reopened',    color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [PK_ISSUE_STATUS.CANCELLED]:   { label: 'Cancelled',   color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
}
export const pkIssueCfg = (s) => PK_ISSUE_CONFIG[s] || PK_ISSUE_CONFIG[PK_ISSUE_STATUS.OPEN]

/** Permitted moves — mirrors PurchaseMomIssueStatus::TRANSITIONS. */
export const PK_ISSUE_TRANSITIONS = {
  [PK_ISSUE_STATUS.OPEN]:        [PK_ISSUE_STATUS.IN_PROGRESS, PK_ISSUE_STATUS.RESOLVED, PK_ISSUE_STATUS.CANCELLED],
  [PK_ISSUE_STATUS.IN_PROGRESS]: [PK_ISSUE_STATUS.OPEN, PK_ISSUE_STATUS.RESOLVED, PK_ISSUE_STATUS.CANCELLED],
  [PK_ISSUE_STATUS.RESOLVED]:    [PK_ISSUE_STATUS.IN_PROGRESS, PK_ISSUE_STATUS.CLOSED, PK_ISSUE_STATUS.REOPENED],
  [PK_ISSUE_STATUS.CLOSED]:      [PK_ISSUE_STATUS.REOPENED],
  [PK_ISSUE_STATUS.REOPENED]:    [PK_ISSUE_STATUS.IN_PROGRESS, PK_ISSUE_STATUS.RESOLVED, PK_ISSUE_STATUS.CANCELLED],
  [PK_ISSUE_STATUS.CANCELLED]:   [PK_ISSUE_STATUS.REOPENED],
}
export const pkIssueNext = (s) => PK_ISSUE_TRANSITIONS[s] || []

export const PK_ISSUE_SEVERITIES = ['Low', 'Medium', 'High', 'Critical']
export const PK_ISSUE_CATEGORIES = ['Safety', 'Compliance', 'Quality', 'Commercial', 'Workforce', 'Schedule', 'Technical', 'Environmental', 'Other']

// ── MOM decision status — App\Support\Purchase\PurchaseMomDecisionStatus ──────
export const PK_DECISION_STATUS = {
  ACTIVE: 'Active',
  SUPERSEDED: 'Superseded',
  RESCINDED: 'Rescinded',
}
export const PK_DECISION_CONFIG = {
  [PK_DECISION_STATUS.ACTIVE]:     { label: 'Active',     color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [PK_DECISION_STATUS.SUPERSEDED]: { label: 'Superseded', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [PK_DECISION_STATUS.RESCINDED]:  { label: 'Rescinded',  color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
}
export const pkDecisionCfg = (s) => PK_DECISION_CONFIG[s] || PK_DECISION_CONFIG[PK_DECISION_STATUS.ACTIVE]
export const PK_DECISION_STATUSES = [PK_DECISION_STATUS.ACTIVE, PK_DECISION_STATUS.SUPERSEDED, PK_DECISION_STATUS.RESCINDED]

export const PK_MODES = [['onsite', 'On site'], ['online', 'Online'], ['hybrid', 'Hybrid']]
export const pkModeLabel = (m) => (PK_MODES.find(([v]) => v === m) || [m, m || '—'])[1]

export const fmtDateTime = (d) => (d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true }) : '—')
export const fmtDate = (d) => (d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—')
