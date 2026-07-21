// Purchase module — shared enums, status config, helpers.
// Mirrors backend App\Support\Purchase\PurchaseRequestStatus (values are the
// contract — the API returns these exact strings on `status`).

export const PR_STATUS = {
  DRAFT:     'Draft',
  SUBMITTED: 'Submitted',
  APPROVED:  'Approved',
  CONVERTED: 'Converted',
  REJECTED:  'Rejected',
  CANCELLED: 'Cancelled',
}

// bg/color drive the badges; keep in sync with the backend LABELS map.
export const STATUS_CONFIG = {
  [PR_STATUS.DRAFT]:     { label: 'Draft',            color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  [PR_STATUS.SUBMITTED]: { label: 'Submitted',        color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [PR_STATUS.APPROVED]:  { label: 'Approved',         color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [PR_STATUS.CONVERTED]: { label: 'Converted to PO',  color: '#6366f1', bg: 'rgba(99,102,241,0.15)' },
  [PR_STATUS.REJECTED]:  { label: 'Rejected',         color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  [PR_STATUS.CANCELLED]: { label: 'Cancelled',        color: '#64748b', bg: 'rgba(100,116,139,0.15)' },
}

export const statusLabel = (s) => STATUS_CONFIG[s]?.label || s || '—'
export const statusColor = (s) => STATUS_CONFIG[s] || STATUS_CONFIG[PR_STATUS.DRAFT]

export const PRIORITIES = ['Low', 'Normal', 'High', 'Urgent']
export const PRIORITY_COLORS = { Urgent: '#b91c1c', High: '#ef4444', Normal: '#f59e0b', Low: '#10b981' }

// ── Purchase Order — mirrors App\Support\Purchase\PurchaseOrderStatus ─────────
export const PO_STATUS = {
  DRAFT:              'Draft',
  ISSUED:             'Issued',
  PARTIALLY_RECEIVED: 'Partially_Received',
  RECEIVED:           'Received',
  CLOSED:             'Closed',
  CANCELLED:          'Cancelled',
}
export const PO_STATUS_CONFIG = {
  [PO_STATUS.DRAFT]:              { label: 'Draft',              color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  [PO_STATUS.ISSUED]:            { label: 'Issued',             color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  [PO_STATUS.PARTIALLY_RECEIVED]: { label: 'Partially Received', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [PO_STATUS.RECEIVED]:          { label: 'Received',           color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [PO_STATUS.CLOSED]:            { label: 'Closed',             color: '#64748b', bg: 'rgba(100,116,139,0.15)' },
  [PO_STATUS.CANCELLED]:         { label: 'Cancelled',          color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const poStatusCfg = (s) => PO_STATUS_CONFIG[s] || PO_STATUS_CONFIG[PO_STATUS.DRAFT]

// ── Goods Receipt — mirrors App\Support\Purchase\GoodsReceiptStatus ───────────
export const GRN_STATUS_CONFIG = {
  Draft:     { label: 'Draft',     color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  Confirmed: { label: 'Confirmed', color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Cancelled: { label: 'Cancelled', color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const grnStatusCfg = (s) => GRN_STATUS_CONFIG[s] || GRN_STATUS_CONFIG.Draft

// PO pipeline stages for the header rail.
export const PO_STAGES = [
  { key: 'draft',     label: 'Draft',     sub: 'Preparing', statuses: [PO_STATUS.DRAFT] },
  { key: 'issued',    label: 'Issued',    sub: 'Ordered',   statuses: [PO_STATUS.ISSUED] },
  { key: 'receiving', label: 'Receiving', sub: 'Partial',   statuses: [PO_STATUS.PARTIALLY_RECEIVED] },
  { key: 'received',  label: 'Received',  sub: 'Complete',  statuses: [PO_STATUS.RECEIVED, PO_STATUS.CLOSED] },
]

// ── Purchase Invoice — mirrors App\Support\Purchase\PurchaseInvoiceStatus ─────
export const PINV_STATUS = {
  DRAFT:            'Draft',
  AWAITING_PAYMENT: 'Awaiting_Payment',
  PARTIALLY_PAID:   'Partially_Paid',
  PAID:             'Paid',
  CANCELLED:        'Cancelled',
}
export const PINV_STATUS_CONFIG = {
  [PINV_STATUS.DRAFT]:            { label: 'Draft',            color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  [PINV_STATUS.AWAITING_PAYMENT]: { label: 'Awaiting Payment', color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  [PINV_STATUS.PARTIALLY_PAID]:   { label: 'Partially Paid',   color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [PINV_STATUS.PAID]:             { label: 'Paid',             color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [PINV_STATUS.CANCELLED]:        { label: 'Cancelled',        color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const pinvStatusCfg = (s) => PINV_STATUS_CONFIG[s] || PINV_STATUS_CONFIG[PINV_STATUS.DRAFT]

export const PINV_STAGES = [
  { key: 'draft',    label: 'Draft',    sub: 'Entered',   statuses: [PINV_STATUS.DRAFT] },
  { key: 'awaiting', label: 'Approved', sub: 'Payable',   statuses: [PINV_STATUS.AWAITING_PAYMENT] },
  { key: 'partial',  label: 'Paying',   sub: 'Partial',   statuses: [PINV_STATUS.PARTIALLY_PAID] },
  { key: 'paid',     label: 'Paid',     sub: 'Settled',   statuses: [PINV_STATUS.PAID] },
]

// Payment modes — [value, label] pairs mirroring App\Support\Purchase\PurchasePaymentMode.
export const PAYMENT_MODES = [
  ['Bank_Transfer', 'Bank Transfer'], ['Cash', 'Cash'], ['Cheque', 'Cheque'],
  ['UPI', 'UPI'], ['Card', 'Card'], ['Other', 'Other'],
]
export const paymentModeLabel = (m) => (PAYMENT_MODES.find(([v]) => v === m) || [m, m])[1]

// ── Debit Note — mirrors App\Support\Purchase\PurchaseDebitNoteStatus ─────────
export const DN_STATUS = {
  DRAFT:     'Draft',
  OPEN:      'Open',
  SETTLED:   'Settled',
  CANCELLED: 'Cancelled',
}
export const DN_STATUS_CONFIG = {
  [DN_STATUS.DRAFT]:     { label: 'Draft',     color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  [DN_STATUS.OPEN]:      { label: 'Open',      color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [DN_STATUS.SETTLED]:   { label: 'Settled',   color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [DN_STATUS.CANCELLED]: { label: 'Cancelled', color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const dnStatusCfg = (s) => DN_STATUS_CONFIG[s] || DN_STATUS_CONFIG[DN_STATUS.DRAFT]

export const DN_STAGES = [
  { key: 'draft',   label: 'Draft',   sub: 'Preparing',  statuses: [DN_STATUS.DRAFT] },
  { key: 'open',    label: 'Open',    sub: 'Claim',      statuses: [DN_STATUS.OPEN] },
  { key: 'settled', label: 'Settled', sub: 'Refunded',   statuses: [DN_STATUS.SETTLED] },
]

// Procure-to-pay pipeline. Only the PR segment is live today; the rest are the
// downstream stages this request flows into (shown dimmed until built).
export const P2P_STAGES = [
  { key: 'request',  label: 'Request',   sub: 'Draft · Submit', statuses: [PR_STATUS.DRAFT, PR_STATUS.SUBMITTED] },
  { key: 'approval', label: 'Approval',  sub: 'Reviewed',       statuses: [PR_STATUS.APPROVED] },
  { key: 'order',    label: 'PO',        sub: 'Converted',      statuses: [PR_STATUS.CONVERTED] },
]

// ── Catalog status — App\Support\Purchase\PurchaseCatalogStatus ──────────────
export const CATALOG_STATUS = { DRAFT: 'Draft', ACTIVE: 'Active', DISCONTINUED: 'Discontinued' }
export const CATALOG_STATUS_CONFIG = {
  Draft:        { label: 'Draft',        color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  Active:       { label: 'Active',       color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Discontinued: { label: 'Discontinued', color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const catalogStatusCfg = (s) => CATALOG_STATUS_CONFIG[s] || CATALOG_STATUS_CONFIG.Draft

// ── Contract status — App\Support\Purchase\PurchaseContractStatus ────────────
export const CONTRACT_STATUS = { DRAFT: 'Draft', UNDER_REVIEW: 'Under_Review', ACTIVE: 'Active', EXPIRED: 'Expired', TERMINATED: 'Terminated' }
export const CONTRACT_STATUS_CONFIG = {
  Draft:        { label: 'Draft',        color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  Under_Review: { label: 'Under Review', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Active:       { label: 'Active',       color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Expired:      { label: 'Expired',      color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  Terminated:   { label: 'Terminated',   color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const contractStatusCfg = (s) => CONTRACT_STATUS_CONFIG[s] || CONTRACT_STATUS_CONFIG.Draft

export const CONTRACT_TYPES = [['rate_contract', 'Rate Contract'], ['msa', 'Master Service Agreement']]
export const contractTypeLabel = (t) => (CONTRACT_TYPES.find(([v]) => v === t) || [t, t])[1]

export const CONTRACT_STAGES = [
  { key: 'draft',   label: 'Draft',        sub: 'Building',    statuses: [CONTRACT_STATUS.DRAFT] },
  { key: 'review',  label: 'Under Review', sub: 'Approval',    statuses: [CONTRACT_STATUS.UNDER_REVIEW] },
  { key: 'active',  label: 'Active',       sub: 'In force',    statuses: [CONTRACT_STATUS.ACTIVE] },
  { key: 'closed',  label: 'Closed',       sub: 'Expired/Term', statuses: [CONTRACT_STATUS.EXPIRED, CONTRACT_STATUS.TERMINATED] },
]

// ── RFQ status — App\Support\Purchase\PurchaseRfqStatus ──────────────────────
export const RFQ_STATUS = { DRAFT: 'Draft', SENT: 'Sent', UNDER_REVIEW: 'Under_Review', AWARDED: 'Awarded', CANCELLED: 'Cancelled' }
export const RFQ_STATUS_CONFIG = {
  Draft:        { label: 'Draft',          color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  Sent:         { label: 'Sent to Vendors', color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  Under_Review: { label: 'Under Review',   color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Awarded:      { label: 'Awarded',        color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Cancelled:    { label: 'Cancelled',      color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const rfqStatusCfg = (s) => RFQ_STATUS_CONFIG[s] || RFQ_STATUS_CONFIG.Draft

export const RFQ_STAGES = [
  { key: 'draft',   label: 'Draft',       sub: 'Building',     statuses: [RFQ_STATUS.DRAFT] },
  { key: 'sent',    label: 'Sent',        sub: 'Awaiting',     statuses: [RFQ_STATUS.SENT] },
  { key: 'review',  label: 'Under Review', sub: 'Comparing',   statuses: [RFQ_STATUS.UNDER_REVIEW] },
  { key: 'awarded', label: 'Awarded',     sub: 'Converted',    statuses: [RFQ_STATUS.AWARDED] },
]

// ── Quotation status — App\Support\Purchase\PurchaseQuotationStatus ───────────
export const QUOTE_STATUS_CONFIG = {
  Draft:       { label: 'Draft',       color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  Received:    { label: 'Received',    color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  Shortlisted: { label: 'Shortlisted', color: '#a78bfa', bg: 'rgba(167,139,250,0.15)' },
  Awarded:     { label: 'Awarded',     color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Rejected:    { label: 'Rejected',    color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
export const quoteStatusCfg = (s) => QUOTE_STATUS_CONFIG[s] || QUOTE_STATUS_CONFIG.Draft

// ── 3-way match verdicts — App\Support\Purchase\ThreeWayMatch ────────────────
// Colour is paired with the label everywhere, so identity is never colour-alone.
export const MATCH_CONFIG = {
  Matched:        { label: 'Matched',        color: '#10b981', bg: 'rgba(16,185,129,0.15)', blocking: false },
  Over_Billed:    { label: 'Over-billed',    color: '#ef4444', bg: 'rgba(239,68,68,0.15)',  blocking: true },
  Price_Variance: { label: 'Price variance', color: '#ef4444', bg: 'rgba(239,68,68,0.15)',  blocking: true },
  Under_Billed:   { label: 'Under-billed',   color: '#f59e0b', bg: 'rgba(245,158,11,0.15)', blocking: false },
  Unmatched:      { label: 'Unmatched',      color: '#94a3b8', bg: 'rgba(148,163,184,0.15)', blocking: false },
}
export const matchCfg = (v) => MATCH_CONFIG[v] || MATCH_CONFIG.Unmatched

// The user's role helpers. Approval is admin-only on the backend (route middleware
// role:admin), so the UI only offers approve/reject to admins — anything else the
// server would 403 anyway. Editing/submitting a Draft is the owner or an admin.
export const canApprovePR = (user) => user?.role === 'admin'
export const canManagePR  = (user) => user?.role === 'admin' || user?.role === 'staff'

// Money formatter — Indian grouping, lakh short form for compact table cells.
export const fmtMoney = (n, currency = 'INR') => {
  const num = Number(n || 0)
  const sym = currency === 'INR' ? '₹' : ''
  return `${sym}${num.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}
export const fmtMoneyShort = (n) => {
  const num = Number(n || 0)
  if (num >= 100000) return `₹${(num / 100000).toFixed(1)}L`
  return `₹${num.toLocaleString('en-IN')}`
}
export const fmtDate = (d) =>
  d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

// Live line-item math — display only; the backend recomputes authoritatively.
export const lineAmount = (it) => {
  const base = (Number(it.qty) || 0) * (Number(it.rate) || 0)
  return base + base * ((Number(it.tax) || 0) / 100)
}
export const totalsOf = (items) => items.reduce((acc, it) => {
  const base = (Number(it.qty) || 0) * (Number(it.rate) || 0)
  acc.subtotal += base
  acc.tax += base * ((Number(it.tax) || 0) / 100)
  return acc
}, { subtotal: 0, tax: 0 })
