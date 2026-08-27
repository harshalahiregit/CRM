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
import { RefreshCw, MapPin, Clock, CalendarDays, ChevronLeft, ChevronRight, Download } from 'lucide-react'
import { exportCsv } from '@/lib/exportCsv'
import { sangoeTrackApi } from '@/services/sangoeTrackApi'
import { isoDate } from './useTrackHistory'
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

/**
 * The table's columns, in one place so the header and the CSV cannot drift.
 *
 * `hint` explains the ones whose meaning is not obvious from a three-letter
 * heading — OT and Break in particular.
 */
const COLUMNS = [
  { label: 'Employee' },
  { label: 'Status' },
  { label: 'In',    right: true },
  { label: 'Out',   right: true },
  { label: 'Late',  right: true, hint: 'Time after the company start time' },
  { label: 'Early', right: true, hint: 'Left before the shift ended' },
  { label: 'OT',    right: true, hint: 'Overtime worked' },
  { label: 'Break', right: true, hint: 'Total time on break' },
  { label: 'Evidence',   hint: 'Clock-in and clock-out selfies, and the map location' },
  { label: 'From',       hint: 'IP address and resolved location of the clock-in' },
]

/**
 * What the CSV carries.
 *
 * Deliberately NOT the same as the on-screen columns: a spreadsheet wants the
 * email and the raw coordinates, and cannot do anything with a selfie. It
 * exports what is on screen after filtering — "export this" means the thing you
 * are looking at, not the whole table.
 */
const CSV_COLUMNS = [
  { key: 'name',   label: 'Employee' },
  { key: 'email',  label: 'Email' },
  { key: 'status', label: 'Status' },
  { key: 'clock_in',  label: 'Clock in' },
  { key: 'clock_out', label: 'Clock out' },
  { key: 'late',      label: 'Late' },
  { key: 'early_leaving', label: 'Early leaving' },
  { key: 'overtime',      label: 'Overtime' },
  { key: 'break_time',    label: 'Break' },
  { key: 'ip_address',    label: 'IP address' },
  { key: 'location',      label: 'Location' },
  { label: 'Latitude',  value: r => r.in_lat ?? '' },
  { label: 'Longitude', value: r => r.in_lng ?? '' },
  // The URLs, so a row in the spreadsheet can still be traced back to its photo.
  { label: 'Clock-in selfie',  value: r => r.in_selfie ?? '' },
  { label: 'Clock-out selfie', value: r => r.out_selfie ?? '' },
]

const FILTERS = [
  { key: 'all',      label: 'All' },
  { key: 'Present',  label: 'Present' },
  { key: 'Late',     label: 'Late' },
  { key: 'Absent',   label: 'Absent' },
  { key: 'On Leave', label: 'On Leave' },
]

/**
 * One row shape from two feeds.
 *
 * `live` is the board (every employee, thin) and `rich` is history (only those
 * with a record, but complete). Either may be absent: on a past day there is no
 * live row, and today an employee who has not clocked in has no history row.
 */
function merge(live, rich) {
  return {
    user_id:   live?.user_id ?? rich?.employee_id,
    name:      live?.name ?? rich?.employee_name,
    email:     live?.email ?? null,
    avatar:    live?.avatar ?? null,
    status:    live?.status ?? rich?.status ?? 'Absent',
    clock_in:  live?.clock_in ?? rich?.clock_in ?? null,
    clock_out: live?.clock_out ?? rich?.clock_out ?? null,
    late:      live?.late_time ?? rich?.late ?? null,
    // History-only, every one of them.
    early_leaving: rich?.early_leaving ?? null,
    overtime:      rich?.overtime ?? null,
    break_time:    rich?.total_break_time ?? null,
    ip_address:    rich?.ip_address ?? null,
    in_selfie:  live?.clock_in_selfie ?? rich?.clock_in_selfie ?? null,
    out_selfie: rich?.clock_out_selfie ?? null,
    in_lat:  live?.clock_in_lat ?? rich?.clock_in_lat ?? null,
    in_lng:  live?.clock_in_lng ?? rich?.clock_in_lng ?? null,
    out_lat: rich?.clock_out_lat ?? null,
    out_lng: rich?.clock_out_lng ?? null,
    location: live?.clock_in_location ?? null,
  }
}

