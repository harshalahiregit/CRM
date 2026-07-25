import { useState, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { BarChart3, FileDown, Printer, Filter, X, TrendingUp, IndianRupee, ClipboardList } from 'lucide-react'
import { inventoryApi, INV_ACCENT, fmtQty, money } from '@/services/inventoryApi'
import Select from '@/components/ui/Select'

/**
 * Inventory reports (blueprint §8) — Stock Summary, Inventory Analysis and
 * Inventory Valuation over one shared filter bar.
 *
 * Every figure is computed server-side from the movement ledger, so a report can
 * never disagree with the stock it reports on. "Download PDF" prints through the
 * browser (Save as PDF) rather than pulling in a PDF library — the print
 * stylesheet below strips the chrome so the output is just the report.
 */

const REPORTS = [
  { key: 'summary',   label: 'Stock Summary',       icon: ClipboardList, blurb: 'Opening → in → out → closing for every item.' },
  { key: 'analysis',  label: 'Inventory Analysis',  icon: TrendingUp,    blurb: 'Consumption, turnover and fast/slow/dead items.' },
  { key: 'valuation', label: 'Inventory Valuation', icon: IndianRupee,   blurb: 'What the shelf is worth, at cost and at sale price.' },
]

const CLASS_STYLE = {
  fast:   { label: 'Fast',   color: '#10B981' },
  medium: { label: 'Medium', color: '#3b82f6' },
  slow:   { label: 'Slow',   color: '#f59e0b' },
  dead:   { label: 'Dead',   color: '#ef4444' },
}

const today = () => new Date().toISOString().slice(0, 10)
const monthsAgo = (n) => { const d = new Date(); d.setMonth(d.getMonth() - n); return d.toISOString().slice(0, 10) }

export default function InventoryReports() {
  const [kind, setKind] = useState('summary')
  const [f, setF] = useState({ from: monthsAgo(3), to: today(), warehouse_id: '', product_id: '', actor_id: '' })

  const { data: warehouses = [] } = useQuery({ queryKey: ['inv-warehouses'], queryFn: inventoryApi.warehouses.list })
  const { data: products = [] } = useQuery({ queryKey: ['inv-products', {}], queryFn: () => inventoryApi.products.list() })
  const { data: staff = [] } = useQuery({ queryKey: ['inv-staff'], queryFn: inventoryApi.staff })

  // Valuation is a point-in-time snapshot — the date window doesn't apply to it.
  const params = useMemo(() => {
    const p = {}
    Object.entries(f).forEach(([k, v]) => { if (v !== '' && v != null) p[k] = v })
    if (kind === 'valuation') { delete p.from; delete p.to; delete p.actor_id }
    return p
  }, [f, kind])

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['inv-report', kind, params],
    queryFn: () => inventoryApi.report(kind, params),
  })

  const rows = data?.rows || []
  const totals = data?.totals || {}
  const cfg = REPORTS.find(r => r.key === kind)
  const set = (k, v) => setF(s => ({ ...s, [k]: v }))
  const clearFilters = () => setF({ from: monthsAgo(3), to: today(), warehouse_id: '', product_id: '', actor_id: '' })
  const hasFilter = f.warehouse_id || f.product_id || f.actor_id

  /* CSV of exactly what's on screen — same rows, same order. */
  const exportCsv = () => {
    const cols = COLUMNS[kind]
    const esc = v => `"${String(v ?? '').replace(/"/g, '""')}"`
    const csv = [
      cols.map(c => esc(c.label)).join(','),
      ...rows.map(r => cols.map(c => esc(c.raw ? c.raw(r) : r[c.key])).join(',')),
    ].join('\n')
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
    const a = document.createElement('a')
    a.href = url; a.download = `inventory-${kind}-${today()}.csv`; a.click()
    URL.revokeObjectURL(url)
  }

  const cols = COLUMNS[kind]

  return (
    <div>
      <style>{`
        @media print {
          body * { visibility: hidden; }
          #inv-report, #inv-report * { visibility: visible; }
          #inv-report { position: absolute; left: 0; top: 0; width: 100%; }
          .no-print { display: none !important; }
          #inv-report table { font-size: 10px; }
        }
      `}</style>

      {/* Header */}
      <header className="flex items-center gap-2 mb-4 no-print">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)` }}>
          <BarChart3 size={17} style={{ color: INV_ACCENT }} />
        </span>
        <div>
          <h1 className="text-lg font-bold leading-tight" style={{ color: 'var(--text-h)' }}>Reports</h1>
          <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{cfg.blurb}</p>
        </div>
        <div className="ml-auto flex items-center gap-2">
          <button onClick={exportCsv} disabled={!rows.length}
            className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold disabled:opacity-40"
            style={{ border: '1px solid var(--border)', color: 'var(--text-body)' }}>
            <FileDown size={14} /> CSV
          </button>
          <button onClick={() => window.print()} disabled={!rows.length}
            className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold disabled:opacity-40"
            style={{ background: INV_ACCENT, color: '#fff' }}>
            <Printer size={14} /> Download PDF
          </button>
        </div>
      </header>

      {/* Report type tabs */}
      <div className="flex gap-1 mb-4 no-print overflow-x-auto">
        {REPORTS.map(r => {
          const on = r.key === kind
          const Icon = r.icon
          return (
            <button key={r.key} onClick={() => setKind(r.key)}
              className="flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold whitespace-nowrap transition-colors"
              style={on
                ? { background: `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)`, color: INV_ACCENT, border: `1px solid ${INV_ACCENT}` }
                : { background: 'var(--bg-card)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
              <Icon size={13} /> {r.label}
            </button>
          )
        })}
      </div>

      {/* Filters */}
      <section className="rounded-2xl p-3 mb-4 no-print" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <div className="flex items-center gap-2 mb-2.5">
          <Filter size={13} style={{ color: INV_ACCENT }} />
          <span className="text-[11px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Filters</span>
          {hasFilter && (
            <button onClick={clearFilters} className="ml-auto flex items-center gap-1 text-[11px] font-bold" style={{ color: 'var(--text-muted)' }}>
              <X size={11} /> Clear
            </button>
          )}
        </div>
        <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))' }}>
          {kind !== 'valuation' && (
            <>
              <Fld label="From date">
                <input type="date" value={f.from} onChange={e => set('from', e.target.value)} style={INP} />
              </Fld>
              <Fld label="To date">
                <input type="date" value={f.to} onChange={e => set('to', e.target.value)} style={INP} />
              </Fld>
            </>
          )}
          <Fld label="Warehouse">
            <Select value={f.warehouse_id} onChange={v => set('warehouse_id', v)} placeholder="All warehouses"
              options={[{ value: '', label: 'All warehouses' }, ...warehouses.map(w => ({ value: w.id, label: w.name }))]} />
          </Fld>
          <Fld label="Commodity">
            <Select value={f.product_id} onChange={v => set('product_id', v)} placeholder="All items"
              options={[{ value: '', label: 'All items' }, ...products.map(p => ({ value: p.id, label: `${p.sku} · ${p.name}` }))]} />
          </Fld>
          {kind !== 'valuation' && (
            <Fld label="Staff">
              <Select value={f.actor_id} onChange={v => set('actor_id', v)} placeholder="Anyone"
                options={[{ value: '', label: 'Anyone' }, ...staff.map(s => ({ value: s.id, label: s.name }))]} />
            </Fld>
          )}
        </div>
      </section>

      {/* Report body */}
      <section id="inv-report" className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <div className="mb-3">
          <h2 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{cfg.label}</h2>
          <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
            {describeFilters(data?.filters, kind)}
          </p>
        </div>

        {isError && <p className="text-xs py-8 text-center" style={{ color: 'var(--color-danger-500)' }}>{error?.message}</p>}
        {isLoading && <div className="h-40 rounded-xl animate-pulse" style={{ background: 'var(--bg-input)' }} />}

        {!isLoading && !isError && rows.length === 0 && (
          <p className="text-xs py-10 text-center" style={{ color: 'var(--text-muted)' }}>
            No data for this period. Record some stock movements, or widen the date range.
          </p>
        )}

        {!isLoading && !isError && rows.length > 0 && (
          <>
            {/* Analysis gets a class breakdown strip */}
            {kind === 'analysis' && (
              <div className="flex flex-wrap gap-2 mb-3">
                {['fast', 'medium', 'slow', 'dead'].map(c => (
                  <span key={c} className="text-[11px] font-bold px-2.5 py-1 rounded-lg"
                    style={{ background: `color-mix(in srgb, ${CLASS_STYLE[c].color} 14%, transparent)`, color: CLASS_STYLE[c].color }}>
                    {CLASS_STYLE[c].label}: {totals[c] ?? 0}
                  </span>
                ))}
                <span className="text-[11px] px-2.5 py-1 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
                  over {totals.days} days
                </span>
              </div>
            )}

            <div className="overflow-x-auto">
              <table className="w-full text-xs" style={{ minWidth: 640 }}>
                <thead>
                  <tr style={{ borderBottom: '1px solid var(--border)' }}>
                    {cols.map(c => (
                      <th key={c.key} className={`px-2 py-2 font-bold ${c.num ? 'text-right' : 'text-left'}`}
                        style={{ color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{c.label}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {rows.map((r, i) => (
                    <tr key={r.product_id ?? i} style={{ borderBottom: '1px solid var(--border)' }}>
                      {cols.map(c => (
                        <td key={c.key} className={`px-2 py-2 ${c.num ? 'text-right tabular-nums' : ''}`}
                          style={{ color: c.strong ? 'var(--text-h)' : 'var(--text-body)', fontWeight: c.strong ? 700 : 400, whiteSpace: c.wrap ? 'normal' : 'nowrap' }}>
                          {c.render ? c.render(r) : r[c.key]}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr style={{ borderTop: `2px solid ${INV_ACCENT}` }}>
                    {cols.map((c, i) => (
                      <td key={c.key} className={`px-2 py-2.5 font-black ${c.num ? 'text-right tabular-nums' : ''}`} style={{ color: 'var(--text-h)' }}>
                        {i === 0 ? `Total · ${rows.length} item${rows.length === 1 ? '' : 's'}` : (c.total ? c.total(totals) : '')}
                      </td>
                    ))}
                  </tr>
                </tfoot>
              </table>
            </div>
          </>
        )}
      </section>
    </div>
  )
}

/* ── Column definitions per report ──────────────────────────────── */

const COLUMNS = {
  summary: [
    { key: 'sku',     label: 'Code' },
    { key: 'name',    label: 'Commodity', wrap: true, strong: true },
    { key: 'unit',    label: 'Unit' },
    { key: 'opening', label: 'Opening', num: true, render: r => fmtQty(r.opening), total: t => fmtQty(t.opening) },
    { key: 'qty_in',  label: 'In',      num: true, render: r => <span style={{ color: '#10B981' }}>+{fmtQty(r.qty_in)}</span>, total: t => fmtQty(t.qty_in) },
    { key: 'qty_out', label: 'Out',     num: true, render: r => <span style={{ color: '#ef4444' }}>−{fmtQty(r.qty_out)}</span>, total: t => fmtQty(t.qty_out) },
    { key: 'closing', label: 'Closing', num: true, strong: true, render: r => fmtQty(r.closing), total: t => fmtQty(t.closing) },
    { key: 'value',   label: 'Value',   num: true, render: r => money(r.value), total: t => money(t.value) },
  ],
  analysis: [
    { key: 'sku',           label: 'Code' },
    { key: 'name',          label: 'Commodity', wrap: true, strong: true },
    { key: 'qty_out',       label: 'Consumed',  num: true, render: r => fmtQty(r.qty_out), total: t => '' },
    { key: 'avg_stock',     label: 'Avg stock', num: true, render: r => fmtQty(r.avg_stock) },
    { key: 'closing',       label: 'On hand',   num: true, render: r => fmtQty(r.closing) },
    { key: 'turnover',      label: 'Turnover',  num: true, render: r => r.turnover == null ? '—' : `${r.turnover}×` },
    { key: 'days_of_stock', label: 'Days left', num: true, render: r => r.days_of_stock == null ? '—' : r.days_of_stock },
    {
      key: 'class', label: 'Class',
      render: r => {
        const s = CLASS_STYLE[r.class] || CLASS_STYLE.dead
        return <span className="text-[10px] font-bold px-2 py-0.5 rounded-full"
          style={{ background: `color-mix(in srgb, ${s.color} 15%, transparent)`, color: s.color }}>{s.label}</span>
      },
      raw: r => r.class,
    },
  ],
  valuation: [
    { key: 'sku',        label: 'Code' },
    { key: 'name',       label: 'Commodity', wrap: true, strong: true },
    { key: 'unit',       label: 'Unit' },
    { key: 'quantity',   label: 'Qty',       num: true, render: r => fmtQty(r.quantity), total: t => fmtQty(t.quantity) },
    { key: 'reserved',   label: 'Reserved',  num: true, render: r => fmtQty(r.reserved) },
    { key: 'cost_price', label: 'Cost',      num: true, render: r => money(r.cost_price) },
    { key: 'cost_value', label: 'Cost value', num: true, strong: true, render: r => money(r.cost_value), total: t => money(t.cost_value) },
    { key: 'sale_value', label: 'Sale value', num: true, render: r => money(r.sale_value), total: t => money(t.sale_value) },
    { key: 'margin',     label: 'Margin',    num: true, render: r => money(r.margin), total: t => money(t.margin) },
  ],
}

function describeFilters(fl, kind) {
  if (!fl) return ''
  const bits = []
  if (kind === 'valuation') bits.push('As of today')
  else if (fl.from || fl.to) bits.push(`${fl.from || 'start'} → ${fl.to || 'today'}`)
  bits.push(fl.warehouse ? `Warehouse: ${fl.warehouse}` : 'All warehouses')
  if (fl.product) bits.push(`Item: ${fl.product}`)
  if (fl.staff) bits.push(`Staff: ${fl.staff}`)
  return bits.join(' · ')
}

const INP = {
  width: '100%', padding: '8px 10px', borderRadius: 10, fontSize: 12,
  background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', outline: 'none',
}

const Fld = ({ label, children }) => (
  <label className="block">
    <span className="block text-[10px] font-bold uppercase tracking-wide mb-1" style={{ color: 'var(--text-muted)' }}>{label}</span>
    {children}
  </label>
)
