import { useState, useEffect, useCallback, useMemo } from 'react'
import { Scale, BarChart3, ChevronDown, ChevronRight, Download, RefreshCw, Table2 } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { exportCsv, stampedName } from '@/lib/exportCsv'
import { fmtMoney, fmtDate } from '../constants'
import { KIT3D_STYLE as PURCHASE_STYLE, labelStyle, inputStyle } from '@/components/ui/kit3d'

/**
 * Purchase → Reports. Four table reports and two chart reports over the
 * Purchase-owned tables. Read-only: nothing here writes.
 *
 * Laid out like the reference module: report links on the left, chart reports
 * beside them, and the filter stack on the right. Which filters are shown
 * depends on the report — the table reports run on a period, the chart reports
 * on a year, and currency is meaningless where no money is being summed.
 *
 * Charts are inline SVG (no external chart lib). The series colour is a single
 * validated purple step per theme — light #7C3AED, dark #9575f7 — both checked
 * against their own surface rather than auto-flipped.
 */

// Matches the reference picker, including its rolling windows.
const PERIODS = [
  ['all_time', 'All Time'],
  ['this_month', 'This Month'],
  ['last_month', 'Last Month'],
  ['this_year', 'This Year'],
  ['last_year', 'Last Year'],
  ['3', 'Last 3 Months'],
  ['6', 'Last 6 Months'],
  ['12', 'Last 12 Months'],
  ['custom', 'Custom Period'],
]

// Series colour: selected per theme, each validated against its own surface.
const CHART_STYLE = `
.pvr-chart { --pvr-bar: #7C3AED; }
:root.dark .pvr-chart { --pvr-bar: #9575f7; }
:root.light .pvr-chart { --pvr-bar: #7C3AED; }
`

// `items` = the products/services multi-select; `currency` = the money filter.
// The reference hides currency on the voucher and order-count reports, because
// neither sums an amount.
const TABLE_REPORTS = [
  { key: 'item-cost',  title: 'Cost of import goods for each item', items: true, currency: true,  fetch: (p) => purchaseApi.reports.itemCost(p) },
  { key: 'po-voucher', title: 'PO voucher report',                                currency: false, fetch: (p) => purchaseApi.reports.poVoucher(p) },
  { key: 'orders',     title: 'Purchase Order Report',                            currency: true,  fetch: (p) => purchaseApi.reports.orders(p) },
  { key: 'invoices',   title: 'Purchase Invoices Report',                         currency: true,  fetch: (p) => purchaseApi.reports.invoices(p) },
]

const CHART_REPORTS = [
  { key: 'stats-count', title: 'Purchase statistics by number of purchase orders', measure: 'count', currency: false, fetch: (p) => purchaseApi.reports.statsByCount(p) },
  { key: 'stats-cost',  title: 'Purchase statistics by cost',                      measure: 'cost',  currency: true,  fetch: (p) => purchaseApi.reports.statsByCost(p) },
]

