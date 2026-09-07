import { useState, useEffect } from 'react'
import { Link } from 'react-router-dom'
import { CalendarDays, Video, AlertTriangle, CheckCircle2, ChevronRight, Copy } from 'lucide-react'

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
  ended:    { fg: '#475569', bg: 'rgba(100,116,139,0.10)', bd: 'rgba(100,116,139,0.28)' },
  expired:  { fg: '#b91c1c', bg: 'rgba(220,38,38,0.08)',  bd: 'rgba(220,38,38,0.28)' },
}

/** Labels for the locally re-derived state — timing_label is the fetched one. */
const LABEL = { upcoming: 'Upcoming', live: 'In progress', ended: 'Ended', expired: 'Expired' }

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

/** How long a call may go unheard from before it counts as over (server: 3 min). */
const STALE_MS = 3 * 60 * 1000

/** How often the card re-reads the clock, and re-asks the server. */
const TICK_MS  = 30 * 1000
const FETCH_MS = 5 * 60 * 1000

/**
 * The meeting's state right now, rather than at the moment it was fetched.
 *
 * The server computes timing_state when it answers, so a dashboard left open
 * kept showing "Upcoming" through the meeting and long after it ended — the one
 * screen a vendor leaves open all day was the one that never noticed. The
 * server's answer still seeds this and remains the fallback; these timestamps
 * are absolute instants, so comparing them to the browser clock is correct in
 * any timezone.
 */
function stateNow(m, now) {
  if (m.timing_state === 'draft' || m.timing_state === 'closed') return m.timing_state

  // What the call actually did outranks the slot it was booked into, exactly as
  // it does on the server (MeetingTiming). Without this the card would go on
  // re-deriving "In progress" from the booked hour for a meeting everyone left
  // ten minutes in — which is the whole reason that state stopped being
  // believable.
  if (m.actual_end_at) return 'ended'
  if (m.actual_start_at) {
    // A call nobody has been heard from for minutes is over, whether or not
    // anyone hung up — a shut laptop reports no ending at all. Same window the
    // server uses, so the card and the record never disagree.
    const seen = m.presence_seen_at ? new Date(m.presence_seen_at).getTime() : null
    return seen && now - seen > STALE_MS ? 'ended' : 'live'
  }

  if (!m.scheduled_at) return m.timing_state
  const start = new Date(m.scheduled_at).getTime()
  const end   = m.ends_at ? new Date(m.ends_at).getTime() : start + 60 * 60 * 1000
  if (!Number.isFinite(start) || !Number.isFinite(end)) return m.timing_state
  if (now < start) return 'upcoming'
  return now >= end ? 'expired' : 'live'
}

const minutesUntil = (m, now) => {
  if (!m.scheduled_at) return null
  const start = new Date(m.scheduled_at).getTime()
  return Number.isFinite(start) ? Math.round((start - now) / 60000) : null
}