/** Move a YYYY-MM-DD string by n days without touching timezones. */
function shiftDay(iso, n) {
  const d = new Date(iso + 'T00:00:00')
  d.setDate(d.getDate() + n)
  return isoDate(d)
}

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
  const [date, setDate]       = useState(() => isoDate(new Date()))

  const today = isoDate(new Date())
  const isToday = date === today

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const today = isoDate(new Date())

      // History is the only feed that carries early leaving, overtime, break
      // time, the clock-OUT selfie and the IP — the live board returns none of
      // them. So it is fetched for EVERY day, including today.
      const detail = sangoeTrackApi.history.attendance({ from: date, to: date, per_page: 200 })
        .then(r => r?.data?.rows ?? [])
        // Detail is an enrichment, not the point: if it fails, the board should
        // still show who is in rather than the whole screen erroring.
        .catch(() => [])

      if (date === today) {
        // Today ALSO needs the live board, because that one lists every employee
        // — including the ones with no attendance row, who are the absent ones.
        // History alone would silently drop them.
        const [live, rich] = await Promise.all([
          sangoeTrackApi.attendance.today('all').then(r => (Array.isArray(r?.data) ? r.data : [])),
          detail,
        ])
        const byId = new Map(rich.map(r => [r.employee_id, r]))
        setRows(live.map(l => merge(l, byId.get(l.user_id))))
      } else {
        // Any other day is history only. Someone who never clocked in has no row,
        // so they simply are not here — see the note under the counters.
        setRows((await detail).map(r => merge(null, r)))
      }
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [date])

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

  const shown = new Date(date + 'T00:00:00').toLocaleDateString('en-IN', {
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
            {shown}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-1.5">
          <button onClick={() => setDate(shiftDay(date, -1))} title="Previous day"
            className="rounded-lg flex items-center justify-center"
            style={{ width: 30, height: 30, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
            <ChevronLeft size={14} />
          </button>

          <input type="date" value={date} max={today} onChange={e => setDate(e.target.value || today)}
            className="rounded-lg text-xs"
            style={{ padding: '6px 10px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />

          <button onClick={() => setDate(shiftDay(date, 1))} disabled={isToday} title={isToday ? 'That day has not happened yet' : 'Next day'}
            className="rounded-lg flex items-center justify-center disabled:opacity-35"
            style={{ width: 30, height: 30, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
            <ChevronRight size={14} />
          </button>

          {!isToday && (
            <button onClick={() => setDate(today)}
              className="rounded-lg text-xs font-semibold"
              style={{ padding: '6px 12px', background: 'transparent', border: '1px solid var(--border)', color: '#a78bfa' }}>
              Today
            </button>
          )}

          <button
            onClick={() => exportCsv(`attendance-${date}`, visible, CSV_COLUMNS)}
            disabled={loading || visible.length === 0}
            title={visible.length === 0 ? 'Nothing to export' : `Export ${visible.length} rows`}
            className="rounded-lg text-xs font-semibold flex items-center gap-1.5 disabled:opacity-40"
            style={{ padding: '7px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}
          >
            <Download size={13} />
            Export
          </button>

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

      {/* The two feeds differ in a way that would otherwise mislead: today lists
          every employee, a past day lists only recorded attendance. Someone
          absent last Tuesday has no row and simply is not there. */}
      {!isToday && !loading && !error && (
        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
          Showing recorded attendance for this day. Anyone with no record — absent, on leave,
          or not yet employed — does not appear, so these counts are not a headcount.
        </p>
      )}

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
          <table className="w-full text-sm" style={{ minWidth: 1020 }}>
            <thead>
              <tr style={{ background: 'var(--bg-input)' }}>
                {COLUMNS.map(c => (
                  <th key={c.label}
                    className="text-[11px] font-bold uppercase tracking-wider px-3 py-2.5"
                    style={{ color: 'var(--text-muted)', textAlign: c.right ? 'right' : 'left', whiteSpace: 'nowrap' }}
                    title={c.hint}>
                    {c.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {visible.map(r => {
                const style = STATUS_STYLE[r.status] ?? {}
                const late  = time(r.late)
                const hasGps = r.in_lat && r.in_lng
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

                    {/* Early leaving, overtime and break come only from history —
                        the live board returns none of them, which is why both
                        feeds are merged above rather than one being chosen. */}
                    <td className="px-3 py-2.5 text-right" style={{ fontVariantNumeric: 'tabular-nums', color: time(r.early_leaving) ? '#fbbf24' : 'var(--text-muted)' }}>
                      {time(r.early_leaving) ?? '—'}
                    </td>
                    <td className="px-3 py-2.5 text-right" style={{ fontVariantNumeric: 'tabular-nums', color: time(r.overtime) ? '#34d399' : 'var(--text-muted)' }}>
                      {time(r.overtime) ?? '—'}
                    </td>
                    <td className="px-3 py-2.5 text-right" style={{ fontVariantNumeric: 'tabular-nums', color: 'var(--text-muted)' }}>
                      {time(r.break_time) ?? '—'}
                    </td>

                    <td className="px-3 py-2.5">
                      <div className="flex items-center gap-1.5">
                        {/* IN and OUT selfie both. The live board never returns the
                            clock-out photo, so until now nobody could see it. */}
                        {[['in', r.in_selfie], ['out', r.out_selfie]].map(([which, src]) => src && (
                          <button key={which}
                            onClick={() => setSelfie({ src, name: r.name, which })}
                            title={`Clock-${which} selfie — ${r.name}`}>
                            <img src={src} alt={`Clock-${which} selfie for ${r.name}`}
                              width={28} height={28} className="rounded object-cover"
                              style={{ border: `1px solid ${which === 'out' ? '#f8717155' : 'var(--border)'}` }} />
                          </button>
                        ))}
                        {hasGps && (
                          <a
                            href={`https://www.google.com/maps?q=${r.in_lat},${r.in_lng}`}
                            target="_blank" rel="noopener noreferrer"
                            className="text-[11px] flex items-center gap-1 rounded-md px-1.5 py-1"
                            style={{ color: '#a78bfa', background: 'var(--bg-input)', whiteSpace: 'nowrap' }}
                            title={r.location || `${r.in_lat}, ${r.in_lng}`}
                          >
                            <MapPin size={11} />
                            Map
                          </a>
                        )}
                        {!r.in_selfie && !r.out_selfie && !hasGps && (
                          <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>—</span>
                        )}
                      </div>
                    </td>

                    {/* Where they clocked in from. A punch from an unexpected
                        network is the thing this column exists to make visible. */}
                    <td className="px-3 py-2.5">
                      <span className="text-[11px]" style={{ color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>
                        {r.ip_address ?? '—'}
                      </span>
                      {r.location && (
                        <div className="text-[11px] truncate" style={{ color: 'var(--text-muted)', maxWidth: 180 }} title={r.location}>
                          {r.location}
                        </div>
                      )}
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
