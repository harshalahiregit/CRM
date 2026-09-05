import { useState, useEffect } from 'react'
import { Link } from 'react-router-dom'
import { CalendarDays, Video, AlertTriangle, ChevronRight } from 'lucide-react'

/**
 * The vendor's meeting schedule, on the portal dashboard.
 *
 * Shared by BOTH vendor portals (TPV and Purchase), which hit their own separate
 * backends — this takes a `load` fetcher and knows nothing about either. It
 * exists because the two dashboards disagreed about meetings in opposite ways:
 * the TPV dashboard showed none at all, so a vendor had no idea a meeting was
 * booked without going looking for it, and the Purchase dashboard popped a join
 * link only while the meeting was still in the FUTURE — so the link vanished at
 * the moment it became useful, and never said the meeting had passed.
 *
 * Every timing judgement here comes from the server's derived fields
 * (`timing_state`, `is_expired`, `is_live`, `ends_at`), not from comparing dates
 * in the browser. The clock that matters is the tenant's, and only the backend
 * knows which one that is.
 */

const TONE = {
  upcoming: { fg: '#0369a1', bg: 'rgba(14,165,233,0.10)', bd: 'rgba(14,165,233,0.30)' },
  live:     { fg: '#15803d', bg: 'rgba(34,197,94,0.12)',  bd: 'rgba(34,197,94,0.35)' },
  expired:  { fg: '#b91c1c', bg: 'rgba(220,38,38,0.08)',  bd: 'rgba(220,38,38,0.28)' },
}

const fmt = (v) => (v ? new Date(v).toLocaleString([], {
  day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit',
}) : '—')

const endTime = (v) => (v ? new Date(v).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : null)

const startsIn = (mins) => {
  const n = Number(mins)
  if (!Number.isFinite(n) || n <= 0) return null
  if (n < 60) return `in ${n} min`
  if (n < 1440) return `in ${Math.floor(n / 60)}h`
  return `in ${Math.floor(n / 1440)} day${Math.floor(n / 1440) === 1 ? '' : 's'}`
}

export default function MeetingScheduleCard({ load, to, limit = 3 }) {
  const [rows, setRows] = useState(null)

  useEffect(() => {
    let live = true
    load()
      .then(r => { if (live) setRows(Array.isArray(r?.data) ? r.data : (Array.isArray(r) ? r : [])) })
      .catch(() => { if (live) setRows([]) })
    return () => { live = false }
  }, [])

  // Nothing to say beats an empty box on a dashboard: the card is only drawn
  // once there is a meeting to show.
  if (!rows || rows.length === 0) return null

  // Expired first — it is the one that needs an answer — then whatever is
  // happening now, then what is coming. Closed meetings are history and are
  // left to the Meetings tab.
  const rank = { expired: 0, live: 1, upcoming: 2 }
  const shown = rows
    .filter(m => rank[m.timing_state] !== undefined)
    .sort((a, b) => (rank[a.timing_state] - rank[b.timing_state])
      || (new Date(a.scheduled_at) - new Date(b.scheduled_at)))
    .slice(0, limit)

  if (!shown.length) return null

  const expiredCount = rows.filter(m => m.is_expired).length

  return (
    <div style={{ borderRadius: 14, border: '1px solid var(--border, #e2e8f0)', background: 'var(--bg-card, #fff)', padding: 16 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
        <CalendarDays size={16} style={{ color: '#0891b2' }} />
        <span style={{ fontWeight: 800, fontSize: 13.5, color: 'var(--text-h)' }}>Your meetings</span>
        {expiredCount > 0 && (
          <span style={{ fontSize: 10.5, fontWeight: 800, padding: '2px 8px', borderRadius: 20, color: TONE.expired.fg, background: TONE.expired.bg, border: `1px solid ${TONE.expired.bd}` }}>
            {expiredCount} expired
          </span>
        )}
        <span style={{ flex: 1 }} />
        {to && (
          <Link to={to} style={{ fontSize: 11.5, fontWeight: 700, color: '#0891b2', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: 2 }}>
            All meetings <ChevronRight size={13} />
          </Link>
        )}
      </div>

      <div style={{ display: 'grid', gap: 8 }}>
        {shown.map(m => {
          const tone = TONE[m.timing_state] || TONE.upcoming
          const end = endTime(m.ends_at)
          return (
            <div key={m.id} style={{ padding: '10px 12px', borderRadius: 10, background: tone.bg, border: `1px solid ${tone.bd}` }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                <span style={{ fontWeight: 800, fontSize: 12.5, color: 'var(--text-h)' }}>{m.title || m.reference}</span>
                <span style={{ fontSize: 10, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.04em', color: tone.fg }}>
                  {m.timing_label || m.timing_state}
                </span>
                <span style={{ flex: 1 }} />
                {m.timing_state === 'upcoming' && startsIn(m.minutes_until_start) && (
                  <span style={{ fontSize: 11, fontWeight: 700, color: tone.fg }}>{startsIn(m.minutes_until_start)}</span>
                )}
              </div>

              <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 3 }}>
                {fmt(m.scheduled_at)}{end ? ` – ${end}` : ''}
                {m.location ? ` · ${m.location}` : ''}
              </div>

              {/* The one thing a vendor looking at an expired meeting needs to
                  know: it is not happening, and nobody is waiting for them. */}
              {m.is_expired && (
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 6, marginTop: 7, fontSize: 11.5, color: tone.fg, fontWeight: 600 }}>
                  <AlertTriangle size={13} style={{ flexShrink: 0, marginTop: 1 }} />
                  <span>This meeting has expired. The organiser has been notified and will reschedule or close it.</span>
                </div>
              )}

              {/* Joinable right up to the end, not only before the start — the
                  link used to disappear the moment the meeting began. */}
              {!m.is_expired && m.meeting_link && m.mode !== 'onsite' && (
                <a href={m.meeting_link} target="_blank" rel="noopener noreferrer"
                  style={{ display: 'inline-flex', alignItems: 'center', gap: 6, marginTop: 8, padding: '6px 12px', borderRadius: 8, fontSize: 11.5, fontWeight: 800, textDecoration: 'none', color: '#fff', background: m.is_live ? 'linear-gradient(145deg,#22c55e,#16a34a)' : 'linear-gradient(145deg,#38bdf8,#0284c7)' }}>
                  <Video size={13} /> {m.is_live ? 'Join now' : 'Join meeting'}
                </a>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}
