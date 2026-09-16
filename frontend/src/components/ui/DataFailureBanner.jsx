import { useEffect, useState } from 'react'
import { AlertTriangle, RotateCw, X } from 'lucide-react'
import {
  subscribeToRequestFailures,
  clearRequestFailures,
} from '@/lib/requestFailures'

/**
 * "Some of this did not load."
 *
 * The portal pages nearly all fetch as `.then(setRows).catch(() => setRows([]))`,
 * which renders a failure as an empty list. A vendor with six contacts was being
 * shown "No contacts yet" — not a bug they would report, because it reads as a
 * true statement about an empty account.
 *
 * This does not fix those call sites; it stops them being silent. The page keeps
 * its empty state and this says, above it, that something failed and the screen
 * is therefore incomplete. Wrong-and-quiet becomes incomplete-and-declared,
 * which is the difference between a vendor trusting a blank screen and one
 * ringing to ask about it.
 *
 * Retry is a reload on purpose: the failures came from many components with no
 * shared refetch, and a reload is the one action guaranteed to re-run all of them.
 */
export default function DataFailureBanner() {
  const [failures, setFailures] = useState([])
  const [dismissed, setDismissed] = useState(false)

  useEffect(() => subscribeToRequestFailures(setFailures), [])

  // A new failure after a dismissal is new news, so the banner comes back.
  useEffect(() => { if (failures.length) setDismissed(false) }, [failures.length])

  if (!failures.length || dismissed) return null

  const n = failures.length

  return (
    <div role="status" style={wrap}>
      <AlertTriangle size={16} style={{ color: '#f59e0b', flexShrink: 0 }} />

      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontWeight: 700, fontSize: 13, color: 'var(--text-h)' }}>
          {n === 1 ? 'Some information could not be loaded.' : `${n} parts of this page could not be loaded.`}
        </div>
        {/* Named plainly: an empty section below might be genuinely empty, or it
            might be this. The vendor cannot tell, so say so. */}
        <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>
          Anything showing as empty below may not be empty. This is a fault on our side, not your data.
        </div>
      </div>

      <button onClick={() => { clearRequestFailures(); window.location.reload() }} style={btn}>
        <RotateCw size={13} /> Retry
      </button>
      <button onClick={() => setDismissed(true)} style={{ ...btn, border: 'none', padding: 6 }} aria-label="Dismiss">
        <X size={14} />
      </button>
    </div>
  )
}

const wrap = {
  display: 'flex', alignItems: 'center', gap: 12,
  padding: '10px 14px', marginBottom: 14,
  borderRadius: 10,
  background: 'rgba(245,158,11,0.10)',
  border: '1px solid rgba(245,158,11,0.35)',
}

const btn = {
  display: 'inline-flex', alignItems: 'center', gap: 6,
  padding: '6px 12px', borderRadius: 8,
  border: '1px solid var(--border)', background: 'transparent',
  color: 'var(--text-h)', fontSize: 12, fontWeight: 600, cursor: 'pointer',
  flexShrink: 0,
}
