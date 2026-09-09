import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  BarChart3, RefreshCw, Users, Stethoscope, AlertTriangle, ListChecks, ChevronRight,
} from 'lucide-react'
import LoadError from '@/components/ui/LoadError'
import { medicalApi } from '@/services/medicalApi'
import { KIT3D_STYLE as GLASS_STYLE } from '@/components/ui/kit3d'
import { S, Stat, FitnessPill, HealthScore, FindingPill, SEVERITY_TONE, humanise } from '@/components/medical/MedicalBits'

/**
 * The general medical report — internal staff, client contacts, site visitors.
 *
 * Kept apart from the vendor report on purpose. That one is organised around
 * vendors, projects and workers; these three audiences have none of those, and
 * a vendor column that is empty three times out of five is worse than no
 * column.
 *
 * What this page is FOR, and what makes it different from a count of
 * certificates: the findings section. "412 examinations, 380 passing" is a
 * number nobody can act on. "Nine people share raised blood pressure, six of
 * them internal staff" is a morning's work for somebody.
 *
 * Charting decisions:
 *  - The KPI row is stat tiles. A single number reads best as a single number.
 *  - Health bands, audiences and findings are one series each — a count per
 *    category — so each is drawn in a single hue with the category named in
 *    text beside it. Four different greens would be decoration, and two of them
 *    would be indistinguishable anyway.
 *  - The month trend carries two series, so it gets a legend AND a direct label
 *    on every mark, and its two hues are the pair already validated for
 *    colour-vision separation in both themes.
 *  - One scale per chart. Examinations and passing are both counts, so they
 *    share an axis honestly. Nothing here is dual-axis.
 *  - Findings carry their own severity colour, which is a STATUS palette and is
 *    never reused as a series hue; each is labelled in words as well.
 */

const SERIES = {
  total:   { light: '#7C3AED', dark: '#8b5cf6', label: 'Examinations' },
  passing: { light: '#10b981', dark: '#059669', label: 'Passing' },
}

const isDark = () =>
  typeof document !== 'undefined' &&
  (document.documentElement.dataset.theme === 'dark' ||
   (!document.documentElement.dataset.theme && window.matchMedia?.('(prefers-color-scheme: dark)').matches))

