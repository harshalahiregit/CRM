import { useEffect, useState } from 'react'

/**
 * Remaining-validity badge for a Purchase Vendor.
 *
 * Purchase-owned: no TPV import, no shared business logic (TPV has its own
 * TemporaryTpvValidityBadge). Takes the server's `validity_countdown` payload
 * and ticks locally off `expires_at` — one timer per badge, minute precision,
 * never a backend call.
 *
 * Permanent accounts render a plain grey "Permanent" and never a countdown.
 */
const COLORS = {
  permanent: { color: '#94a3b8', bg: 'rgba(148,163,184,0.15)', ring: 'rgba(148,163,184,0.35)' },
  safe:      { color: '#10b981', bg: 'rgba(16,185,129,0.15)',  ring: 'rgba(16,185,129,0.35)' },
  soon:      { color: '#f97316', bg: 'rgba(249,115,22,0.15)',  ring: 'rgba(249,115,22,0.35)' },
  expired:   { color: '#ef4444', bg: 'rgba(239,68,68,0.15)',   ring: 'rgba(239,68,68,0.35)' },
  awaiting:  { color: '#64748b', bg: 'rgba(100,116,139,0.15)', ring: 'rgba(100,116,139,0.35)' },
}

/** Seconds → { label, tone }. Pure, so it stays testable and predictable. */
export function purchaseValidityLabel(countdown, nowMs = Date.now()) {
  if (!countdown || !countdown.is_temporary) return { label: 'Permanent', tone: 'permanent' }

  const seconds = countdown.expires_at
    ? Math.max(0, Math.floor((new Date(countdown.expires_at).getTime() - nowMs) / 1000))
    : (countdown.remaining_seconds ?? 0)

  // The clock only starts at activation — a Draft/Registered account has
  // not begun, so it is never reported as expired.
  if (countdown.is_awaiting_activation || countdown.state === 'awaiting_activation') {
    return { label: 'Awaiting Activation', tone: 'awaiting' }
  }

  if (countdown.is_expired || seconds <= 0) return { label: 'Expired', tone: 'expired' }

  // Presentation only — the stored expiry is never altered. Days round UP, so a
  // window activated moments ago reads "5 Days Left" instead of the literal
  // "4 Days 23 Hours Left", and the figure then holds steady for a whole day.
  const tone = seconds > 3 * 86400 ? 'safe' : 'soon'

  if (seconds >= 86400) {
    const days = Math.ceil(seconds / 86400)
    return { label: `${days} ${days === 1 ? 'Day' : 'Days'} Left`, tone }
  }

  const hours = Math.floor(seconds / 3600)
  if (hours >= 1) return { label: `${hours} ${hours === 1 ? 'Hour' : 'Hours'} Left`, tone }
  return { label: 'Expires Today', tone }
}

export default function TemporaryVendorValidityBadge({ countdown, compact = false, showLabel = false }) {
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    if (!countdown?.is_temporary || countdown?.is_expired || countdown?.is_awaiting_activation) return undefined
    // Minute precision is enough — one interval, no polling.
    const t = setInterval(() => setNow(Date.now()), 60_000)
    return () => clearInterval(t)
  }, [countdown?.is_temporary, countdown?.is_expired, countdown?.is_awaiting_activation])

  const { label, tone } = purchaseValidityLabel(countdown, now)
  const cfg = COLORS[tone]

  return (
    <span
      title={countdown?.expires_at ? `Access expires ${new Date(countdown.expires_at).toLocaleString()}` : 'Permanent access'}
      style={{
        display: 'inline-flex', alignItems: 'center', gap: 5, whiteSpace: 'nowrap',
        padding: compact ? '2px 8px' : '4px 11px',
        borderRadius: 999, fontSize: compact ? 10.5 : 12, fontWeight: 800,
        color: cfg.color, background: cfg.bg, border: `1px solid ${cfg.ring}`,
      }}
    >
      {showLabel && tone !== 'permanent' && tone !== 'awaiting' && (
        <span style={{ opacity: 0.75, fontWeight: 700, textTransform: 'uppercase', fontSize: compact ? 9 : 10 }}>Remaining</span>
      )}
      {label}
    </span>
  )
}
