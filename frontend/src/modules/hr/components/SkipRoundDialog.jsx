import { useState } from 'react'
import { SkipForward, Loader2 } from 'lucide-react'
import Drawer from '@/components/ui/Drawer'
import { hrApi } from '@/services/hrApi'

// Reasons an optional round may be skipped.
export const SKIP_REASONS = [
  'Client not required',
  'Business decision',
  'Internal hiring',
  'Already evaluated',
  'Other',
]

/**
 * The ONE skip confirmation dialog in the product.
 *
 * Used from two places, with the same UI and the same write path:
 *   • Candidate workspace → skipping a round that is already scheduled  (`interview`)
 *   • Schedule drawer     → skipping a round that was never scheduled   (`createPayload`)
 *
 * A skipped round is closed as status=Completed / result=Skipped through the existing
 * recordFeedback endpoint — never Failed, never Cancelled, and no new API. When the
 * round does not exist yet it is created first with the existing schedule endpoint so
 * the timeline and the sequence gate have a real record to reason about.
 */
export default function SkipRoundDialog({
  open, roundName, interview = null, createPayload = null, onClose, onDone, showToast,
}) {
  const [reason, setReason] = useState(SKIP_REASONS[0])
  const [other, setOther] = useState('')
  const [busy, setBusy] = useState(false)

  if (!open) return null

  const confirm = async () => {
    const text = reason === 'Other' ? other.trim() : reason
    if (!text) return showToast?.('Skip reason is required.', 'error')
    setBusy(true)
    try {
      // The round must exist before it can be closed as Skipped.
      const target = interview || await hrApi.interviews.schedule(createPayload)
      await hrApi.interviews.recordFeedback(target.id, {
        result: 'Skipped',
        status: 'Completed',
        notes: `Round skipped - ${text}`,
      })
      showToast?.(`${roundName} skipped`)
      setReason(SKIP_REASONS[0]); setOther('')
      onDone?.()
      onClose?.()
    } catch (e) {
      showToast?.(e.response?.data?.message || 'Could not skip the round', 'error')
    } finally { setBusy(false) }
  }

  return (
    <Drawer
      open onClose={() => !busy && onClose?.()}
      title={`Skip ${roundName}?`}
      width="min(520px, 95vw)"
      footer={(
        <div className="flex gap-3">
          <button onClick={() => onClose?.()} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          <button onClick={confirm} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity: busy ? 0.7 : 1 }}>
            {busy ? <Loader2 size={14} className="animate-spin" /> : <SkipForward size={14} />} Skip Round
          </button>
        </div>
      )}
    >
      <p className="text-xs mb-4 leading-relaxed" style={{ color: 'var(--text-muted)' }}>
        <b style={{ color: 'var(--text-h)' }}>{roundName} is optional.</b> Are you sure you want to skip this interview round?
        <br />It is recorded as <b style={{ color: 'var(--text-h)' }}>Skipped</b> — not failed and not cancelled — and the next round unlocks immediately.
      </p>

      <div className="space-y-3">
        <div>
          <label className="label">Reason *</label>
          <select className="input-3d text-sm" value={reason} onChange={e => setReason(e.target.value)}>
            {SKIP_REASONS.map(r => <option key={r}>{r}</option>)}
          </select>
        </div>
        {reason === 'Other' && (
          <div>
            <label className="label">Skip Reason *</label>
            <textarea rows={3} className="input-3d text-sm resize-none" placeholder="Why is this round being skipped?"
              value={other} onChange={e => setOther(e.target.value)}
              style={!other.trim() ? { borderColor: 'rgba(248,113,113,0.5)' } : undefined} />
          </div>
        )}
      </div>
    </Drawer>
  )
}
