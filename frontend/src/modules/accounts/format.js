// Shared formatting helpers for the Accounts module (₹ / en-IN).
export const inr = (v) =>
  '₹' + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export const inrPlain = (v) =>
  Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export const fmtDate = (d) =>
  d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

export const NATURE_LABEL = {
  asset: 'Asset', liability: 'Liability', equity: 'Equity', income: 'Income', expense: 'Expense',
}

export const VOUCHER_TYPES = [
  { code: 'journal', label: 'Journal' },
  { code: 'payment', label: 'Payment' },
  { code: 'receipt', label: 'Receipt' },
  { code: 'contra', label: 'Contra' },
  { code: 'sales', label: 'Sales' },
  { code: 'purchase', label: 'Purchase' },
  { code: 'debit_note', label: 'Debit Note' },
  { code: 'credit_note', label: 'Credit Note' },
  { code: 'stock_journal', label: 'Stock Journal' },
]