export default function PurchaseReports() {
  const [period, setPeriod] = useState('this_month')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [currency, setCurrency] = useState('')
  const [year, setYear] = useState(String(new Date().getFullYear()))
  const [items, setItems] = useState([])
  const [active, setActive] = useState('item-cost')
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(false)
  const [opts, setOpts] = useState({ items: [], currencies: [], years: [] })

  const report = useMemo(
    () => [...TABLE_REPORTS, ...CHART_REPORTS].find(r => r.key === active),
    [active],
  )
  const isChart = Boolean(report?.measure)

  // Filter vocabulary, loaded once — every option comes from a real row.
  useEffect(() => {
    purchaseApi.reports.filters()
      .then(o => {
        setOpts(o)
        if (o.years?.length && !o.years.includes(Number(year))) setYear(String(o.years[0]))
      })
      .catch(() => {})
  }, [])   // eslint-disable-line react-hooks/exhaustive-deps

  // A custom range is only sent once both ends are set, matching the reference:
  // picking a "from" enables "to", and nothing reloads until "to" is chosen.
  const customIncomplete = !isChart && period === 'custom' && !(from && to)

  const load = useCallback(() => {
    if (!report || customIncomplete) return
    setLoading(true); setData(null)

    const params = isChart
      ? { year }
      : { period, ...(period === 'custom' ? { from, to } : {}) }

    if (report.currency && currency) params.currency = currency
    if (report.items && items.length) params.items = items

    report.fetch(params)
      .then(setData)
      .catch(() => setData({ rows: [], points: [] }))
      .finally(() => setLoading(false))
  }, [report, isChart, period, from, to, currency, year, items, customIncomplete])

  useEffect(() => { load() }, [load])

  // Switching report clears filters that do not apply to it, so a stale
  // selection can never silently narrow the next report.
  const pick = (key) => {
    setActive(key)
    setItems([])
    const next = [...TABLE_REPORTS, ...CHART_REPORTS].find(r => r.key === key)
    if (!next?.currency) setCurrency('')
  }

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{PURCHASE_STYLE}{CHART_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>PROCUREMENT ANALYTICS</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Reports</h1>
        </div>
        <button onClick={load} style={ghostBtn}><RefreshCw size={14} /> Refresh</button>
      </div>

      {/* Report links (by table · charts) + the filter stack */}
      <div className="pr-glass" style={{ padding: 20, borderRadius: 16, marginBottom: 16 }}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(260px,1fr))', gap: 26 }}>
          <ReportList icon={Scale} heading="Report by table" items={TABLE_REPORTS} active={active} onPick={pick} />
          <ReportList icon={BarChart3} heading="Charts Based Report" items={CHART_REPORTS} active={active} onPick={pick} />

          <div style={{ display: 'grid', gap: 12, alignContent: 'start' }}>
            {/* Charts run on a year; the table reports run on a period. */}
            {isChart ? (
              <div>
                <label style={labelStyle}>Year</label>
                <select value={year} onChange={e => setYear(e.target.value)} style={{ ...inputStyle, cursor: 'pointer' }}>
                  {(opts.years || []).map(y => <option key={y} value={y}>{y}</option>)}
                </select>
              </div>
            ) : (
              <>
                <div>
                  <label style={labelStyle}>Period</label>
                  <select value={period} onChange={e => { setPeriod(e.target.value); setFrom(''); setTo('') }}
                    style={{ ...inputStyle, cursor: 'pointer' }}>
                    {PERIODS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                  </select>
                </div>

                {period === 'custom' && (
                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
                    <div>
                      <label style={labelStyle}>From Date</label>
                      <input type="date" value={from} onChange={e => { setFrom(e.target.value); if (!e.target.value) setTo('') }} style={inputStyle} />
                    </div>
                    <div>
                      <label style={labelStyle}>To Date</label>
                      {/* Disabled until a start date exists — same rule as the reference. */}
                      <input type="date" value={to} min={from || undefined} disabled={!from}
                        onChange={e => setTo(e.target.value)}
                        style={{ ...inputStyle, opacity: from ? 1 : 0.5, cursor: from ? 'auto' : 'not-allowed' }} />
                    </div>
                  </div>
                )}
              </>
            )}

            {/* Currency only where an amount is actually being summed. */}
            {report?.currency && (opts.currencies || []).length > 0 && (
              <div>
                <label style={labelStyle}>Currency</label>
                <select value={currency} onChange={e => setCurrency(e.target.value)} style={{ ...inputStyle, cursor: 'pointer' }}>
                  <option value="">All currencies</option>
                  {opts.currencies.map(c => <option key={c} value={c}>{c}</option>)}
                </select>
              </div>
            )}

            {/* Products / services, for the item report only. */}
            {report?.items && (
              <div>
                <label style={labelStyle}>Products &amp; Services</label>
                <select multiple value={items} size={Math.min(5, Math.max(3, (opts.items || []).length))}
                  onChange={e => setItems([...e.target.selectedOptions].map(o => o.value))}
                  style={{ ...inputStyle, height: 'auto', minHeight: 84 }}>
                  {(opts.items || []).map(i => <option key={i} value={i}>{i}</option>)}
                </select>
                <p style={{ fontSize: 11, color: 'var(--text-muted)', margin: '5px 0 0' }}>
                  {items.length ? `${items.length} selected` : 'All items'}
                </p>
              </div>
            )}

            {!isChart && data?.from && (
              <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: 0 }}>{fmtDate(data.from)} — {fmtDate(data.to)}</p>
            )}
          </div>
        </div>
      </div>

      {/* Active report */}
      <div className="pr-glass" style={{ padding: 20, borderRadius: 16 }}>
        <h2 style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 14px' }}>{report?.title}</h2>
        {customIncomplete
          ? <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>Choose a start and end date to run this report.</p>
          : loading ? <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>Loading…</p>
            : !data ? null
              : report.measure ? <ChartReport data={data} measure={report.measure} title={report.title} />
                : <TableReport kind={active} data={data} />}
      </div>
    </div>
  )
}

