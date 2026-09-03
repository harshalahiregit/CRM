import { useCallback, useEffect, useState } from 'react'
import { BarChart3, RefreshCw, Download, Building2, Stethoscope, AlertTriangle, Timer } from 'lucide-react'
import LoadError from '@/components/ui/LoadError'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'
import { medicalApi } from '@/services/medicalApi'
import { S, Stat, humanise } from './MedicalBits'

/**
 * The Medical report — one page, both registers (`module` picks which).
 *
 * The measure the page is built around: a **success is a cleared certificate**,
 * not a filed one. Passing, current, and accepted by the quality team. Counting
 * submissions would flatter every number here, so the totals, the vendor table
 * and the trend all use the same definition the gate uses.
 *
 * Charting decisions, deliberately:
 *  - The KPI row is stat tiles, not a chart — a single number is best read as a
 *    single number.
 *  - Health bands and rejection reasons are ONE series each (a count per
 *    category), so they are drawn in a single hue with the category named in
 *    text. Four greens-and-ambers would have been decorative, and two of them
 *    (#10b981 vs #22c55e) are indistinguishable to normal vision anyway.
 *  - The trend carries two series, so it gets a legend AND direct labels, and
 *    its two hues were validated for colour-vision separation in both themes
 *    (light #7C3AED/#10b981 ΔE 39; dark #8b5cf6/#059669 ΔE 32).
 *  - One scale per chart. Examinations and successes are both counts, so they
 *    share an axis honestly; nothing here is dual-axis.
 */

/* Validated two-series pair (see the header note). Dark steps are chosen for the
   dark surface, not flipped from the light ones. */
const SERIES = {
  total:   { light: '#7C3AED', dark: '#8b5cf6', label: 'Examinations' },
  success: { light: '#10b981', dark: '#059669', label: 'Cleared' },
}

const isDark = () =>
  typeof document !== 'undefined' &&
  (document.documentElement.dataset.theme === 'dark' ||
   (!document.documentElement.dataset.theme && window.matchMedia?.('(prefers-color-scheme: dark)').matches))

