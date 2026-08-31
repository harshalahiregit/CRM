/**
 * The parts every SangoeTrack approval screen shares.
 *
 * Four screens differ only in which fields a row shows; the header, the
 * pending-only caveat, the load and error states, and the approve/reject
 * exchange are identical. Keeping them here means the honest empty state gets
 * written once instead of four times — and it is the part most likely to be
 * quietly dropped if each screen re-implemented it.
 */

import { useState } from 'react'
import { RefreshCw, Inbox, Check, X, Download } from 'lucide-react'
import { exportCsv } from '@/lib/exportCsv'
import LoadError from '@/components/ui/LoadError'
import EmptyState from '@/components/ui/EmptyState'
import { trackErrorMessage } from '@/services/sangoeTrackApi'
import { useToast } from '@/hooks/useToast'

/* ── header ──────────────────────────────────────────────────────────── */

export function TrackHeader({ title, subtitle, onRefresh, loading }) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 className="text-xl font-bold" style={{ color: 'var(--text-h)' }}>{title}</h1>
        <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{subtitle}</p>
      </div>
      <button
        onClick={onRefresh}
        disabled={loading}
        className="rounded-lg text-xs font-semibold flex items-center gap-1.5 disabled:opacity-50"
        style={{ padding: '7px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}
      >
        <RefreshCw size={13} className={loading ? 'animate-spin' : ''} />
        Refresh
      </button>
    </div>
  )
}

/* ── list states ─────────────────────────────────────────────────────── */

/**
 * Wraps loading / error / empty so every queue behaves the same.
 *
 * `noun` completes the sentence "No <noun> are waiting for a decision" — and the
 * description names the limitation rather than leaving an empty screen to be
 * misread as an empty history.
 */
export function TrackList({ loading, error, rows, onRetry, noun, children }) {
  if (error)   return <LoadError error={error} onRetry={onRetry} title="Could not load the queue" />
  if (loading) return <p className="text-sm py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</p>

  if (!rows.length) {
    return (
      <EmptyState
        icon={Inbox}
        title={`No ${noun} are waiting for a decision`}
        description={
          `SangoeTrack only returns items that are still pending, so anything already ` +
          `approved or rejected will not appear here. Its own records hold the history.`
        }
      />
    )
  }

  return <div className="flex flex-col gap-2.5">{children}</div>
}

/* ── one row ─────────────────────────────────────────────────────────── */

export function TrackCard({ who, when, children, footer }) {
  return (
    <div className="rounded-xl p-4 flex flex-col gap-3"
      style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <span className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{who}</span>
        {when && <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Applied {when}</span>}
      </div>
      {children}
      {footer}
    </div>
  )
}

/** A labelled value. Long free text (a reason) wraps; short facts sit inline. */
export function Field({ label, value, wide = false, tone }) {
  if (value === null || value === undefined || value === '') return null
  return (
    <div style={{ gridColumn: wide ? '1 / -1' : undefined }}>
      <div className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
        {label}
      </div>
      <div className="text-sm mt-0.5" style={{ color: tone ?? 'var(--text-h)', wordBreak: 'break-word' }}>
        {value}
      </div>
    </div>
  )
}

export function FieldGrid({ children }) {
  return (
    <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))' }}>
      {children}
    </div>
  )
}

/* ── export ──────────────────────────────────────────────────────────── */

/**
 * CSV of exactly what is on screen — same filters, same date range.
 *
 * "Export this" means the thing you are looking at, so it takes the already
 * filtered rows rather than re-fetching everything. The one thing to be careful
 * of: on a paginated history view this exports the CURRENT PAGE, and the button
 * says so rather than letting someone believe they have the lot.
 */
