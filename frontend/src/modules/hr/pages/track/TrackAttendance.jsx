/**
 * Live attendance from track.sangoe.in.
 *
 * Two things worth knowing about this screen:
 *
 * 1. It is TODAY ONLY. SangoeTrack's endpoint accepts no date parameter, so
 *    there is nothing else to ask it for. That is stated in the header rather
 *    than left for someone to work out from an unchanging table.
 *
 * 2. It shows the clock-in selfie and location. SangoeTrack's API returns both
 *    on every row, and their own admin app fetches them and then never displays
 *    them — so the evidence the whole punch flow exists to collect has, until
 *    now, only been visible on their web tables. On a desktop there is room for
 *    it, so it is here.
 *
 * The counts are derived from the rows rather than fetched separately: the same
 * request already carries every employee's status, and a second call could
 * disagree with the list it sits above.
 */

import { useState, useEffect, useMemo, useCallback } from 'react'
import { RefreshCw, MapPin, Clock, CalendarDays } from 'lucide-react'
import { sangoeTrackApi } from '@/services/sangoeTrackApi'
import LoadError from '@/components/ui/LoadError'
import EmptyState from '@/components/ui/EmptyState'

// Their status strings, and the colour each earns. 'Late' is a kind of present,
// so it stays warm rather than red — someone who came in late is at work.
const STATUS_STYLE = {
  'Present':  { fg: '#34d399', bg: 'rgba(52,211,153,0.12)' },
  'Late':     { fg: '#fbbf24', bg: 'rgba(251,191,36,0.12)' },
  'Absent':   { fg: '#f87171', bg: 'rgba(248,113,113,0.12)' },
  'On Leave': { fg: '#818cf8', bg: 'rgba(129,140,248,0.12)' },
}

const FILTERS = [
  { key: 'all',      label: 'All' },
  { key: 'Present',  label: 'Present' },
  { key: 'Late',     label: 'Late' },
  { key: 'Absent',   label: 'Absent' },
  { key: 'On Leave', label: 'On Leave' },
]

/** '09:15:00' → '09:15'. Null and their '00:00:00' placeholder both mean "no". */
function time(value) {
  if (!value || value === '00:00:00') return null
  return String(value).slice(0, 5)
}

