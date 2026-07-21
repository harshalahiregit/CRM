/**
 * Compliance engine constants — mirrors App\Support\Compliance.
 *
 * Generic, not TPV: the engine attaches checklists to any allowlisted subject.
 * Kept out of modules/tpv/constants.js for that reason — HR's exit checklists
 * will read from here too.
 */

// ── Checklist lifecycle — App\Support\Compliance\ComplianceStatus ────────────
export const CL_STATUS = {
  DRAFT: 'Draft',
  ASSIGNED: 'Assigned',
  SUBMITTED: 'Submitted',
  MANAGER_APPROVED: 'Manager_Approved',
  APPROVED: 'Approved',
  REJECTED: 'Rejected',
}

export const CL_STATUS_CONFIG = {
  [CL_STATUS.DRAFT]:            { label: 'Draft',            color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  [CL_STATUS.ASSIGNED]:         { label: 'Assigned',         color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  [CL_STATUS.SUBMITTED]:        { label: 'Submitted',        color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [CL_STATUS.MANAGER_APPROVED]: { label: 'Manager Approved', color: '#a78bfa', bg: 'rgba(167,139,250,0.15)' },
  [CL_STATUS.APPROVED]:         { label: 'Approved',         color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [CL_STATUS.REJECTED]:         { label: 'Rejected',         color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const clStatusCfg = (s) => CL_STATUS_CONFIG[s] || CL_STATUS_CONFIG[CL_STATUS.DRAFT]

/** Only an Assigned checklist accepts answers (mirrors ComplianceStatus::FILLABLE). */
export const isFillable = (s) => s === CL_STATUS.ASSIGNED
export const isClosed = (s) => s === CL_STATUS.APPROVED || s === CL_STATUS.REJECTED

// ── Template lifecycle — App\Support\Compliance\TemplateStatus ───────────────
export const TPL_STATUS = { DRAFT: 'Draft', ACTIVE: 'Active', ARCHIVED: 'Archived' }

export const TPL_STATUS_CONFIG = {
  [TPL_STATUS.DRAFT]:    { label: 'Draft',    color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  [TPL_STATUS.ACTIVE]:   { label: 'Active',   color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [TPL_STATUS.ARCHIVED]: { label: 'Archived', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
}
export const tplStatusCfg = (s) => TPL_STATUS_CONFIG[s] || TPL_STATUS_CONFIG[TPL_STATUS.DRAFT]

/** The definition freezes once a template leaves Draft — clone to revise. */
export const isTemplateEditable = (s) => s === TPL_STATUS.DRAFT
export const isTemplateIssuable = (s) => s === TPL_STATUS.ACTIVE

// ── Risk bands — App\Support\Compliance\RiskBand ─────────────────────────────
// Same Low/Moderate/High vocabulary and colours the workforce wizard already
// renders (modules/tpv/constants BAND_COLORS). Higher score = more risk, so Low
// is the good band. Every use pairs the colour with the word, so identity is
// never colour-alone.
export const RISK_BANDS = ['Low', 'Moderate', 'High']
export const RISK_CONFIG = {
  Low:      { label: 'Low',      color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Moderate: { label: 'Moderate', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  High:     { label: 'High',     color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const riskCfg = (b) => RISK_CONFIG[b] || { label: 'Unscored', color: '#94a3b8', bg: 'rgba(148,163,184,0.12)' }

// ── Question types — App\Support\Compliance\QuestionType ─────────────────────
export const Q_TYPES = [
  ['boolean', 'Yes / No'],
  ['choice',  'Single choice'],
  ['number',  'Number'],
  ['text',    'Text'],
  ['date',    'Date'],
]
export const qTypeLabel = (t) => (Q_TYPES.find(([v]) => v === t) || [t, t])[1]
export const SCORABLE_TYPES = ['boolean', 'choice', 'number']
export const isScorable = (t) => SCORABLE_TYPES.includes(t)

// ── Signature chain — App\Support\Compliance\SignatureTier ───────────────────
export const TIERS = ['issuer', 'manager', 'head']
export const TIER_LABELS = { issuer: 'Issuer', manager: 'Manager', head: 'Head' }
export const tierLabel = (t) => TIER_LABELS[t] || t

// ── Subjects — App\Support\Compliance\ChecklistSubject ───────────────────────
export const SUBJECTS = [['vendor', 'Vendor'], ['worker', 'Worker']]
export const subjectLabel = (s) => (SUBJECTS.find(([v]) => v === s) || [s, s])[1]

/** Percent-of-maximum cut-offs; mirrors RiskBand::DEFAULT_THRESHOLDS. */
export const DEFAULT_THRESHOLDS = { moderate: 25, high: 50 }

// ── Client-side risk hint ────────────────────────────────────────────────────
/**
 * Whether an answer earns risk — used ONLY to decide when to reveal the remark
 * box, never to score. The authoritative scoring is ChecklistEvaluator's; this
 * mirrors its rules so the supervisor sees the remark field as they answer,
 * rather than discovering the requirement in a 422 after submitting.
 */
export function answerIsRisky(question, value) {
  if (value === null || value === undefined || value === '') return false

  switch (question.type) {
    case 'boolean':
      return value === (question.risk_when ?? false)
    case 'choice': {
      const opt = (question.options || []).find(o => o.value === value)
      return !!opt && Number(opt.risk || 0) > 0
    }
    case 'number':
      return Number(question.risk_per_unit || 0) > 0 && Number(value) > 0
    default:
      return false
  }
}

export const fmtDateTime = (d) => (d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false }) : '—')
export const fmtDate = (d) => (d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—')
