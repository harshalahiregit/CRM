/**
 * The company holiday calendar from SangoeTrack.
 *
 * Read-only for now, and it says so. SangoeTrack exposes the list over its API
 * but nothing to add, edit or remove one — that lives only in their web admin.
 * Rather than showing an Add button that cannot work, the screen names where the
 * editing happens.
 *
 * Their payload is shaped for a calendar widget — `title`, `start`, `end` — so
 * the occasion arrives as `title`. Dates are plain YYYY-MM-DD strings.
 */

import { useState, useEffect, useCallback, useMemo } from 'react'
import { PartyPopper, ExternalLink } from 'lucide-react'
import { sangoeTrackApi } from '@/services/sangoeTrackApi'
import LoadError from '@/components/ui/LoadError'
import EmptyState from '@/components/ui/EmptyState'
import { TrackHeader } from './TrackShell'

const DAY = 86400000

function parse(d) {
  if (!d) return null
  const t = Date.parse(String(d).slice(0, 10))
  return Number.isNaN(t) ? null : new Date(t)
}

const fmt = d =>
  d ? d.toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' }) : '—'

/** Inclusive day count; a single-day holiday is 1, not 0. */
function lengthInDays(from, to) {
  if (!from) return 1
  if (!to) return 1
  return Math.max(1, Math.round((to - from) / DAY) + 1)
}

export default function TrackHolidays() {
  const [rows, setRows]       = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError]     = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await sangoeTrackApi.holidays.list()
      setRows(Array.isArray(res?.data) ? res.data : [])
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  // Soonest first, and anything already past sinks below the upcoming ones —
  // "when is the next holiday" is the question this screen exists to answer.
  const { upcoming, past } = useMemo(() => {
    const todayStart = new Date()
    todayStart.setHours(0, 0, 0, 0)

    const decorated = rows.map(r => {
      const from = parse(r.start)
      const to   = parse(r.end) ?? from
      return { title: r.title, from, to, days: lengthInDays(from, to) }
    })

    const isPast = h => h.to && h.to < todayStart
    const bySoonest = (a, b) => (a.from?.getTime() ?? 0) - (b.from?.getTime() ?? 0)

    return {
      upcoming: decorated.filter(h => !isPast(h)).sort(bySoonest),
      past:     decorated.filter(isPast).sort((a, b) => bySoonest(b, a)),
    }
  }, [rows])

  const List = ({ items, dim }) => (
    <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
      {items.map((h, i) => (
        <div key={`${h.title}-${i}`}
          className="flex flex-wrap items-baseline justify-between gap-2 px-3.5 py-3"
          style={{ borderTop: i === 0 ? 'none' : '1px solid var(--border)', opacity: dim ? 0.6 : 1 }}>
          <span className="font-semibold text-sm" style={{ color: 'var(--text-h)' }}>{h.title}</span>
          <span className="text-xs" style={{ color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
            {fmt(h.from)}
            {h.days > 1 && ` – ${fmt(h.to)} · ${h.days} days`}
          </span>
        </div>
      ))}
    </div>
  )

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <TrackHeader
        title="Holidays"
        subtitle={upcoming.length
          ? `Next up: ${upcoming[0].title}, ${fmt(upcoming[0].from)}.`
          : 'The company holiday calendar on SangoeTrack.'}
        onRefresh={load}
        loading={loading}
      />

      {error ? (
        <LoadError error={error} onRetry={load} title="Could not load holidays" />
      ) : loading ? (
        <p className="text-sm py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</p>
      ) : rows.length === 0 ? (
        <EmptyState icon={PartyPopper}
          title="No holidays on the calendar"
          description="Holidays are added in SangoeTrack's web admin." />
      ) : (
        <div className="flex flex-col gap-5">
          {upcoming.length > 0 && (
            <section className="flex flex-col gap-2">
              <h2 className="text-[11px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                Coming up
              </h2>
              <List items={upcoming} />
            </section>
          )}

          {past.length > 0 && (
            <section className="flex flex-col gap-2">
              <h2 className="text-[11px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                Already passed
              </h2>
              <List items={past} dim />
            </section>
          )}
        </div>
      )}

      {/* Stated rather than implied by a missing button. */}
      <p className="text-[11px] flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}>
        <ExternalLink size={12} />
        Holidays are added and edited in SangoeTrack's web admin — it has no API for changing
        them yet.
      </p>
    </div>
  )
}