export default function MedicalReport({ module = 'tpv', accent = '#a78bfa' }) {
  const [report, setReport] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [filters, setFilters] = useState({ from: '', to: '', vendor_id: '' })
  const [dark, setDark] = useState(isDark)

  useEffect(() => {
    const mq = window.matchMedia?.('(prefers-color-scheme: dark)')
    const sync = () => setDark(isDark())
    mq?.addEventListener?.('change', sync)
    return () => mq?.removeEventListener?.('change', sync)
  }, [])

  const tone = (k) => SERIES[k][dark ? 'dark' : 'light']

  const load = useCallback(() => {
    const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v))
    medicalApi.admin.report(module, params)
      .then(d => { setLoadError(null); setReport(d) })
      .catch(e => { setReport(null); setLoadError(e) })
  }, [module, filters])

  useEffect(() => { load() }, [load])

  const t = report?.totals
  const h = report?.health

  return (
    <div style={{ padding: 4 }}>
      <style>{TPV_STYLE}</style>

      <header style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: accent, margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>REPORTS</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0', display: 'flex', alignItems: 'center', gap: 8 }}>
            <BarChart3 size={20} /> Medical report
          </h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>
            Volume, vendor statistics, successes and failures, and how healthy the workforce is.
            A success means <strong>cleared</strong> — passing, current and approved.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <button onClick={() => medicalApi.admin.reportExport(module, filters, 'csv')} style={S.btn}><Download size={14} /> CSV</button>
          <button onClick={() => medicalApi.admin.reportExport(module, filters, 'xlsx')} style={S.btn}><Download size={14} /> Excel</button>
          <button onClick={load} style={S.btn}><RefreshCw size={14} /> Refresh</button>
        </div>
      </header>

      {/* Filters — one row above the charts, as a reader expects. */}
      <div style={{ display: 'flex', gap: 10, marginBottom: 14, flexWrap: 'wrap', alignItems: 'flex-end' }}>
        <div>
          <label style={S.label}>From</label>
          <input type="date" value={filters.from} onChange={e => setFilters(f => ({ ...f, from: e.target.value }))} style={S.select} />
        </div>
        <div>
          <label style={S.label}>To</label>
          <input type="date" value={filters.to} onChange={e => setFilters(f => ({ ...f, to: e.target.value }))} style={S.select} />
        </div>
        {report?.by_vendor?.length > 0 && (
          <div>
            <label style={S.label}>{report.vendor_label}</label>
            <select value={filters.vendor_id} onChange={e => setFilters(f => ({ ...f, vendor_id: e.target.value }))} style={S.select}>
              <option value="">All vendors</option>
              {report.by_vendor.filter(v => v.vendor_id).map(v => (
                <option key={v.vendor_id} value={v.vendor_id}>{v.vendor}</option>
              ))}
            </select>
          </div>
        )}
        {(filters.from || filters.to || filters.vendor_id) && (
          <button onClick={() => setFilters({ from: '', to: '', vendor_id: '' })} style={{ ...S.btn, alignSelf: 'flex-end' }}>Clear</button>
        )}
      </div>

      {loadError && <LoadError error={loadError} onRetry={load} />}
      {!report && !loadError && <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>Loading…</p>}

      {report && (
        <>
          {/* ── The headline numbers ─────────────────────────────────── */}
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 14 }}>
            <Stat label="Examinations" value={t.examinations} tone={tone('total')} />
            <Stat label="Workers" value={t.workers} tone="#6366f1" />
            <Stat label="Vendors" value={t.vendors} tone="#6366f1" />
            <Stat label="Successes" value={t.successes} tone={tone('success')} />
            <Stat label="Failures" value={t.failures} tone="#ef4444" />
            <Stat label="Awaiting review" value={t.pending_review} tone="#6366f1" />
            <Stat label="Expired" value={t.expired} tone="#ef4444" />
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: 14, marginBottom: 14 }}>
            {/* Hero numbers — a rate and an average read best as themselves. */}
            <Card title="Success rate" hint="Approved, of the certificates a reviewer has ruled on">
              <div style={{ display: 'flex', alignItems: 'baseline', gap: 10 }}>
                <span style={{ fontSize: 40, fontWeight: 900, color: tone('success'), lineHeight: 1 }}>
                  {t.success_rate === null ? '—' : `${t.success_rate}%`}
                </span>
                <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>
                  {t.approved} approved · {t.rejected} rejected · {t.decided} decided
                </span>
              </div>
              <Split rows={[
                ['Fit', t.fit], ['Fit with restrictions', t.fit_restricted], ['Unfit', t.unfit],
              ]} />
            </Card>

            <Card title="Average health score" hint={`Out of ${t.score_scale}, across every scored examination`}>
              <div style={{ display: 'flex', alignItems: 'baseline', gap: 10 }}>
                <span style={{ fontSize: 40, fontWeight: 900, color: 'var(--text-h)', lineHeight: 1 }}>
                  {h.average ?? '—'}
                </span>
                <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>
                  best {h.best ?? '—'} · worst {h.worst ?? '—'} · {h.scored} scored
                </span>
              </div>
              <Split rows={[
                ['Internal', t.internal], ['External', t.external], ['Re-examinations', t.re_examinations],
              ]} />
            </Card>

            <Card title="Review turnaround" hint="How long the quality team takes, and how much argument a certificate costs">
              <div style={{ display: 'flex', alignItems: 'baseline', gap: 10 }}>
                <Timer size={20} color="var(--text-muted)" />
                <span style={{ fontSize: 32, fontWeight: 900, color: 'var(--text-h)', lineHeight: 1 }}>
                  {report.turnaround.avg_hours ?? '—'}
                </span>
                <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>hours on average</span>
              </div>
              <Split rows={[
                ['Longest', report.turnaround.longest_hours ? `${report.turnaround.longest_hours} h` : '—'],
                ['Needed a reply', report.turnaround.needed_exchanges],
                ['Avg exchanges', report.turnaround.avg_exchanges ?? 0],
              ]} />
            </Card>
          </div>

          {/* ── Health ratings ───────────────────────────────────────── */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 14, marginBottom: 14 }}>
            <Card title="Health ratings" hint="Where the scored examinations fall. One series, so one hue — the band is named, not colour-coded.">
              {h.scored === 0 ? (
                <Empty>No examination has been scored yet.</Empty>
              ) : (
                <BarList
                  rows={h.bands.map(b => ({ label: b.band, value: b.count, meta: `${b.percent}%` }))}
                  max={Math.max(...h.bands.map(b => b.count), 1)}
                  tone={tone('total')}
                />
              )}
            </Card>

            <Card title="Why certificates were refused" hint="Grouped, because the pattern is the useful part — forty 'illegible document' holds is a scanning problem, not forty unfit workers.">
              {report.rejections.length === 0 ? (
                <Empty>Nothing has been rejected or held.</Empty>
              ) : (
                <BarList
                  rows={report.rejections.slice(0, 8).map(r => ({
                    label: r.label,
                    value: r.count,
                    meta: `${r.rejected} rejected · ${r.held} held`,
                  }))}
                  max={Math.max(...report.rejections.map(r => r.count), 1)}
                  tone="#ef4444"
                />
              )}
            </Card>
          </div>

          {/* ── Trend ────────────────────────────────────────────────── */}
          <Card
            title="Examinations over time"
            hint="Both series are counts on one scale."
            action={
              <div style={{ display: 'flex', gap: 12 }}>
                <LegendKey tone={tone('total')} label={SERIES.total.label} />
                <LegendKey tone={tone('success')} label={SERIES.success.label} />
              </div>
            }
          >
            {report.by_month.length === 0 ? (
              <Empty>No examinations in this range.</Empty>
            ) : (
              <TrendBars months={report.by_month} totalTone={tone('total')} successTone={tone('success')} />
            )}
          </Card>

          {/* ── Vendor statistics ────────────────────────────────────── */}
          <Card title={`${report.vendor_label} statistics`} icon={Building2} style={{ marginTop: 14 }}>
            {report.by_vendor.length === 0 ? (
              <Empty>No examinations in this range.</Empty>
            ) : (
              <Table
                head={['Vendor', 'Exams', 'Workers', 'Approved', 'Awaiting', 'Hold', 'Rejected', 'Unfit', 'Expired', 'Success', 'Avg score']}
                rows={report.by_vendor.map(v => [
                  v.vendor, v.examinations, v.workers, v.approved, v.pending_review, v.on_hold,
                  v.rejected, v.unfit, v.expired,
                  v.success_rate === null ? '—' : `${v.success_rate}%`,
                  v.avg_score ?? '—',
                ])}
              />
            )}
          </Card>

          {/* ── Doctors ──────────────────────────────────────────────── */}
          <Card title="By examining doctor" icon={Stethoscope} style={{ marginTop: 14 }}>
            {report.by_doctor.length === 0 ? (
              <Empty>No examiner has been named on these records.</Empty>
            ) : (
              <Table
                head={['Doctor', 'Licence', 'Examinations', 'Approved', 'Rejected', 'On hold', 'Avg score']}
                rows={report.by_doctor.map(d => [
                  d.doctor, d.licence || '—', d.examinations, d.approved, d.rejected, d.on_hold, d.avg_score ?? '—',
                ])}
              />
            )}
          </Card>
        </>
      )}
    </div>
  )
}

