/**
 * SangoeTrack's monthly reports.
 *
 * This screen is a renderer, not a report. SangoeTrack sends whole tables —
 * column labels, alignment, rows and totals — already formatted for display,
 * rupee symbols and all. So it draws what arrives and nothing more.
 *
 * That has one good consequence and one bad one, and both are worth knowing:
 *
 *   good — a new report added on their side appears here with no change at all.
 *   bad  — the numbers are strings, so nothing can be sorted, filtered, charted
 *          or totalled differently. The values are baked.
 *
 * A real data endpoint is on the build list. Until it exists this is honest
 * about being their view rather than pretending to be ours.
 */

import { useState, useEffect, useCallback } from 'react'
import { ChevronLeft, ChevronRight, BarChart3 } from 'lucide-react'
import { sangoeTrackApi } from '@/services/sangoeTrackApi'
import LoadError from '@/components/ui/LoadError'
import EmptyState from '@/components/ui/EmptyState'
import { TrackHeader } from './TrackShell'

/** 'YYYY-MM' for a month offset from now. */
function monthKey(offset = 0) {
  const d = new Date()
  d.setDate(1)
  d.setMonth(d.getMonth() + offset)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

function ReportTable({ report }) {
  const columns = Array.isArray(report.columns) ? report.columns : []
  const rows    = Array.isArray(report.rows) ? report.rows : []
  const totals  = report.totals && typeof report.totals === 'object' ? report.totals : null

  return (
    <section className="flex flex-col gap-2.5">
      <div>
        <h2 className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{report.title}</h2>
        {report.subtitle && (
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{report.subtitle}</p>
        )}
      </div>

      {rows.length === 0 ? (
        <p className="text-xs rounded-lg px-3 py-2.5" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
          Nothing recorded this month.
        </p>
      ) : (
        <div className="rounded-xl overflow-x-auto" style={{ border: '1px solid var(--border)' }}>
          <table className="w-full text-sm" style={{ minWidth: Math.max(360, columns.length * 130) }}>
            <thead>
              <tr style={{ background: 'var(--bg-input)' }}>
                {columns.map((c, i) => (
                  <th key={i}
                    className="text-[11px] font-bold uppercase tracking-wider px-3 py-2.5"
                    style={{ color: 'var(--text-muted)', textAlign: c?.align === 'right' ? 'right' : 'left', whiteSpace: 'nowrap' }}>
                    {c?.label ?? ''}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((row, r) => (
                <tr key={r} style={{ borderTop: '1px solid var(--border)' }}>
                  {(Array.isArray(row) ? row : [row]).map((cell, c) => (
                    <td key={c} className="px-3 py-2"
                      style={{
                        color: c === 0 ? 'var(--text-h)' : 'var(--text-muted)',
                        fontWeight: c === 0 ? 600 : 400,
                        textAlign: columns[c]?.align === 'right' ? 'right' : 'left',
                        fontVariantNumeric: 'tabular-nums',
                        whiteSpace: columns[c]?.align === 'right' ? 'nowrap' : undefined,
                      }}>
                      {cell}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {totals && Object.keys(totals).length > 0 && (
        <div className="flex flex-wrap gap-x-5 gap-y-1 px-1">
          {Object.entries(totals).map(([label, value]) => (
            <div key={label}>
              <span className="text-[10px] uppercase tracking-wider font-bold" style={{ color: 'var(--text-muted)' }}>
                {label}{' '}
              </span>
              <span className="text-sm font-bold" style={{ color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>
                {value}
              </span>
            </div>
          ))}
        </div>
      )}
    </section>
  )
}

export default function TrackReports() {
  const [offset, setOffset]   = useState(0)
  const [data, setData]       = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError]     = useState(null)

  const month = monthKey(offset)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await sangoeTrackApi.reports.get(month)
      setData(res?.data ?? null)
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [month])

  useEffect(() => { load() }, [load])

  const reports = Array.isArray(data?.reports) ? data.reports : []

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <TrackHeader
        title="Reports"
        subtitle={data?.employee_count != null
          ? `${data.employee_count} employees · figures come formatted by SangoeTrack`
          : 'Monthly figures from SangoeTrack.'}
        onRefresh={load}
        loading={loading}
      />

      {/* ── month stepper ────────────────────────────────────────── */}
      <div className="flex items-center gap-2">
        <button onClick={() => setOffset(o => o - 1)} title="Previous month"
          className="rounded-lg flex items-center justify-center"
          style={{ width: 32, height: 32, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
          <ChevronLeft size={15} />
        </button>

        <span className="text-sm font-bold px-2" style={{ color: 'var(--text-h)', minWidth: 140, textAlign: 'center' }}>
          {data?.month_label ?? month}
        </span>

        <button onClick={() => setOffset(o => Math.min(0, o + 1))}
          disabled={offset >= 0}
          title={offset >= 0 ? 'That month has not happened yet' : 'Next month'}
          className="rounded-lg flex items-center justify-center disabled:opacity-35"
          style={{ width: 32, height: 32, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
          <ChevronRight size={15} />
        </button>

        {offset !== 0 && (
          <button onClick={() => setOffset(0)}
            className="rounded-lg text-xs font-semibold"
            style={{ padding: '6px 12px', background: 'transparent', border: '1px solid var(--border)', color: '#a78bfa' }}>
            This month
          </button>
        )}
      </div>

      {error ? (
        <LoadError error={error} onRetry={load} title="Could not load reports" />
      ) : loading ? (
        <p className="text-sm py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</p>
      ) : reports.length === 0 ? (
        <EmptyState icon={BarChart3}
          title="Nothing to report for this month"
          description="No attendance, leave, claims or advances were recorded." />
      ) : (
        <div className="flex flex-col gap-7">
          {reports.map(r => <ReportTable key={r.key ?? r.title} report={r} />)}
        </div>
      )}
    </div>
  )
}