export default function MedicalGeneralReport() {
  const [report, setReport] = useState(null)
  const [options, setOptions] = useState(null)
  const [error, setError] = useState(null)
  const [filters, setFilters] = useState({ audience: '', from: '', to: '', doctor_id: '' })
  const [dark, setDark] = useState(isDark)

  useEffect(() => {
    const mq = window.matchMedia?.('(prefers-color-scheme: dark)')
    const sync = () => setDark(isDark())
    mq?.addEventListener?.('change', sync)
    return () => mq?.removeEventListener?.('change', sync)
  }, [])

  const tone = (k) => SERIES[k][dark ? 'dark' : 'light']

  const load = useCallback(() => {
    setError(null)
    const clean = Object.fromEntries(Object.entries(filters).filter(([, v]) => v))
    medicalApi.general.report(clean)
      .then(res => { setReport(res?.data ?? null); setOptions(res?.options ?? null) })
      .catch(e => { setReport(null); setError(e) })
  }, [filters])

  useEffect(() => { load() }, [load])

  const set = (k, v) => setFilters(f => ({ ...f, [k]: v }))

  const shared = (report?.findings?.groups ?? []).filter(g => g.shared)
  const maxShared = Math.max(...shared.map(g => g.count), 1)

  return (
    <div>
      {/* .pr-glass lives in this stylesheet, and the admin shell does not
          inject it — without this the cards below have no background at all
          and the page shows through them. */}
      <style>{GLASS_STYLE}</style>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: 12, marginBottom: 16 }}>
        <div>
          <p style={{ margin: 0, fontSize: 11, fontWeight: 900, letterSpacing: '0.1em', color: '#a78bfa' }}>MEDICAL</p>
          <h1 style={{ margin: '2px 0 0', fontSize: 21, fontWeight: 900, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
            <BarChart3 size={19} /> Staff, clients and visitors
          </h1>
          <p style={{ margin: '4px 0 0', color: 'var(--text-muted)', fontSize: 12.5 }}>
            Everything the doctor portal recorded outside the two vendor registers.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <Link to="/app/medical/general" style={{ ...S.btn, textDecoration: 'none', minHeight: 40 }}>
            <ListChecks size={14} /> Register
          </Link>
          <button onClick={load} style={{ ...S.btn, minHeight: 40 }}><RefreshCw size={14} /> Refresh</button>
        </div>
      </header>

      <div className="pr-glass" style={{ padding: 12, borderRadius: 14, marginBottom: 14, display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'flex-end' }}>
        <div>
          <label style={S.label}>Audience</label>
          <select value={filters.audience} onChange={e => set('audience', e.target.value)} style={{ ...S.select, minHeight: 40 }}>
            <option value="">Everyone</option>
            {(options?.audiences ?? []).map(a => <option key={a.key} value={a.key}>{a.label}</option>)}
          </select>
        </div>
        <div>
          <label style={S.label}>Doctor</label>
          <select value={filters.doctor_id} onChange={e => set('doctor_id', e.target.value)} style={{ ...S.select, minHeight: 40 }}>
            <option value="">Any doctor</option>
            {(options?.doctors ?? []).map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
          </select>
        </div>
        <div>
          <label style={S.label}>From</label>
          <input type="date" value={filters.from} onChange={e => set('from', e.target.value)} style={{ ...S.input, minHeight: 40 }} />
        </div>
        <div>
          <label style={S.label}>To</label>
          <input type="date" value={filters.to} onChange={e => set('to', e.target.value)} style={{ ...S.input, minHeight: 40 }} />
        </div>
        {(filters.audience || filters.from || filters.to || filters.doctor_id) && (
          <button onClick={() => setFilters({ audience: '', from: '', to: '', doctor_id: '' })} style={{ ...S.btn, minHeight: 40 }}>
            Clear
          </button>
        )}
      </div>

      {error ? (
        <LoadError error={error} onRetry={load} />
      ) : !report ? (
        <p style={{ fontSize: 13, color: 'var(--text-muted)' }}>Building the report…</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>

          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
            <Stat label="Examinations" value={report.totals.examinations} tone={tone('total')} />
            <Stat label="People" value={report.totals.people} tone="#6366f1" />
            <Stat label="Passing" value={report.totals.passing} tone={tone('passing')} />
            <Stat label="Expired" value={report.totals.expired} tone="#f97316" />
            <Stat label="Awaiting review" value={report.totals.pending} tone="#6366f1" />
            <Stat label="Rejected" value={report.totals.rejected} tone="#ef4444" />
          </div>

          {/* ── What the report exists to say ─────────────────────────── */}
          <Card title="Findings people share" icon={AlertTriangle}
            hint="From each person's most recent examination. A finding held by more than one person is what this page is for.">
            {shared.length === 0 ? (
              <Empty>No finding is shared by more than one person in this range.</Empty>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                {shared.map(group => (
                  <div key={group.key}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 3, gap: 10 }}>
                      <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>
                        {group.label}
                        <span style={{ marginLeft: 7, fontSize: 10, fontWeight: 800, color: SEVERITY_TONE[group.severity] }}>
                          {group.severity.toUpperCase()}
                        </span>
                      </span>
                      <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
                        <strong style={{ color: 'var(--text-h)' }}>{group.count}</strong> people
                      </span>
                    </div>
                    {/* Track and fill: a category with a count of one still
                        shows its track, which is how "few" reads differently
                        from "missing". */}
                    <div style={{ height: 8, borderRadius: 999, background: 'var(--bg-input, rgba(127,127,127,0.14))', overflow: 'hidden' }}>
                      <div style={{
                        width: `${Math.max((group.count / maxShared) * 100, 2)}%`,
                        height: '100%', background: SEVERITY_TONE[group.severity] || '#6b7280', borderRadius: 999,
                      }} />
                    </div>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 7 }}>
                      {group.people.map(p => (
                        <span key={`${group.key}-${p.id}`} style={{
                          fontSize: 11, padding: '3px 8px', borderRadius: 7,
                          background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)',
                        }}>
                          {p.name}{p.detail ? ` · ${p.detail}` : ''}
                        </span>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Card>

          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: 14 }}>
            <Card title="By audience" icon={Users}>
              {report.by_audience.every(a => a.examinations === 0) ? (
                <Empty>Nothing recorded yet.</Empty>
              ) : (
                <BarList
                  tone={tone('total')}
                  max={Math.max(...report.by_audience.map(a => a.examinations), 1)}
                  rows={report.by_audience.map(a => ({
                    label: a.label, value: a.examinations, meta: `${a.people} people · ${a.passing} passing`,
                  }))}
                />
              )}
            </Card>

            <Card title="Health bands" icon={BarChart3}
              hint={report.health.average != null ? `Average score ${report.health.average} of 10, across ${report.health.scored} scored examinations.` : undefined}>
              {report.health.scored === 0 ? (
                <Empty>No examination in this range carries a health score.</Empty>
              ) : (
                <BarList
                  tone={tone('total')}
                  max={Math.max(...report.health.bands.map(b => b.count), 1)}
                  rows={report.health.bands.map(b => ({ label: b.band, value: b.count }))}
                />
              )}
            </Card>
          </div>

          <Card title="Month by month" icon={BarChart3}
            action={
              <div style={{ display: 'flex', gap: 12 }}>
                <LegendKey tone={tone('total')} label="Examinations" />
                <LegendKey tone={tone('passing')} label="Passing" />
              </div>
            }>
            {report.by_month.length === 0 ? (
              <Empty>Nothing to plot in this range.</Empty>
            ) : (
              <TrendBars months={report.by_month} totalTone={tone('total')} passingTone={tone('passing')} />
            )}
          </Card>

          <Card title="By doctor" icon={Stethoscope}>
            {report.by_doctor.length === 0 ? <Empty>No examinations.</Empty> : (
              <Table
                head={['Doctor', 'Examinations', 'Passing', 'Average score']}
                rows={report.by_doctor.map(d => [d.doctor, d.examinations, d.passing, d.average ?? '—'])}
              />
            )}
          </Card>

          {/* ── Everyone, individually ────────────────────────────────── */}
          <Card title="Every person" icon={Users}
            hint="Their most recent examination, and what it found. This is the individual view in list form.">
            {report.people.length === 0 ? <Empty>Nobody has been examined in this range.</Empty> : (
              <div style={{ overflowX: 'auto' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
                  <thead>
                    <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                      {['Person', 'Audience', 'Last examined', 'Outcome', 'Score', 'Findings', ''].map((h, i) => (
                        <th key={i} style={{ padding: '8px 10px', fontWeight: 700, whiteSpace: 'nowrap' }}>{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {report.people.map(p => (
                      <tr key={`${p.subject_type}:${p.subject_id}`} style={{ borderTop: '1px solid var(--border)' }}>
                        <td style={{ padding: '8px 10px', fontWeight: 700, color: 'var(--text-h)' }}>
                          {p.name}
                          {p.context && <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 500 }}>{p.context}</div>}
                        </td>
                        <td style={{ padding: '8px 10px', color: 'var(--text-muted)' }}>{humanise(p.audience)}</td>
                        <td style={{ padding: '8px 10px', color: p.is_expired ? '#ef4444' : 'var(--text-muted)', whiteSpace: 'nowrap' }}>
                          {p.last_exam}{p.is_expired ? ' · expired' : ''}
                        </td>
                        <td style={{ padding: '8px 10px' }}><FitnessPill status={p.fitness_status} /></td>
                        <td style={{ padding: '8px 10px' }}><HealthScore score={p.health_score} band={p.health_band} /></td>
                        <td style={{ padding: '8px 10px' }}>
                          {p.findings.length === 0
                            ? <span style={{ color: '#10b981', fontWeight: 700 }}>none</span>
                            : (
                              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 5, maxWidth: 360 }}>
                                {p.findings.slice(0, 3).map(f => <FindingPill key={f.key} finding={f} showDetail={false} />)}
                                {p.findings.length > 3 && (
                                  <span style={{ fontSize: 11, color: 'var(--text-muted)', alignSelf: 'center' }}>
                                    +{p.findings.length - 3} more
                                  </span>
                                )}
                              </div>
                            )}
                        </td>
                        <td style={{ padding: '8px 10px' }}>
                          <Link to={`/app/medical/general?open=${p.latest_id}`}
                            style={{ ...S.btn, padding: '4px 9px', fontSize: 11, textDecoration: 'none' }}>
                            Open <ChevronRight size={11} />
                          </Link>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>

          <p style={{ margin: 0, fontSize: 11, color: 'var(--text-muted)' }}>
            Generated {report.generated_at}.
          </p>
        </div>
      )}
    </div>
  )
}

/* ── Marks ───────────────────────────────────────────────────────────────── */

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

/** Two counts per month, side by side on ONE scale, with a 2px gap between them. */
function TrendBars({ months, totalTone, passingTone }) {
  const max = Math.max(...months.map(m => m.examinations), 1)

  return (
    <div style={{ overflowX: 'auto' }}>
      <div style={{ display: 'flex', gap: 14, alignItems: 'flex-end', minHeight: 150, paddingTop: 8 }}>
        {months.map(m => (
          <div key={m.month} style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 6, minWidth: 54 }}>
            <div style={{ display: 'flex', alignItems: 'flex-end', gap: 2, height: 120 }}
              title={`${m.label}: ${m.examinations} examinations, ${m.passing} passing`}>
              <Bar height={(m.examinations / max) * 120} tone={totalTone} value={m.examinations} />
              <Bar height={(m.passing / max) * 120} tone={passingTone} value={m.passing} />
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

function Card({ title, hint, icon: Icon, action, children }) {
  return (
    <section className="pr-glass" style={{ padding: 16, borderRadius: 14 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap', marginBottom: hint ? 4 : 12 }}>
        <h2 style={{ margin: 0, fontSize: 13, fontWeight: 900, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 7 }}>
          {Icon && <Icon size={15} style={{ color: '#a78bfa' }} />} {title}
        </h2>
        {action}
      </div>
      {hint && <p style={{ margin: '0 0 12px', fontSize: 11.5, color: 'var(--text-muted)', lineHeight: 1.5 }}>{hint}</p>}
      {children}
    </section>
  )
}

function Table({ head, rows }) {
  return (
    <div style={{ overflowX: 'auto' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
        <thead>
          <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 10.5, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
            {head.map((h, i) => <th key={i} style={{ padding: '8px 10px', fontWeight: 700, whiteSpace: 'nowrap' }}>{h}</th>)}
          </tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i} style={{ borderTop: '1px solid var(--border)' }}>
              {r.map((cell, j) => (
                <td key={j} style={{ padding: '8px 10px', whiteSpace: 'nowrap', color: j === 0 ? 'var(--text-h)' : 'var(--text-muted)', fontWeight: j === 0 ? 700 : 500 }}>
                  {cell}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

const Empty = ({ children }) => (
  <p style={{ margin: 0, fontSize: 12.5, color: 'var(--text-muted)' }}>{children}</p>
)
