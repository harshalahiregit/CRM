import { useState } from 'react'
import { createPortal } from 'react-dom'
import { X, Loader2 } from 'lucide-react'

/**
 * Ask why a lead is being written off.
 *
 * The old CRM marked lost/junk with a bare confirm, so a workspace ended up with
 * a pile of lost leads and no way to review why. The reason is optional — it must
 * never block the action — but the common answers are offered as one-tap chips so
 * capturing one costs nothing.
 */
const PRESETS = {
  lost: ['Price too high', 'Chose a competitor', 'Budget cut', 'No response', 'Bad timing', 'Not a fit'],
  junk: ['Spam submission', 'Test entry', 'Duplicate', 'Wrong contact details', 'Not a real enquiry'],
}

const COPY = {
  lost: {
    title: 'Mark this lead as lost?',
    body: 'It moves out of the active pipeline. You can restore it later.',
    confirm: 'Mark lost',
    tone: '#f59e0b',
  },
  junk: {
    title: 'Mark this lead as junk?',
    body: 'Use this for spam and test entries so they stop skewing your numbers.',
    confirm: 'Mark junk',
    tone: '#ef4444',
  },
}

export default function ReasonDialog({ kind = 'lost', onCancel, onConfirm, busy = false }) {
  const [reason, setReason] = useState('')
  const copy = COPY[kind] || COPY.lost

  return createPortal(
    <div className="fixed inset-0 z-[130] flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,0.55)' }}>
      <div className="w-full max-w-md rounded-2xl p-5" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <div className="flex items-start justify-between gap-3 mb-2">
          <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{copy.title}</p>
          <button onClick={onCancel} className="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0"
            style={{ border: '1px solid var(--border)' }}>
            <X size={13} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>
        <p className="text-xs mb-3" style={{ color: 'var(--text-muted)' }}>{copy.body}</p>

        <label className="label">Reason <span style={{ color: 'var(--text-muted)' }}>(optional)</span></label>
        <input autoFocus className="input-3d text-sm" value={reason} onChange={e => setReason(e.target.value)}
          placeholder="Why is this being written off?"
          onKeyDown={e => e.key === 'Enter' && onConfirm(reason)} />

        <div className="flex flex-wrap gap-1.5 mt-2">
          {(PRESETS[kind] || []).map(p => (
            <button key={p} onClick={() => setReason(p)}
              className="px-2.5 py-1 rounded-lg text-[11px] font-semibold transition-colors"
              style={reason === p
                ? { background: 'rgba(124,58,237,0.14)', color: '#a78bfa', border: '1px solid rgba(124,58,237,0.3)' }
                : { background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
              {p}
            </button>
          ))}
        </div>

        <div className="flex justify-end gap-2 mt-4">
          <button onClick={onCancel} className="px-4 py-2 rounded-xl text-xs font-bold"
            style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          <button onClick={() => onConfirm(reason)} disabled={busy}
            className="px-4 py-2 rounded-xl text-xs font-bold text-white inline-flex items-center gap-1.5 disabled:opacity-60"
            style={{ background: copy.tone }}>
            {busy && <Loader2 size={11} className="animate-spin" />} {copy.confirm}
          </button>
        </div>
      </div>
    </div>,
    document.body,
  )
}
