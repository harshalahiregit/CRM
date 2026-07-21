import { useState, useMemo } from 'react'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { History, Search, Filter, X, FileDown, ChevronLeft, ChevronRight } from 'lucide-react'
import { inventoryApi, INV_ACCENT, fmtQty, MOVEMENT_META } from '@/services/inventoryApi'
import Select from '@/components/ui/Select'

/**
 * Inventory history (blueprint §7) — the read-only audit ledger for the whole
 * module. Every receipt, delivery, transfer and adjustment lands here with the
 * balance it started from and the balance it left behind, so "why is this 7?"
 * is answerable for any item on any day.
 *
 * Nothing on this page mutates stock; it is a window onto inventory_movements.
 */

const PAGE = 100

export default function InventoryHistory() {
  const [f, setF] = useState({ product_id: '', warehouse_id: '', type: '', actor_id: '', from: '', to: '', search: '' })
  const [page, setPage] = useState(0)

  const { data: warehouses = [] } = useQuery({ queryKey: ['inv-warehouses'], queryFn: inventoryApi.warehouses.list })
  const { data: products = [] } = useQuery({ queryKey: ['inv-products', {}], queryFn: () => inventoryApi.products.list() })
  const { data: staff = [] } = useQuery({ queryKey: ['inv-staff'], queryFn: inventoryApi.staff })

  const params = useMemo(() => {
    const p = { limit: PAGE, offset: page * PAGE }
    Object.entries(f).forEach(([k, v]) => { if (v !== '' && v != null) p[k] = v })
    return p
  }, [f, page])

  const { data, isLoading } = useQuery({
    queryKey: ['inv-history', params],
    queryFn: () => inventoryApi.history(params),
    placeholderData: keepPreviousData,
  })

  const rows = data?.rows || []
  const total = data?.total || 0
  const pages = Math.max(1, Math.ceil(total / PAGE))

  // Any filter change resets to the first page — otherwise you can land on an
  // empty page 4 of a 2-page result and think the ledger is broken.
  const set = (k, v) => { setF(s => ({ ...s, [k]: v })); setPage(0) }
  const clear = () => { setF({ product_id: '', warehouse_id: '', type: '', actor_id: '', from: '', to: '', search: '' }); setPage(0) }
  const active = Object.values(f).some(Boolean)

  const exportCsv = () => {
    const head = ['ID', 'Date', 'Type', 'Code', 'Commodity', 'From', 'To', 'Qty', 'Opening', 'Closing', 'Batch', 'Reason', 'Reference', 'By']
    const esc = v => `"${String(v ?? '').replace(/"/g, '""')}"`
    const csv = [head.map(esc).join(','), ...rows.map(r => [
      r.id, fmtDate(r.date), r.type, r.product?.sku, r.product?.name,
      r.from_warehouse?.name, r.to_warehouse?.name, r.quantity, r.opening, r.closing,
      r.batch_no, r.reason, r.reference, r.actor?.name,
    ].map(esc).join(','))].join('\n')
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
    const a = document.createElement('a')
    a.href = url; a.download = `inventory-history-${new Date().toISOString().slice(0, 10)}.csv`; a.click()
    URL.revokeObjectURL(url)
  }

  return (
    <div>
      <header className="flex items-center gap-2 mb-4">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)` }}>
          <History size={17} style={{ color: INV_ACCENT }} />
        </span>
        <div>
          <h1 className="text-lg font-bold leading-tight" style={{ color: 'var(--text-h)' }}>Inventory history</h1>
          <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
            {total.toLocaleString('en-IN')} movement{total === 1 ? '' : 's'} · every stock change, oldest balance to newest
          </p>
        </div>
        <button onClick={exportCsv} disabled={!rows.length}
          className="ml-auto flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold disabled:opacity-40"
          style={{ border: '1px solid var(--border)', color: 'var(--text-body)' }}>
          <FileDown size={14} /> Export CSV
        </button>
      </header>

      {/* Filters */}
      <section className="rounded-2xl p-3 mb-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <div className="flex items-center gap-2 mb-2.5">
          <Filter size={13} style={{ color: INV_ACCENT }} />
          <span className="text-[11px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Filters</span>
          {active && (
            <button onClick={clear} className="ml-auto flex items-center gap-1 text-[11px] font-bold" style={{ color: 'var(--text-muted)' }}>
              <X size={11} /> Clear
            </button>
          )}
        </div>

        <div className="relative mb-2.5">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input value={f.search} onChange={e => set('search', e.target.value)}
            placeholder="Search item, batch, serial, reason or note…"
            style={{ ...INP, paddingLeft: 34 }} />
        </div>

        <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(148px,1fr))' }}>
          <Fld label="Commodity">
            <Select value={f.product_id} onChange={v => set('product_id', v)} placeholder="All items"
              options={[{ value: '', label: 'All items' }, ...products.map(p => ({ value: p.id, label: `${p.sku} · ${p.name}` }))]} />
          </Fld>
          <Fld label="Warehouse">
            <Select value={f.warehouse_id} onChange={v => set('warehouse_id', v)} placeholder="All warehouses"
              options={[{ value: '', label: 'All warehouses' }, ...warehouses.map(w => ({ value: w.id, label: w.name }))]} />
          </Fld>
          <Fld label="Type">
            <Select value={f.type} onChange={v => set('type', v)} placeholder="All types"
              options={[{ value: '', label: 'All types' }, ...Object.values(MOVEMENT_META).map(m => ({ value: m.value, label: m.label, dot: m.color }))]} />
          </Fld>
          <Fld label="Staff">
            <Select value={f.actor_id} onChange={v => set('actor_id', v)} placeholder="Anyone"
              options={[{ value: '', label: 'Anyone' }, ...staff.map(s => ({ value: s.id, label: s.name }))]} />
          </Fld>
          <Fld label="From"><input type="date" value={f.from} onChange={e => set('from', e.target.value)} style={INP} /></Fld>
          <Fld label="To"><input type="date" value={f.to} onChange={e => set('to', e.target.value)} style={INP} /></Fld>
        </div>
      </section>

      {/* Ledger */}
      <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        {isLoading && <div className="h-40 rounded-xl animate-pulse" style={{ background: 'var(--bg-input)' }} />}

        {!isLoading && rows.length === 0 && (
          <p className="text-xs py-10 text-center" style={{ color: 'var(--text-muted)' }}>
            No movements match these filters.
          </p>
        )}

        {!isLoading && rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-xs" style={{ minWidth: 900 }}>
              <thead>
                <tr style={{ borderBottom: '1px solid var(--border)' }}>
                  {['ID', 'Date', 'Type', 'Commodity', 'Movement', 'Qty', 'Opening', 'Closing', 'Batch / reason', 'By'].map((h, i) => (
                    <th key={h} className={`px-2 py-2 font-bold ${[5, 6, 7].includes(i) ? 'text-right' : 'text-left'}`}
                      style={{ color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {rows.map(r => {
                  const meta = MOVEMENT_META[r.type] || { label: r.type, color: 'var(--text-muted)' }
                  const sign = r.direction === 'in' ? '+' : r.direction === 'out' ? '−' : '⇄'
                  const signColor = r.direction === 'in' ? '#10B981' : r.direction === 'out' ? '#ef4444' : '#3b82f6'
                  return (
                    <tr key={r.id} style={{ borderBottom: '1px solid var(--border)' }}>
                      <td className="px-2 py-2 tabular-nums" style={{ color: 'var(--text-muted)' }}>#{r.id}</td>
                      <td className="px-2 py-2" style={{ color: 'var(--text-body)', whiteSpace: 'nowrap' }}>{fmtDate(r.date)}</td>
                      <td className="px-2 py-2">
                        <span className="text-[10px] font-bold px-2 py-0.5 rounded-full"
                          style={{ background: `color-mix(in srgb, ${meta.color} 15%, transparent)`, color: meta.color }}>
                          {meta.label}
                        </span>
                      </td>
                      <td className="px-2 py-2">
                        {r.product ? (
                          <Link to={`/app/inventory/products/${r.product.id}`} className="hover:underline">
                            <span className="block font-semibold" style={{ color: 'var(--text-h)' }}>{r.product.name}</span>
                            <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{r.product.sku}</span>
                          </Link>
                        ) : <span style={{ color: 'var(--text-muted)' }}>—</span>}
                      </td>
                      <td className="px-2 py-2" style={{ color: 'var(--text-body)', whiteSpace: 'nowrap' }}>
                        {r.from_warehouse?.name || '—'} <span style={{ color: 'var(--text-muted)' }}>→</span> {r.to_warehouse?.name || '—'}
                      </td>
                      <td className="px-2 py-2 text-right tabular-nums font-bold" style={{ color: signColor }}>
                        {sign}{fmtQty(r.quantity)}
                      </td>
                      <td className="px-2 py-2 text-right tabular-nums" style={{ color: 'var(--text-muted)' }}>
                        {r.opening == null ? '—' : fmtQty(r.opening)}
                      </td>
                      <td className="px-2 py-2 text-right tabular-nums font-bold" style={{ color: 'var(--text-h)' }}>
                        {r.closing == null ? '—' : fmtQty(r.closing)}
                      </td>
                      <td className="px-2 py-2" style={{ color: 'var(--text-muted)', maxWidth: 200 }}>
                        {r.batch_no && <span className="font-mono">{r.batch_no}</span>}
                        {r.batch_no && (r.reason || r.notes) && ' · '}
                        {r.reason || r.notes || (!r.batch_no && '—')}
                        {r.reference && <span className="block text-[10px] opacity-70">{r.reference}</span>}
                      </td>
                      <td className="px-2 py-2" style={{ color: 'var(--text-body)', whiteSpace: 'nowrap' }}>{r.actor?.name || 'System'}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}

        {/* Pager */}
        {pages > 1 && (
          <div className="flex items-center justify-between mt-3 pt-3" style={{ borderTop: '1px solid var(--border)' }}>
            <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
              Page {page + 1} of {pages}
            </span>
            <div className="flex gap-1.5">
              <PagerBtn disabled={page === 0} onClick={() => setPage(p => p - 1)}><ChevronLeft size={14} /> Prev</PagerBtn>
              <PagerBtn disabled={page + 1 >= pages} onClick={() => setPage(p => p + 1)}>Next <ChevronRight size={14} /></PagerBtn>
            </div>
          </div>
        )}
      </section>
    </div>
  )
}

const fmtDate = (d) => d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—'

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

const PagerBtn = ({ disabled, onClick, children }) => (
  <button disabled={disabled} onClick={onClick}
    className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold disabled:opacity-35"
    style={{ border: '1px solid var(--border)', color: 'var(--text-body)' }}>
    {children}
  </button>
)
