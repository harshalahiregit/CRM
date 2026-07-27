/**
 * Vendor-portal display constants.
 *
 * Self-contained (not imported from modules/) so the portal stays decoupled — it
 * is the vendor's view, and mirrors backend statuses with vendor-friendly labels.
 * Every colour is paired with its label, so status is never colour-alone.
 */

// ── Onboarding — App\Support\Tpv\TpvOnboardingStatus ─────────────────────────
export const OB_STATUS_CONFIG = {
  Draft:             { label: 'Draft',             color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  In_Progress:       { label: 'In Progress',       color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  Submitted:         { label: 'Submitted',         color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Under_Review:      { label: 'Under Review',      color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Resubmit_Required: { label: 'Resubmit Required', color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  Approved:          { label: 'Approved',          color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Rejected:          { label: 'Rejected',          color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const obStatusCfg = (s) => OB_STATUS_CONFIG[s] || OB_STATUS_CONFIG.Draft

// ── Document review status — App\Support\Vendor\VendorDocumentStatus ──────────
export const DOC_STATUS_CONFIG = {
  Pending:  { label: 'Under Review', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Approved: { label: 'Approved',     color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Rejected: { label: 'Rejected',     color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const docStatusCfg = (s) => DOC_STATUS_CONFIG[s] || { label: 'Not Uploaded', color: '#94a3b8', bg: 'rgba(148,163,184,0.12)' }

// ── Purchase order status — App\Support\Purchase\PurchaseOrderStatus ──────────
export const PO_STATUS_CONFIG = {
  Issued:             { label: 'Issued',             color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  Partially_Received: { label: 'Partially Received', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Received:           { label: 'Received',           color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Closed:             { label: 'Closed',             color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  Cancelled:          { label: 'Cancelled',          color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const poStatusCfg = (s) => PO_STATUS_CONFIG[s] || PO_STATUS_CONFIG.Issued

// ── Invoice status — App\Support\Purchase\PurchaseInvoiceStatus ───────────────
export const INV_STATUS_CONFIG = {
  Awaiting_Payment: { label: 'Awaiting Payment', color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  Partially_Paid:   { label: 'Partially Paid',   color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Paid:             { label: 'Paid',             color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Cancelled:        { label: 'Cancelled',        color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const invStatusCfg = (s) => INV_STATUS_CONFIG[s] || INV_STATUS_CONFIG.Awaiting_Payment

// ── 3-way match verdict (vendor-facing labels) ───────────────────────────────
// Only Matched / Under_Billed / Unmatched can appear on an APPROVED invoice
// (over-billing is blocked at approval), so the vendor never sees a scary flag on
// a bill they can view. The others are defined defensively.
export const MATCH_CONFIG = {
  Matched:        { label: 'Matched to order & receipt', color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Under_Billed:   { label: 'Billed below delivery',       color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Unmatched:      { label: 'Not linked to an order',      color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  Over_Billed:    { label: 'Over-billed',                 color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  Price_Variance: { label: 'Price variance',             color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const matchCfg = (v) => (v ? MATCH_CONFIG[v] : null)

export const fmtMoney = (n, currency = 'INR') => {
  const v = Number(n || 0)
  try { return new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 2 }).format(v) }
  catch { return `₹${v.toLocaleString('en-IN')}` }
}
export const fmtDate = (d) => (d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—')
