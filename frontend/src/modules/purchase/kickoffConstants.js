/**
 * Purchase kickoff constants — Purchase-owned mirror of App\Support\Purchase\
 * PurchaseKickoffStatus. Deliberately self-contained: the Purchase kickoff engine
 * never shares code or tables with the shared/TPV kickoff engine, so these live in
 * modules/purchase and import nothing from modules/shared or modules/tpv.
 */

// ── Status — App\Support\Purchase\PurchaseKickoffStatus ──────────────────────
export const PK_STATUS = {
  SCHEDULED: 'Scheduled',
  DELAYED: 'Delayed',
  COMPLETED: 'Completed',
  CANCELLED: 'Cancelled',
}

export const PK_STATUS_CONFIG = {
  [PK_STATUS.SCHEDULED]: { label: 'Scheduled', color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  [PK_STATUS.DELAYED]:   { label: 'Delayed',   color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [PK_STATUS.COMPLETED]: { label: 'Completed', color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [PK_STATUS.CANCELLED]: { label: 'Cancelled', color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
}
export const pkStatusCfg = (s) => PK_STATUS_CONFIG[s] || PK_STATUS_CONFIG[PK_STATUS.SCHEDULED]

export const isPkOpen   = (s) => s === PK_STATUS.SCHEDULED || s === PK_STATUS.DELAYED
export const isPkClosed = (s) => s === PK_STATUS.COMPLETED || s === PK_STATUS.CANCELLED

/** Permitted moves — mirrors PurchaseKickoffStatus::TRANSITIONS so the UI only
 *  offers actions the server will accept. */
export const PK_TRANSITIONS = {
  [PK_STATUS.SCHEDULED]: [PK_STATUS.DELAYED, PK_STATUS.COMPLETED, PK_STATUS.CANCELLED],
  [PK_STATUS.DELAYED]:   [PK_STATUS.SCHEDULED, PK_STATUS.COMPLETED, PK_STATUS.CANCELLED],
  [PK_STATUS.COMPLETED]: [],
  [PK_STATUS.CANCELLED]: [PK_STATUS.SCHEDULED],
}
export const pkNextStatuses = (s) => PK_TRANSITIONS[s] || []

export const PK_MODES = [['onsite', 'On site'], ['online', 'Online']]
export const pkModeLabel = (m) => (PK_MODES.find(([v]) => v === m) || [m, m || '—'])[1]

export const fmtDateTime = (d) => (d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true }) : '—')
export const fmtDate = (d) => (d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—')
