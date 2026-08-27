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
import { RefreshCw, Inbox, Check, X } from 'lucide-react'
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
