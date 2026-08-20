/**
 * Kickoff meeting constants — mirrors App\Support\Shared.
 *
 * Shared, not TPV: the engine attaches meetings to any allowlisted subject.
 * Kept in modules/shared for that reason — Shivam's Project&Task module reads
 * from here too when it plugs a project subject into the engine.
 */

// ── Status — App\Support\Shared\KickoffStatus ────────────────────────────────
export const KO_STATUS = {
  SCHEDULED: 'Scheduled',
  DELAYED: 'Delayed',
  COMPLETED: 'Completed',
  CANCELLED: 'Cancelled',
}

export const KO_STATUS_CONFIG = {
  [KO_STATUS.SCHEDULED]: { label: 'Scheduled', color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  [KO_STATUS.DELAYED]:   { label: 'Delayed',   color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [KO_STATUS.COMPLETED]: { label: 'Completed', color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [KO_STATUS.CANCELLED]: { label: 'Cancelled', color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
}
export const koStatusCfg = (s) => KO_STATUS_CONFIG[s] || KO_STATUS_CONFIG[KO_STATUS.SCHEDULED]

export const isKoOpen   = (s) => s === KO_STATUS.SCHEDULED || s === KO_STATUS.DELAYED
export const isKoClosed = (s) => s === KO_STATUS.COMPLETED || s === KO_STATUS.CANCELLED

/** Permitted moves — mirrors KickoffStatus::TRANSITIONS, so the UI only offers
 *  actions the server will accept. */
export const KO_TRANSITIONS = {
  [KO_STATUS.SCHEDULED]: [KO_STATUS.DELAYED, KO_STATUS.COMPLETED, KO_STATUS.CANCELLED],
  [KO_STATUS.DELAYED]:   [KO_STATUS.SCHEDULED, KO_STATUS.COMPLETED, KO_STATUS.CANCELLED],
  [KO_STATUS.COMPLETED]: [],
  [KO_STATUS.CANCELLED]: [KO_STATUS.SCHEDULED],
}
export const koNextStatuses = (s) => KO_TRANSITIONS[s] || []

// ── MOM Action lifecycle — App\Support\Shared\MomActionStatus ─────────────────
export const ACT_STATUS_CONFIG = {
  Open:                 { label: 'Open',                 color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  In_Progress:          { label: 'In Progress',          color: '#a78bfa', bg: 'rgba(167,139,250,0.15)' },
  Pending_Verification: { label: 'Pending Verification', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Closed:               { label: 'Closed',               color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Reopened:             { label: 'Reopened',             color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  Cancelled:            { label: 'Cancelled',            color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
}
export const actStatusCfg = (s) => ACT_STATUS_CONFIG[s] || ACT_STATUS_CONFIG.Open

/** Mirrors MomActionStatus::TRANSITIONS so the UI only offers accepted moves. */
export const ACT_TRANSITIONS = {
  Open:                 ['In_Progress', 'Pending_Verification', 'Cancelled'],
  In_Progress:          ['Open', 'Pending_Verification', 'Cancelled'],
  Pending_Verification: ['In_Progress', 'Closed', 'Reopened', 'Cancelled'],
  Closed:               ['Reopened'],
  Reopened:             ['In_Progress', 'Pending_Verification', 'Closed', 'Cancelled'],
  Cancelled:            ['Reopened'],
}
export const actNextStatuses = (s) => ACT_TRANSITIONS[s] || []

export const KO_MODES = [['onsite', 'On site'], ['online', 'Online']]
export const koModeLabel = (m) => (KO_MODES.find(([v]) => v === m) || [m, m || '—'])[1]

export const fmtDateTime = (d) => (d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true }) : '—')
export const fmtDate = (d) => (d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—')