export function ExportButton({ filename, rows, columns, total }) {
  const n = rows?.length ?? 0
  const partial = total != null && total > n

  return (
    <button
      onClick={() => exportCsv(filename, rows, columns)}
      disabled={n === 0}
      title={
        n === 0 ? 'Nothing to export'
          : partial ? `Exports the ${n} rows on this page, not all ${total}`
          : `Export ${n} rows`
      }
      className="rounded-lg text-xs font-semibold flex items-center gap-1.5 disabled:opacity-40"
      style={{ padding: '7px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}
    >
      <Download size={13} />
      {partial ? `Export page (${n})` : 'Export'}
    </button>
  )
}

/* ── pending vs history ──────────────────────────────────────────────── */

/**
 * The two halves of every approval screen.
 *
 * Kept as a visible switch rather than one merged list because they are
 * genuinely different things: one is a worklist you act on, the other is a
 * record you read. Merging them would put approve/reject buttons next to
 * decisions already made.
 */
export function QueueTabs({ tab, onChange, pendingCount }) {
  const tabs = [
    { key: 'pending', label: 'Waiting on you', count: pendingCount },
    { key: 'history', label: 'History' },
  ]
  return (
    <div className="flex gap-1.5">
      {tabs.map(t => {
        const on = tab === t.key
        return (
          <button key={t.key} onClick={() => onChange(t.key)} aria-pressed={on}
            className="rounded-lg text-xs font-semibold flex items-center gap-1.5"
            style={{
              padding: '7px 14px',
              background: on ? 'rgba(124,58,237,0.14)' : 'transparent',
              border: `1px solid ${on ? '#7C3AED' : 'var(--border)'}`,
              color: on ? '#a78bfa' : 'var(--text-muted)',
            }}>
            {t.label}
            {t.count != null && t.count > 0 && (
              <span style={{ fontVariantNumeric: 'tabular-nums', opacity: 0.8 }}>{t.count}</span>
            )}
          </button>
        )
      })}
    </div>
  )
}

const STATUS_SETS = {
  decision: [['', 'Any outcome'], ['approved', 'Approved'], ['rejected', 'Rejected'], ['pending', 'Still pending']],
  // All ten of SangoeTrack's advance statuses. Four were missing — settled and
  // closed among them — so the advances that matter most at month end could be
  // seen under "Any status" and never isolated.
  advance:  [['', 'Any status'],
             ['pending', 'Pending'],
             ['manager_approved', 'Manager approved'],
             ['accounts_approved', 'Accounts approved'],
             ['approved', 'Ready to disburse'],
             ['disbursed', 'Disbursed'],
             ['settlement_submitted', 'Settlement submitted'],
             ['settlement_rejected', 'Settlement rejected'],
             ['settled', 'Settled'],
             ['closed', 'Closed'],
             ['rejected', 'Rejected']],
}

/**
 * Date range and outcome. `statuses` picks the vocabulary — advances move
 * through a longer chain than a simple approved/rejected.
 */
export function HistoryFilters({ filters, setFilter, clear, active, statuses = 'decision', children }) {
  const options = STATUS_SETS[statuses] ?? STATUS_SETS.decision
  const field = {
    background: 'var(--bg-input)', border: '1px solid var(--border)',
    color: 'var(--text-h)', padding: '6px 10px', borderRadius: 8, fontSize: 13,
  }
  return (
    <div className="flex flex-wrap items-end gap-2">
      <label className="flex flex-col gap-1">
        <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>From</span>
        <input type="date" value={filters.from} onChange={e => setFilter('from', e.target.value)} style={field} />
      </label>
      <label className="flex flex-col gap-1">
        <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>To</span>
        <input type="date" value={filters.to} onChange={e => setFilter('to', e.target.value)} style={field} />
      </label>
      <label className="flex flex-col gap-1">
        <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Outcome</span>
        <select value={filters.status} onChange={e => setFilter('status', e.target.value)} style={field}>
          {options.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
      </label>
      {children}
      {active > 0 && (
        <button onClick={clear}
          className="rounded-lg text-xs font-semibold"
          style={{ padding: '7px 12px', background: 'transparent', border: '1px solid var(--border)', color: '#a78bfa' }}>
          Clear filters
        </button>
      )}
    </div>
  )
}

/**
 * Page controls plus the honest count.
 *
 * Shows the total from the server rather than the rows on screen — "25 shown"
 * when there are 400 is the kind of number people quote in meetings.
 */
export function HistoryPager({ meta, page, setPage, noun }) {
  if (!meta) return null
  const pages = meta.pages ?? 1
  const total = meta.total ?? 0
  const from  = total === 0 ? 0 : (page - 1) * (meta.per_page ?? 25) + 1
  const to    = Math.min(page * (meta.per_page ?? 25), total)

  const btn = (label, target, disabled) => (
    <button onClick={() => setPage(target)} disabled={disabled}
      className="rounded-lg text-xs font-semibold disabled:opacity-35"
      style={{ padding: '6px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
      {label}
    </button>
  )

  return (
    <div className="flex flex-wrap items-center justify-between gap-2 pt-1">
      <span className="text-xs" style={{ color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>
        {total === 0 ? `No ${noun}` : `${from}–${to} of ${total} ${noun}`}
        {meta.from && meta.to && ` · ${meta.from} to ${meta.to}`}
      </span>
      {pages > 1 && (
        <div className="flex items-center gap-1.5">
          {btn('Previous', page - 1, page <= 1)}
          <span className="text-xs px-1" style={{ color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>
            {page} / {pages}
          </span>
          {btn('Next', page + 1, page >= pages)}
        </div>
      )}
    </div>
  )
}

/** The outcome of a past decision, said the way a person would. */
export function Outcome({ status }) {
  const tone = {
    approved:  { fg: '#34d399', label: 'Approved' },
    rejected:  { fg: '#f87171', label: 'Rejected' },
    pending:   { fg: '#fbbf24', label: 'Still pending' },
    // The advance chain. Settled and closed are the finished states and read as
    // such; the two settlement ones are still waiting on somebody.
    disbursed:              { fg: '#60a5fa', label: 'Disbursed' },
    settlement_submitted:   { fg: '#fbbf24', label: 'Settlement submitted' },
    settlement_rejected:    { fg: '#f87171', label: 'Settlement rejected' },
    settled:                { fg: '#34d399', label: 'Settled' },
    closed:                 { fg: '#8894a2', label: 'Closed' },
  }[String(status ?? '').toLowerCase()]
    ?? { fg: 'var(--text-muted)', label: String(status ?? '—').replace(/_/g, ' ') }

  return (
    <span className="rounded-md text-[11px] font-bold px-2 py-1 self-start"
      style={{ color: tone.fg, background: 'var(--bg-input)', whiteSpace: 'nowrap', textTransform: 'capitalize' }}>
      {tone.label}
    </span>
  )
}

/** Who decided and when — absent while a request is still open. */
export function DecidedBy({ by, at, remarks }) {
  if (!by && !at && !remarks) return null
  return (
    <div className="text-[11px] rounded-lg px-2.5 py-1.5" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
      {(by || at) && <span>{by ?? 'Someone'}{at ? ` · ${at}` : ''}</span>}
      {remarks && <span style={{ color: 'var(--text-h)' }}>{by || at ? ' — ' : ''}{remarks}</span>}
    </div>
  )
}

/* ── the decision ────────────────────────────────────────────────────── */

/**
 * Approve / Reject with an optional note.
 *
 * The note is opened by the action rather than always shown: an approver who has
 * already decided should not have to tab past an empty box, but a rejection
 * without a reason is unhelpful to whoever receives it, so rejecting opens it
 * expanded and says so.
 *
 * `onDecide(status, remark)` must return a promise. Errors surface SangoeTrack's
 * own wording — it is more specific than anything we would invent.
 */
export function DecisionBar({ onDecide, onDone, approveLabel = 'Approve', extra }) {
  const [pending, setPending] = useState(null)   // 'approved' | 'rejected' | null
  const [remark, setRemark]   = useState('')
  const [busy, setBusy]       = useState(false)
  const toast = useToast()

  async function confirm() {
    setBusy(true)
    try {
      await onDecide(pending, remark.trim() || null)
      toast.success(pending === 'approved' ? 'Approved' : 'Rejected')
      setPending(null)
      setRemark('')
      onDone?.()
    } catch (err) {
      // Their message, not ours — "Leave not found" beats "Something went wrong".
      toast.error(trackErrorMessage(err, 'SangoeTrack refused this.'))
    } finally {
      setBusy(false)
    }
  }

  if (pending) {
    return (
      <div className="flex flex-col gap-2 rounded-lg p-3" style={{ background: 'var(--bg-input)' }}>
        <label className="text-[11px] font-semibold" style={{ color: 'var(--text-muted)' }}>
          {pending === 'rejected'
            ? 'Why are you rejecting this? The employee will see it.'
            : 'Note (optional)'}
        </label>
        <textarea
          value={remark}
          onChange={e => setRemark(e.target.value)}
          rows={2}
          maxLength={1000}
          autoFocus
          className="rounded-lg text-sm px-2.5 py-2 resize-y"
          style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }}
        />
        <div className="flex gap-2">
          <button onClick={confirm} disabled={busy}
            className="rounded-lg text-xs font-bold disabled:opacity-50"
            style={{
              padding: '7px 14px', color: '#fff',
              background: pending === 'approved' ? '#059669' : '#dc2626',
            }}>
            {busy ? 'Sending…' : pending === 'approved' ? `Confirm ${approveLabel.toLowerCase()}` : 'Confirm reject'}
          </button>
          <button onClick={() => { setPending(null); setRemark('') }} disabled={busy}
            className="rounded-lg text-xs font-semibold disabled:opacity-50"
            style={{ padding: '7px 14px', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
            Cancel
          </button>
        </div>
      </div>
    )
  }

  return (
    <div className="flex flex-wrap gap-2 items-center">
      <button onClick={() => setPending('approved')}
        className="rounded-lg text-xs font-bold flex items-center gap-1.5"
        style={{ padding: '7px 14px', background: 'rgba(5,150,105,0.14)', color: '#34d399' }}>
        <Check size={13} /> {approveLabel}
      </button>
      <button onClick={() => setPending('rejected')}
        className="rounded-lg text-xs font-bold flex items-center gap-1.5"
        style={{ padding: '7px 14px', background: 'rgba(220,38,38,0.12)', color: '#f87171' }}>
        <X size={13} /> Reject
      </button>
      {extra}
    </div>
  )
}
