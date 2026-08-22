import { AlertTriangle, RotateCcw } from 'lucide-react'

/**
 * "This did not load" — the state most pages were missing entirely.
 *
 * A failed fetch left as `data === undefined` renders as an empty result, and
 * an empty result is a claim: zero invoices, no checklists, nothing overdue. On
 * the accounts reports it was worse than blank — an undefined `balanced` flag
 * is falsy, so a dead endpoint reported "⚠ Out of balance" to an accountant.
 *
 * So this says the request failed, shows what the server said, and offers the
 * retry — which is the only thing the user can actually do about it.
 *
 * @param error    an Error, or an axios error; the server's message is preferred
 * @param onRetry  usually react-query's refetch
 * @param title    override for a page where "Could not load" reads oddly
 */
export default function LoadError({ error, onRetry, title = 'Could not load this', className = '' }) {
  const detail =
    error?.response?.data?.message ||
    error?.message ||
    'The server did not respond.'

  return (
    <div className={`rounded-2xl px-5 py-6 text-center ${className}`}
      style={{ border: '1px solid rgba(239,68,68,0.35)', background: 'rgba(239,68,68,0.06)' }}>
      <AlertTriangle size={22} style={{ color: '#f87171', margin: '0 auto' }} />
      <p className="text-sm font-bold mt-2" style={{ color: 'var(--text-h)' }}>{title}</p>
      <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{detail}</p>
      {onRetry && (
        <button type="button" onClick={() => onRetry()}
          className="inline-flex items-center gap-1.5 text-xs font-bold mt-3 rounded-lg"
          style={{ padding: '7px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#a78bfa' }}>
          <RotateCcw size={12} /> Try again
        </button>
      )}
    </div>
  )
}