/** The join link in the open, with a one-press copy. */
function CopyLink({ link }) {
  const [copied, setCopied] = useState(false)
  return (
    <button
      onClick={() => navigator.clipboard?.writeText(link)
        .then(() => { setCopied(true); setTimeout(() => setCopied(false), 1800) })
        .catch(() => {/* no clipboard: the link is still readable below */})}
      title={link}
      style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 10px', borderRadius: 8, cursor: 'pointer', fontSize: 11, fontWeight: 700, background: 'var(--bg-input, #fff)', border: '1px solid var(--border, #e2e8f0)', color: copied ? '#15803d' : 'var(--text-muted, #64748b)', maxWidth: 240, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
      <Copy size={12} style={{ flexShrink: 0 }} />
      {copied ? 'Link copied' : link.replace(/^https?:\/\//, '')}
    </button>
  )
}

export default function MeetingScheduleCard({ load, to, limit = 3 }) {
  const [rows, setRows] = useState(null)
  const [now, setNow] = useState(() => Date.now())

  // Re-read the clock so a meeting becomes live, then expired, on its own —
  // and "starts in 20 minutes" counts down instead of freezing.
  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), TICK_MS)
    return () => clearInterval(t)
  }, [])

  useEffect(() => {
    let live = true
    const fetchRows = () => load()
      .then(r => { if (live) setRows(Array.isArray(r?.data) ? r.data : (Array.isArray(r) ? r : [])) })
      .catch(() => { if (live) setRows(prev => prev ?? []) })

    fetchRows()
    const poll = setInterval(fetchRows, FETCH_MS)

    // A dashboard is usually left in a background tab. Coming back to it is
    // exactly when the list is most likely to be out of date, and waiting up
    // to five minutes for the next poll is the wrong answer.
    const onVisible = () => { if (document.visibilityState === 'visible') { setNow(Date.now()); fetchRows() } }
    document.addEventListener('visibilitychange', onVisible)

    return () => { live = false; clearInterval(poll); document.removeEventListener('visibilitychange', onVisible) }
  }, [])

  // Nothing to say beats an empty box on a dashboard: the card is only drawn
  // once there is a meeting to show.
  if (!rows || rows.length === 0) return null

  // Expired first — it is the one that needs an answer — then whatever is
  // happening now, then what is coming. Closed meetings are history and are
  // left to the Meetings tab.
  const rank = { expired: 0, live: 1, upcoming: 2 }
  // Re-derived on every tick, not read off the fetched row.
  const live = rows.map(m => {
    const state = stateNow(m, now)
    // `is_expired` gates the join link, and a meeting that has ENDED cannot be
    // joined either — both finished states have to close it.
    return { ...m, timing_state: state,
             is_expired: state === 'expired' || state === 'ended',
             is_live: state === 'live',
             minutes_until_start: minutesUntil(m, now) }
  })
  const shown = live
    .filter(m => rank[m.timing_state] !== undefined)
    .sort((a, b) => (rank[a.timing_state] - rank[b.timing_state])
      || (new Date(a.scheduled_at) - new Date(b.scheduled_at)))
    .slice(0, limit)

  if (!shown.length) return null

  const expiredCount = live.filter(m => m.is_expired).length

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
                  {LABEL[m.timing_state] || m.timing_state}
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
              {/* Ended and expired both mean "over", and they are different
                  news: one happened, the other was missed. Saying "expired" for
                  a meeting the vendor sat through would be plainly wrong. */}
              {m.timing_state === 'ended' && (
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 6, marginTop: 7, fontSize: 11.5, color: tone.fg, fontWeight: 600 }}>
                  <CheckCircle2 size={13} style={{ flexShrink: 0, marginTop: 1 }} />
                  <span>
                    This meeting has ended
                    {m.held_minutes ? ` — it ran ${m.held_minutes} min` : ''}. The minutes will be shared with you.
                  </span>
                </div>
              )}
              {m.timing_state === 'expired' && (
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 6, marginTop: 7, fontSize: 11.5, color: tone.fg, fontWeight: 600 }}>
                  <AlertTriangle size={13} style={{ flexShrink: 0, marginTop: 1 }} />
                  <span>This meeting has expired. The organiser has been notified and will reschedule or close it.</span>
                </div>
              )}

              {/* Joinable right up to the end, not only before the start — the
                  link used to disappear the moment the meeting began. */}
              {!m.is_expired && m.meeting_link && m.mode !== 'onsite' && (
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 8, flexWrap: 'wrap' }}>
                  <a href={m.meeting_link} target="_blank" rel="noopener noreferrer"
                    style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 12px', borderRadius: 8, fontSize: 11.5, fontWeight: 800, textDecoration: 'none', color: '#fff', background: m.is_live ? 'linear-gradient(145deg,#22c55e,#16a34a)' : 'linear-gradient(145deg,#38bdf8,#0284c7)' }}>
                    <Video size={13} /> {m.is_live ? 'Join now' : 'Join meeting'}
                  </a>
                  {/* The link itself, not only a button over it. A vendor
                      joining from their phone, or passing it to a colleague who
                      was not invited through the portal, needs to be able to
                      read and copy it. */}
                  <CopyLink link={m.meeting_link} />
                </div>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}
