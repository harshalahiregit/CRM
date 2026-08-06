// A tiny, shared list toolbar: a page-size selector + a refresh button. Dropped
// into the header of every module list (Tasks / Projects / Inventory / Helpdesk)
// so they all get the same two controls. Refresh spins while its promise runs.
import { useState } from 'react'
import { RefreshCw } from 'lucide-react'

const DEFAULT_SIZES = [
  { value: 25, label: '25' }, { value: 50, label: '50' },
  { value: 100, label: '100' }, { value: 0, label: 'All' },
]

export default function ListControls({
  pageSize, onPageSize, sizes = DEFAULT_SIZES, onRefresh,
  accent = 'var(--color-primary-500)', className = '',
}) {
  const [spinning, setSpinning] = useState(false)

  const refresh = async () => {
    if (spinning || !onRefresh) return
    setSpinning(true)
    try { await onRefresh() } finally { setTimeout(() => setSpinning(false), 400) }
  }

  return (
    <div className={`flex items-center gap-2 ${className}`}>
      {onPageSize && (
        <select value={pageSize} onChange={e => onPageSize(Number(e.target.value))}
          aria-label="Rows per page" title="Rows per page"
          className="rounded-lg outline-none text-xs" style={{ padding: '7px 8px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
          {sizes.map(s => <option key={s.value} value={s.value}>{s.label}</option>)}
        </select>
      )}
      {onRefresh && (
        <button type="button" onClick={refresh} disabled={spinning} title="Refresh" aria-label="Refresh"
          className="flex items-center justify-center rounded-lg disabled:opacity-60"
          style={{ width: 32, height: 32, background: 'var(--bg-input)', border: '1px solid var(--border)', color: accent }}>
          <RefreshCw size={14} className={spinning ? 'animate-spin' : ''} />
        </button>
      )}
    </div>
  )
}