function ReportList({ icon: Icon, heading, items, active, onPick }) {
  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
        <Icon size={15} style={{ color: 'var(--text-muted)' }} />
        <h2 style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>{heading}</h2>
      </div>
      <div style={{ display: 'grid' }}>
        {items.map(it => {
          const on = active === it.key
          return (
            <button key={it.key} onClick={() => onPick(it.key)} style={{
              display: 'flex', alignItems: 'center', gap: 7, width: '100%', textAlign: 'left',
              padding: '10px 4px', background: 'none', border: 'none', borderBottom: '1px solid var(--border)',
              color: on ? '#a78bfa' : 'var(--text-muted)', fontWeight: on ? 800 : 600, fontSize: 12.5, cursor: 'pointer',
            }}>
              {on ? <ChevronDown size={13} /> : <ChevronRight size={13} />}
              {it.title}
            </button>
          )
        })}
      </div>
    </div>
  )
}

/* ── Table reports ───────────────────────────────────────────────────────── */

// Columns mirror the reference report tables. `foot` names the totals key the
// footer row prints under that column.
const COLUMNS = {
  'item-cost': [
    { label: 'Product Code', value: r => r.product_code || '—' },
    { label: 'Product Name', value: r => r.product_name, grow: true },
    { label: 'PO Number', value: r => r.po_number },
    { label: 'Qty', value: r => Number(r.qty || 0).toLocaleString('en-IN'), num: true },
    { label: 'Rate', value: r => fmtMoney(r.rate), num: true },
    { label: 'Subtotal', value: r => fmtMoney(r.subtotal), num: true, strong: true, foot: 'total' },
  ],
  'po-voucher': [
    { label: 'Purchase Order', value: r => r.po_number },
    { label: 'Date', value: r => fmtDate(r.order_date) },
    { label: 'Department', value: r => r.department || '—' },
    { label: 'Vendor', value: r => r.vendor_name || '—', grow: true },
    { label: 'Approval Status', value: r => r.status },
    { label: 'Delivery Status', value: r => r.delivery_status },
    { label: 'Payment Status', value: r => r.payment_status },
  ],
  orders: [
    { label: 'Purchase Order', value: r => r.po_number },
    { label: 'Date', value: r => fmtDate(r.order_date) },
    { label: 'Department', value: r => r.department || '—' },
    { label: 'Vendor', value: r => r.vendor_name || '—', grow: true },
    { label: 'Approval Status', value: r => r.status },
    { label: 'PO Value', value: r => fmtMoney(r.po_value), num: true, foot: 'total_po' },
    { label: 'Tax Value', value: r => fmtMoney(r.tax_value), num: true, foot: 'total_tax' },
    { label: 'PO Value (incl. tax)', value: r => fmtMoney(r.total_value), num: true, strong: true, foot: 'total' },
  ],
  invoices: [
    { label: 'Invoice No', value: r => r.invoice_number },
    { label: 'Contract', value: r => r.contract_number || '—' },
    { label: 'Purchase Order', value: r => r.po_number || '—' },
    { label: 'Invoice Date', value: r => fmtDate(r.invoice_date) },
    { label: 'Payment Status', value: r => r.status },
    { label: 'Invoice Amount', value: r => fmtMoney(r.invoice_amount), num: true },
    { label: 'Tax Value', value: r => fmtMoney(r.tax_value), num: true },
    { label: 'Total (incl. tax)', value: r => fmtMoney(r.total), num: true, strong: true, foot: 'total' },
  ],
}