/* ── Marks ───────────────────────────────────────────────────────────────── */

/**
 * A ranked bar list. Every bar carries its label and value as text, so the chart
 * is readable without relying on colour at all — which is also what lets it use
 * one hue honestly.
 */
function BarList({ rows, max, tone }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
      {rows.map((r, i) => (
        <div key={i}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 3, gap: 10 }}>
            <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{r.label}</span>
            <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
              {r.meta ? `${r.meta} · ` : ''}<strong style={{ color: 'var(--text-h)' }}>{r.value}</strong>
            </span>
          </div>
          {/* Track + fill. The fill's far end is rounded and anchored to the
              baseline; a zero-value row still shows its track, which is how the
              reader tells "none" from "missing". */}
          <div style={{ height: 8, borderRadius: 999, background: 'var(--bg-input, rgba(127,127,127,0.14))', overflow: 'hidden' }}>
            <div style={{
              width: `${max ? Math.max((r.value / max) * 100, r.value > 0 ? 2 : 0) : 0}%`,
              height: '100%', background: tone, borderRadius: 999,
            }} />
          </div>
        </div>
      ))}
    </div>
  )
}

/** Two counts per month, side by side on one scale, with a 2px gap between them. */
function TrendBars({ months, totalTone, successTone }) {
  const max = Math.max(...months.map(m => m.examinations), 1)

  return (
    <div style={{ overflowX: 'auto' }}>
      <div style={{ display: 'flex', gap: 14, alignItems: 'flex-end', minHeight: 150, paddingTop: 8 }}>
        {months.map(m => (
          <div key={m.month} style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 6, minWidth: 54 }}>
            <div style={{ display: 'flex', alignItems: 'flex-end', gap: 2, height: 120 }} title={`${m.label}: ${m.examinations} examinations, ${m.successes} cleared`}>
              <Bar height={(m.examinations / max) * 120} tone={totalTone} value={m.examinations} />
              <Bar height={(m.successes / max) * 120} tone={successTone} value={m.successes} />
            </div>
            <span style={{ fontSize: 10.5, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{m.label}</span>
          </div>
        ))}
      </div>
    </div>
  )
}