export default function TrackAttendance() {
  const [rows, setRows]       = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError]     = useState(null)
  const [filter, setFilter]   = useState('all')
  const [selfie, setSelfie]   = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      // Always fetch everyone and filter here — their status filter would cost a
      // round trip per chip and make the counts flicker as you switch.
      const res = await sangoeTrackApi.attendance.today('all')
      setRows(Array.isArray(res?.data) ? res.data : [])
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const counts = useMemo(() => {
    const c = { all: rows.length, 'Present': 0, 'Late': 0, 'Absent': 0, 'On Leave': 0 }
    rows.forEach(r => { if (c[r.status] !== undefined) c[r.status] += 1 })
    return c
  }, [rows])

  const visible = useMemo(
    () => (filter === 'all' ? rows : rows.filter(r => r.status === filter)),
    [rows, filter]
  )

  const today = new Date().toLocaleDateString('en-IN', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
  })

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">

      {/* ── header ───────────────────────────────────────────────── */}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold" style={{ color: 'var(--text-h)' }}>Attendance</h1>
          <p className="text-xs mt-1 flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}>
            <CalendarDays size={12} />
            {today}
            <span aria-hidden="true">·</span>
            {/* Said plainly, because the table looking identical tomorrow is
                otherwise indistinguishable from the data being stale. */}
            <span>Today only — SangoeTrack keeps the history</span>
          </p>
        </div>

        <button
          onClick={load}
          disabled={loading}
          className="rounded-lg text-xs font-semibold flex items-center gap-1.5 disabled:opacity-50"
          style={{
            padding: '7px 12px', background: 'var(--bg-input)',
            border: '1px solid var(--border)', color: 'var(--text-h)',
          }}
        >
          <RefreshCw size={13} className={loading ? 'animate-spin' : ''} />
          Refresh
        </button>
      </div>

      {/* ── counters, doubling as filters ────────────────────────── */}
      <div className="flex flex-wrap gap-2">
        {FILTERS.map(f => {
          const on    = filter === f.key
          const style = STATUS_STYLE[f.key]
          return (
            <button
              key={f.key}
              onClick={() => setFilter(f.key)}
              aria-pressed={on}
              className="rounded-xl text-left transition-colors"
              style={{
                padding: '10px 14px', minWidth: 104,
                background: on ? (style?.bg ?? 'rgba(124,58,237,0.12)') : 'var(--bg-card)',
                border: `1px solid ${on ? (style?.fg ?? '#7C3AED') : 'var(--border)'}`,
              }}
            >
              <div className="text-lg font-bold leading-none"
                style={{ color: style?.fg ?? 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>
                {counts[f.key] ?? 0}
              </div>
              <div className="text-[11px] mt-1 font-semibold" style={{ color: 'var(--text-muted)' }}>
                {f.label}
              </div>
            </button>
          )
        })}
      </div>

      {/* ── the board ────────────────────────────────────────────── */}
      {error ? (
        <LoadError error={error} onRetry={load} title="Could not load attendance" />
      ) : loading ? (
        <p className="text-sm py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</p>
      ) : visible.length === 0 ? (
        <EmptyState
          icon={Clock}
          title={filter === 'all' ? 'Nobody on the board yet' : `No one is ${FILTERS.find(f => f.key === filter)?.label.toLowerCase()}`}
          description={filter === 'all'
            ? 'No employees came back from SangoeTrack for today.'
            : 'Try another filter.'}
        />
      ) : (
        <div className="rounded-xl overflow-x-auto" style={{ border: '1px solid var(--border)' }}>
          <table className="w-full text-sm" style={{ minWidth: 720 }}>
            <thead>
              <tr style={{ background: 'var(--bg-input)' }}>
                {['Employee', 'Status', 'In', 'Out', 'Late', 'Clock-in evidence'].map((h, i) => (
                  <th key={h}
                    className="text-[11px] font-bold uppercase tracking-wider px-3 py-2.5"
                    style={{
                      color: 'var(--text-muted)',
                      textAlign: i >= 2 && i <= 4 ? 'right' : 'left',
                      whiteSpace: 'nowrap',
                    }}>
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {visible.map(r => {
                const style = STATUS_STYLE[r.status] ?? {}
                const late  = time(r.late_time)
                const hasGps = r.clock_in_lat && r.clock_in_lng
                return (
                  <tr key={r.user_id} style={{ borderTop: '1px solid var(--border)' }}>
                    <td className="px-3 py-2.5">
                      <div className="flex items-center gap-2.5">
                        {r.avatar
                          ? <img src={r.avatar} alt="" width={28} height={28}
                              className="rounded-full object-cover" style={{ flexShrink: 0 }} />
                          : <span className="rounded-full flex items-center justify-center text-[11px] font-bold"
                              style={{ width: 28, height: 28, flexShrink: 0, background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
                              {String(r.name ?? '?').charAt(0).toUpperCase()}
                            </span>}
                        <div style={{ minWidth: 0 }}>
                          <div className="font-semibold truncate" style={{ color: 'var(--text-h)' }}>{r.name}</div>
                          {r.email && <div className="text-[11px] truncate" style={{ color: 'var(--text-muted)' }}>{r.email}</div>}
                        </div>
                      </div>
                    </td>

                    <td className="px-3 py-2.5">
                      <span className="rounded-md text-[11px] font-bold px-2 py-1"
                        style={{ color: style.fg ?? 'var(--text-muted)', background: style.bg ?? 'var(--bg-input)', whiteSpace: 'nowrap' }}>
                        {r.status}
                      </span>
                    </td>

                    <td className="px-3 py-2.5 text-right" style={{ fontVariantNumeric: 'tabular-nums', color: 'var(--text-h)' }}>
                      {time(r.clock_in) ?? <span style={{ color: 'var(--text-muted)' }}>—</span>}
                    </td>

                    <td className="px-3 py-2.5 text-right" style={{ fontVariantNumeric: 'tabular-nums', color: 'var(--text-h)' }}>
                      {/* An open shift is not a missing one — say which it is. */}
                      {time(r.clock_out)
                        ?? (time(r.clock_in)
                            ? <span style={{ color: '#fbbf24' }}>Still in</span>
                            : <span style={{ color: 'var(--text-muted)' }}>—</span>)}
                    </td>

                    <td className="px-3 py-2.5 text-right" style={{ fontVariantNumeric: 'tabular-nums', color: late ? '#fbbf24' : 'var(--text-muted)' }}>
                      {late ?? '—'}
                    </td>

                    <td className="px-3 py-2.5">
                      <div className="flex items-center gap-2">
                        {r.clock_in_selfie && (
                          <button onClick={() => setSelfie({ src: r.clock_in_selfie, name: r.name })}
                            title={`Clock-in selfie — ${r.name}`}>
                            <img src={r.clock_in_selfie} alt={`Clock-in selfie for ${r.name}`}
                              width={30} height={30}
                              className="rounded object-cover"
                              style={{ border: '1px solid var(--border)' }} />
                          </button>
                        )}
                        {hasGps && (
                          <a
                            href={`https://www.google.com/maps?q=${r.clock_in_lat},${r.clock_in_lng}`}
                            target="_blank" rel="noopener noreferrer"
                            className="text-[11px] flex items-center gap-1 rounded-md px-2 py-1"
                            style={{ color: '#a78bfa', background: 'var(--bg-input)', whiteSpace: 'nowrap' }}
                            title={r.clock_in_location || `${r.clock_in_lat}, ${r.clock_in_lng}`}
                          >
                            <MapPin size={11} />
                            Map
                          </a>
                        )}
                        {!r.clock_in_selfie && !hasGps && (
                          <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>—</span>
                        )}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* ── selfie viewer ────────────────────────────────────────── */}
      {selfie && (
        <div
          role="dialog" aria-modal="true" aria-label={`Clock-in selfie for ${selfie.name}`}
          onClick={() => setSelfie(null)}
          className="fixed inset-0 z-50 flex items-center justify-center p-6"
          style={{ background: 'rgba(0,0,0,0.75)' }}
        >
          <div onClick={e => e.stopPropagation()} className="flex flex-col items-center gap-3">
            <img src={selfie.src} alt={`Clock-in selfie for ${selfie.name}`}
              style={{ maxWidth: '90vw', maxHeight: '75vh', borderRadius: 12 }} />
            <p className="text-sm font-semibold" style={{ color: '#fff' }}>{selfie.name}</p>
            <button onClick={() => setSelfie(null)}
              className="rounded-lg text-xs font-semibold"
              style={{ padding: '7px 14px', background: 'rgba(255,255,255,0.14)', color: '#fff' }}>
              Close
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