function TableReport({ kind, data }) {
  const cols = COLUMNS[kind] || []
  const rows = data.rows || []

  const summary = {
    'item-cost': [['Total cost', fmtMoney(data.total)]],
    'po-voucher': [['Total ordered', fmtMoney(data.total)]],
    orders: [['Total ordered', fmtMoney(data.total)]],
    invoices: [['Billed', fmtMoney(data.total)], ['Paid', fmtMoney(data.paid)], ['Outstanding', fmtMoney(data.balance)]],
  }[kind] || []

  if (!rows.length) return <p style={{ color: 'var(--text-muted)', fontSize: 13, padding: '18px 0' }}>No entries found for this period.</p>

  return (
    <>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginBottom: 12, flexWrap: 'wrap' }}>
        <div style={{ display: 'flex', gap: 18, flexWrap: 'wrap' }}>
          {summary.map(([k, v]) => (
            <div key={k}>
              <div style={{ fontSize: 10.5, color: 'var(--text-muted)', textTransform: 'uppercase', fontWeight: 800, letterSpacing: '.04em' }}>{k}</div>
              <div style={{ fontSize: 18, fontWeight: 900, color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>{v}</div>
            </div>
          ))}
        </div>
        <button onClick={() => exportCsv(stampedName(kind), rows, cols.map(c => ({ label: c.label, value: c.value })))} style={ghostBtn}>
          <Download size={14} /> Export
        </button>
      </div>

      <div style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 640 }}>
          <thead><tr>{cols.map(c => (
            <th key={c.label} style={{ textAlign: c.num ? 'right' : 'left', padding: '10px 12px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', borderBottom: '1px solid var(--border)' }}>{c.label}</th>
          ))}</tr></thead>
          <tbody>
            {rows.map((r, i) => (
              <tr key={r.id ?? i} className="pr-li-row">
                {cols.map(c => (
                  <td key={c.label} style={{
                    padding: '10px 12px', fontSize: 12.5, whiteSpace: c.grow ? 'normal' : 'nowrap',
                    textAlign: c.num ? 'right' : 'left', fontVariantNumeric: c.num ? 'tabular-nums' : 'normal',
                    color: c.strong ? 'var(--text-h)' : 'var(--text-muted)', fontWeight: c.strong ? 700 : 500,
                  }}>{c.value(r)}</td>
                ))}
              </tr>
            ))}
          </tbody>
          {/* Totals row, as in the reference tables. */}
          {cols.some(c => c.foot) && (
            <tfoot>
              <tr>
                {cols.map((c, i) => (
                  <td key={c.label} style={{
                    padding: '11px 12px', fontSize: 12.5, whiteSpace: 'nowrap',
                    textAlign: c.num ? 'right' : 'left', fontVariantNumeric: c.num ? 'tabular-nums' : 'normal',
                    borderTop: '2px solid var(--border)', color: 'var(--text-h)', fontWeight: 800,
                  }}>
                    {c.foot ? fmtMoney(data[c.foot]) : (i === 0 ? 'Total' : '')}
                  </td>
                ))}
              </tr>
            </tfoot>
          )}
        </table>
      </div>
      <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '10px 0 0' }}>{rows.length} row{rows.length === 1 ? '' : 's'}</p>
    </>
  )
}

/* ── Chart report ────────────────────────────────────────────────────────── */

