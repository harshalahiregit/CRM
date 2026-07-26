import { useState, useEffect, useRef } from 'react'
import { Clock, AlertTriangle } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'

/**
 * Sticky countdown banner for a Temporary TPV (PRD §4.10). Renders only when the
 * signed-in vendor is temporary. The remaining time is server-authoritative —
 * fetched from /tpv/access/countdown, ticked locally each second, and reconciled
 * with the server every 30s. Both timers pause when the tab is hidden.
 */

const BANDS = {
  green:   { bg: 'rgba(16,185,129,0.14)', border: 'rgba(16,185,129,0.45)', color: '#10b981' },
  orange:  { bg: 'rgba(245,158,11,0.14)', border: 'rgba(245,158,11,0.45)', color: '#f59e0b' },
  red:     { bg: 'rgba(239,68,68,0.16)',  border: 'rgba(239,68,68,0.5)',   color: '#ef4444' },
  expired: { bg: 'rgba(239,68,68,0.2)',   border: 'rgba(239,68,68,0.65)',  color: '#ef4444' },
}

const DAY = 86400
const bandFor = (s) => (s <= 0 ? 'expired' : s <= DAY ? 'red' : s <= 3 * DAY ? 'orange' : 'green')

function fmt(sec) {
  const d = Math.floor(sec / DAY)
  const h = Math.floor((sec % DAY) / 3600)
  const m = Math.floor((sec % 3600) / 60)
  const p = (n) => String(n).padStart(2, '0')
  return `${d} Day${d === 1 ? '' : 's'} ${p(h)} Hours ${p(m)} Minutes`
}

// Project a countdown payload from a vendor record (used when data is already
// loaded — e.g. the onboarding wizard — so no extra API call is made).
function fromVendor(v) {
  if (!v || !v.is_temporary) return { is_temporary: false }
  const secs = v.access_expires_at
    ? Math.max(0, Math.floor((new Date(v.access_expires_at).getTime() - Date.now()) / 1000))
    : 0
  return { is_temporary: true, access_status: v.access_status, seconds_remaining: secs }
}

export default function TemporaryAccessBanner({ vendor = null }) {
  const selfFetch = !vendor
  const [data, setData] = useState(vendor ? fromVendor(vendor) : null)
  const [secs, setSecs] = useState(vendor ? fromVendor(vendor).seconds_remaining || 0 : 0)

  const reconcile = () =>
    tpvApi.access.countdown()
      .then((d) => { setData(d); setSecs(Math.max(0, d.seconds_remaining || 0)) })
      .catch(() => {})

  // Self-fetch (portal context): initial + periodic server reconcile, paused when hidden.
  useEffect(() => {
    if (!selfFetch) return undefined
    reconcile()
    const id = setInterval(() => { if (!document.hidden) reconcile() }, 30000)
    return () => clearInterval(id)
  }, [selfFetch])

  // Prop-driven context: re-project when the passed vendor changes.
  useEffect(() => {
    if (selfFetch) return
    const d = fromVendor(vendor)
    setData(d); setSecs(d.seconds_remaining || 0)
  }, [selfFetch, vendor])

  // Smooth per-second local tick, paused when hidden.
  useEffect(() => {
    const id = setInterval(() => { if (!document.hidden) setSecs((s) => Math.max(0, s - 1)) }, 1000)
    return () => clearInterval(id)
  }, [])

  if (!data || !data.is_temporary) return null

  const expired = secs <= 0 || data.access_status === 'Expired'
  const cfg = BANDS[expired ? 'expired' : bandFor(secs)]
  const Icon = expired ? AlertTriangle : Clock

  return (
    <div role="status" aria-live="polite"
      style={{ position: 'sticky', top: 0, zIndex: 50, display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap',
        padding: '9px 16px', fontSize: 13, fontWeight: 700, background: cfg.bg, borderBottom: `1px solid ${cfg.border}`, color: cfg.color }}>
      <Icon size={15} style={{ flexShrink: 0 }} />
      <span style={{ fontWeight: 800 }}>Temporary Third Party Vendor</span>
      {expired
        ? <span style={{ fontWeight: 600 }}>— Access expired. Please contact your administrator.</span>
        : <span style={{ fontWeight: 600 }}>— Access expires in: {fmt(secs)}</span>}
    </div>
  )
}