function Bar({ height, tone, value }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'flex-end', height: '100%' }}>
      {/* Direct label on the mark — a legend alone would make identity colour-only. */}
      <span style={{ fontSize: 10, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 2 }}>{value}</span>
      <div style={{
        width: 16, height: Math.max(height, value > 0 ? 3 : 1),
        background: tone, borderTopLeftRadius: 4, borderTopRightRadius: 4,
      }} />
    </div>
  )
}

function LegendKey({ tone, label }) {
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 11.5, color: 'var(--text-muted)' }}>
      <span style={{ width: 9, height: 9, borderRadius: 3, background: tone }} /> {label}
    </span>
  )
}

/* ── Chrome ──────────────────────────────────────────────────────────────── */

function Card({ title, hint, icon: Icon, action, style, children }) {
  return (
    <section className="pr-glass" style={{ padding: 16, borderRadius: 14, ...style }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12, marginBottom: 12 }}>
        <div>
          <h2 style={{ margin: 0, fontSize: 13, fontWeight: 800, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 6 }}>
            {Icon && <Icon size={14} />} {title}
          </h2>
          {hint && <p style={{ margin: '3px 0 0', fontSize: 11.5, color: 'var(--text-muted)', maxWidth: 560 }}>{hint}</p>}
        </div>
        {action}
      </div>
      {children}
    </section>
  )
}

function Split({ rows }) {
  return (
    <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap', marginTop: 12, paddingTop: 10, borderTop: '1px solid var(--border)' }}>
      {rows.map(([label, value]) => (
        <div key={label}>
          <div style={S.label}>{label}</div>
          <div style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>{value ?? 0}</div>
        </div>
      ))}
    </div>
  )
}

function Table({ head, rows }) {
  return (
    <div style={{ overflowX: 'auto' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
        <thead>
          <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
            {head.map((h, i) => <th key={i} style={{ padding: '8px 10px', whiteSpace: 'nowrap' }}>{h}</th>)}
          </tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i} style={{ borderTop: '1px solid var(--border)' }}>
              {r.map((c, j) => (
                <td key={j} style={{
                  padding: '8px 10px',
                  fontWeight: j === 0 ? 700 : 500,
                  color: j === 0 ? 'var(--text-h)' : 'var(--text-muted)',
                  whiteSpace: 'nowrap',
                }}>{typeof c === 'string' ? humanise(c) : c}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function Empty({ children }) {
  return <p style={{ margin: 0, fontSize: 12.5, color: 'var(--text-muted)' }}>{children}</p>
}