function ChartReport({ data, measure, title }) {
  const [showTable, setShowTable] = useState(false)
  const points = data.points || []
  const fmt = (v) => measure === 'cost' ? fmtMoney(v) : Number(v).toLocaleString('en-IN')

  if (!points.length) return <p style={{ color: 'var(--text-muted)', fontSize: 13, padding: '18px 0' }}>No purchase orders in this period.</p>

  return (
    <>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginBottom: 6, flexWrap: 'wrap' }}>
        <div>
          <div style={{ fontSize: 10.5, color: 'var(--text-muted)', textTransform: 'uppercase', fontWeight: 800, letterSpacing: '.04em' }}>
            {measure === 'cost' ? 'Total spend' : 'Total orders'}
          </div>
          <div style={{ fontSize: 22, fontWeight: 900, color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>{fmt(data.total)}</div>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button onClick={() => setShowTable(s => !s)} style={ghostBtn}><Table2 size={14} /> {showTable ? 'Hide' : 'Show'} data table</button>
          <button onClick={() => exportCsv(stampedName(measure === 'cost' ? 'purchase-by-cost' : 'purchase-by-count'), points, [
            { label: 'Period', value: p => p.label },
            { label: measure === 'cost' ? 'Cost' : 'Orders', value: p => p.value },
          ])} style={ghostBtn}><Download size={14} /> Export</button>
        </div>
      </div>

      <BarChart points={points} format={fmt} caption={title} />

      {showTable && (
        <table style={{ width: '100%', borderCollapse: 'collapse', marginTop: 14, maxWidth: 420 }}>
          <caption style={{ captionSide: 'top', textAlign: 'left', fontSize: 11.5, color: 'var(--text-muted)', paddingBottom: 6 }}>{title}</caption>
          <thead><tr>
            <th style={{ textAlign: 'left', padding: '8px 12px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', borderBottom: '1px solid var(--border)' }}>Period</th>
            <th style={{ textAlign: 'right', padding: '8px 12px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', borderBottom: '1px solid var(--border)' }}>{measure === 'cost' ? 'Cost' : 'Orders'}</th>
          </tr></thead>
          <tbody>
            {points.map(p => (
              <tr key={p.bucket}>
                <td style={{ padding: '7px 12px', fontSize: 12.5, color: 'var(--text-muted)' }}>{p.label}</td>
                <td style={{ padding: '7px 12px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-h)', fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>{fmt(p.value)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </>
  )
}

/** Round a max up to a readable axis top. */
function niceMax(v) {
  if (v <= 0) return 1
  const mag = 10 ** Math.floor(Math.log10(v))
  return Math.ceil(v / mag) * mag
}

/**
 * Single-series bar chart, inline SVG. Bars are anchored to the baseline with
 * 4px rounded tops and a 2px gap between neighbours; grid and axes stay
 * recessive; every value is reachable on hover and in the data table, so no
 * per-bar labels crowd the plot. One series → the title carries identity, so
 * no legend box is needed.
 */
function BarChart({ points, format, caption }) {
  const [hover, setHover] = useState(null)

  const W = 760, H = 240
  const padL = 64, padR = 14, padT = 16, padB = 34
  const plotW = W - padL - padR
  const plotH = H - padT - padB

  const max = niceMax(Math.max(...points.map(p => p.value), 0))
  const step = plotW / points.length
  const barW = Math.max(4, Math.min(30, step - 2))   // 2px gap between neighbours
  const y = (v) => padT + plotH - (v / max) * plotH
  const ticks = [0, max / 2, max]

  // Thin x labels so they never collide.
  const labelEvery = Math.ceil(points.length / 10)

  return (
    <div className="pvr-chart" style={{ position: 'relative' }}>
      <svg viewBox={`0 0 ${W} ${H}`} width="100%" height={H} role="img" aria-label={caption} style={{ display: 'block', overflow: 'visible' }}>
        {/* Recessive grid + y labels */}
        {ticks.map((t, i) => (
          <g key={i}>
            <line x1={padL} x2={W - padR} y1={y(t)} y2={y(t)} stroke="var(--border)" strokeWidth="1" />
            <text x={padL - 10} y={y(t) + 4} textAnchor="end" fontSize="10.5" fill="var(--text-muted)">{format(t)}</text>
          </g>
        ))}

        {points.map((p, i) => {
          const bx = padL + i * step + (step - barW) / 2
          const by = y(p.value)
          const bh = Math.max(0, padT + plotH - by)
          const r = Math.min(4, barW / 2, bh)
          const on = hover === i
          return (
            <g key={p.bucket}>
              {/* Bar: rounded top, square base on the axis */}
              <path
                d={`M${bx},${by + bh} L${bx},${by + r} Q${bx},${by} ${bx + r},${by} L${bx + barW - r},${by} Q${bx + barW},${by} ${bx + barW},${by + r} L${bx + barW},${by + bh} Z`}
                fill="var(--pvr-bar)"
                opacity={hover === null || on ? 1 : 0.55}
              />
              {/* Hit target — wider than the mark */}
              <rect x={padL + i * step} y={padT} width={step} height={plotH} fill="transparent"
                onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)} />
              {i % labelEvery === 0 && (
                <text x={bx + barW / 2} y={H - padB + 18} textAnchor="middle" fontSize="10.5" fill="var(--text-muted)">{p.label}</text>
              )}
            </g>
          )
        })}

        {/* Baseline */}
        <line x1={padL} x2={W - padR} y1={padT + plotH} y2={padT + plotH} stroke="var(--border)" strokeWidth="1.5" />
      </svg>

      {hover !== null && (
        <div style={{
          position: 'absolute', top: 4, left: `${((padL + hover * step + step / 2) / W) * 100}%`,
          transform: 'translateX(-50%)', pointerEvents: 'none',
          background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 8,
          padding: '6px 10px', fontSize: 12, whiteSpace: 'nowrap', boxShadow: '0 8px 20px -8px rgba(0,0,0,.4)',
        }}>
          <div style={{ color: 'var(--text-muted)', fontSize: 11 }}>{points[hover].label}</div>
          <div style={{ color: 'var(--text-h)', fontWeight: 800, fontVariantNumeric: 'tabular-nums' }}>{format(points[hover].value)}</div>
        </div>
      )}
    </div>
  )
}

const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 7, padding: '8px 13px', borderRadius: 10, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)', cursor: 'pointer', fontSize: 12.5, fontWeight: 700 }
