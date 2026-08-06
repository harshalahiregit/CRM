import { useState, useEffect, useMemo, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import {
  Package, Plus, Search, ScanLine, Pencil, Trash2, AlertTriangle, X,
  FileDown, Upload, Barcode, ChevronDown, Check, Tag, Filter,
} from 'lucide-react'
import {
  inventoryApi, INV_ACCENT, fmtQty, money, ALERT_OPTIONS, BULK_ACTIONS,
} from '@/services/inventoryApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'
import { ConfirmModal } from '@/components/ui/SearchPicker'
import ProductFormModal from '../components/ProductFormModal'
import ImportModal from '../components/ImportModal'
import BarcodeSheet from '../components/BarcodeSheet'
import ListControls from '@/components/ui/ListControls'

/**
 * Items (blueprint §1). The scan box is first-class rather than buried:
 * warehouse staff navigate by barcode, so typing/scanning a code jumps straight
 * to the item instead of making them search by name.
 *
 * Adds the blueprint's full toolbar — Tags/Alert/Warehouse/Item filters, export
 * (all or checked), bulk actions, barcode printing, and the two importers.
 */
export default function ProductList() {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { user } = useAuth()
  const [params, setParams] = useSearchParams()

  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState(null)
  const [confirmDelete, setConfirmDelete] = useState(null)
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [category, setCategory] = useState('')
  const [tag, setTag] = useState('')
  const [alert, setAlert] = useState('')
  const [warehouse, setWarehouse] = useState('')
  const [item, setItem] = useState('')
  const [scan, setScan] = useState('')
  const [scanErr, setScanErr] = useState('')
  const [err, setErr] = useState('')

  const [selected, setSelected] = useState(() => new Set())
  const [bulkOpen, setBulkOpen] = useState(false)
  const [bulkAsk, setBulkAsk] = useState(null)      // { action, label }
  const [bulkValue, setBulkValue] = useState('')
  const [importKind, setImportKind] = useState(null)
  const [printing, setPrinting] = useState(null)    // products to print barcodes for
  const [pageSize, setPageSize] = useState(25)       // rows shown; 0 = all

  // Dashboard's "Low stock" tile links here with ?filter=low.
  const lowOnly = params.get('filter') === 'low'
  const isAdmin = user?.role === 'admin'

  useEffect(() => {
    const t = setTimeout(() => setDebounced(search.trim()), 300)
    return () => clearTimeout(t)
  }, [search])

  const filters = useMemo(() => {
    const f = {}
    if (debounced) f.search = debounced
    if (category) f.category_id = category
    if (tag) f.tag = tag
    if (alert) f.alert = alert
    if (warehouse) f.warehouse_id = warehouse
    if (item) f.product_id = item
    return f
  }, [debounced, category, tag, alert, warehouse, item])

  const { data: all = [], isLoading, refetch: refetchProducts } = useQuery({ queryKey: ['inv-products', filters], queryFn: () => inventoryApi.products.list(filters) })
  const { data: categories = [] } = useQuery({ queryKey: ['inv-categories'], queryFn: inventoryApi.categories.list })
  const { data: warehouses = [] } = useQuery({ queryKey: ['inv-warehouses'], queryFn: inventoryApi.warehouses.list })
  const { data: tags = [] } = useQuery({ queryKey: ['inv-tags'], queryFn: inventoryApi.products.tags })
  const { data: allItems = [] } = useQuery({ queryKey: ['inv-products', {}], queryFn: () => inventoryApi.products.list() })

  const products = lowOnly ? all.filter(p => p.low_stock) : all
  const pagedProducts = pageSize === 0 ? products : products.slice(0, pageSize)
  const anyFilter = category || tag || alert || warehouse || item || debounced || lowOnly

  const clearFilters = () => {
    setCategory(''); setTag(''); setAlert(''); setWarehouse(''); setItem(''); setSearch(''); setParams({})
  }

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['inv-products'] })
    qc.invalidateQueries({ queryKey: ['inv-summary'] })
    qc.invalidateQueries({ queryKey: ['inv-tags'] })
  }

  const remove = useMutation({
    mutationFn: (id) => inventoryApi.products.remove(id),
    onSuccess: () => { setConfirmDelete(null); refresh() },
    onError: (e) => { setConfirmDelete(null); setErr(e?.message || 'Could not delete that product.') },
  })

  const bulk = useMutation({
    mutationFn: ({ action, value }) => inventoryApi.products.bulk(action, [...selected], value),
    onSuccess: (res) => {
      setBulkAsk(null); setBulkValue(''); setSelected(new Set()); refresh()
      // Say what was skipped rather than implying the whole batch went through.
      if (res?.failed?.length) setErr(`${res.failed.length} item(s) skipped — ${res.failed[0].reason}`)
      else setErr('')
    },
    onError: (e) => setErr(e?.message || 'That bulk action failed.'),
  })

  /* Selection is scoped to what's on screen. */
  const allChecked = products.length > 0 && products.every(p => selected.has(p.id))
  const toggleAll = () => setSelected(allChecked ? new Set() : new Set(products.map(p => p.id)))
  const toggleOne = (id) => setSelected(s => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n })
  const chosen = products.filter(p => selected.has(p.id))

  const exportCsv = (rows, name) => {
    const cols = [
      ['Commodity Code', p => p.sku], ['Commodity Name', p => p.name], ['Barcode', p => p.barcode],
      ['Category', p => p.category?.name], ['Brand', p => p.brand], ['Tags', p => (p.tags || []).join(' | ')],
      ['Unit', p => p.base_unit], ['On hand', p => p.on_hand], ['Reserved', p => p.reserved],
      ['Purchase Price', p => p.cost_price], ['Sale price', p => p.sale_price], ['Tax %', p => p.gst_rate],
      ['Minimum stock', p => p.min_stock], ['Maximum stock', p => p.max_stock], ['Status', p => p.status],
    ]
    const esc = v => `"${String(v ?? '').replace(/"/g, '""')}"`
    const csv = [cols.map(c => esc(c[0])).join(','), ...rows.map(p => cols.map(c => esc(c[1](p))).join(','))].join('\n')
    const url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }))
    const a = document.createElement('a')
    a.href = url; a.download = `${name}-${new Date().toISOString().slice(0, 10)}.csv`; a.click()
    URL.revokeObjectURL(url)
  }

  const doScan = async (e) => {
    e.preventDefault()
    const code = scan.trim()
    if (!code) return
    setScanErr('')
    try {
      const p = await inventoryApi.products.lookup(code)
      setScan('')
      navigate(`/app/inventory/products/${p.id}`)
    } catch (ex) {
      setScanErr(ex?.message || 'No match.')
    }
  }

  const runBulk = (action) => {
    setBulkOpen(false)
    const meta = BULK_ACTIONS.find(a => a.value === action)
    setBulkValue('')
    setBulkAsk({ action, label: meta?.label || action })
  }

  return (
    <div>
      <header className="flex flex-wrap items-center gap-2 mb-4">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)` }}>
          <Package size={17} style={{ color: INV_ACCENT }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Items</h1>
        <span className="text-xs px-2 py-0.5 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>{products.length}</span>
        {lowOnly && (
          <button onClick={() => setParams({})} className="flex items-center gap-1 text-xs font-bold px-2 py-0.5 rounded-lg"
            style={{ background: 'color-mix(in srgb, #f59e0b 15%, transparent)', color: '#f59e0b' }}>
            Low stock only <X size={11} />
          </button>
        )}

        <div className="ml-auto flex flex-wrap items-center gap-2">
          {isAdmin && (
            <>
              <ToolBtn onClick={() => setImportKind('items')}><Upload size={13} /> Import items</ToolBtn>
              <ToolBtn onClick={() => setImportKind('opening-stock')}><Upload size={13} /> Import opening stock</ToolBtn>
            </>
          )}
          <ListControls pageSize={pageSize} onPageSize={setPageSize} onRefresh={refetchProducts} accent={INV_ACCENT} />
          <ToolBtn onClick={() => exportCsv(products, 'items')} disabled={!products.length}><FileDown size={13} /> Export</ToolBtn>
          <button onClick={() => { setEditing(null); setShowForm(true) }}
            className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
            style={{ background: INV_ACCENT, color: '#fff' }}>
            <Plus size={14} /> Add
          </button>
        </div>
      </header>

      {/* Scan + search */}
      <div className="flex flex-wrap items-center gap-2 mb-2 p-2.5 rounded-2xl" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <form onSubmit={doScan} className="relative" style={{ minWidth: 210 }}>
          <ScanLine size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: INV_ACCENT }} />
          <input value={scan} onChange={e => { setScan(e.target.value); setScanErr('') }}
            placeholder="Scan barcode or SKU → Enter"
            className="w-full rounded-xl outline-none"
            style={{ padding: '8px 12px 8px 33px', fontSize: 13, background: 'var(--bg-input)', border: `1px solid ${scanErr ? 'var(--color-danger-500)' : INV_ACCENT}`, color: 'var(--text-h)' }} />
        </form>
        <div className="relative flex-1" style={{ minWidth: 180 }}>
          <Search size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search items…"
            className="w-full rounded-xl outline-none"
            style={{ padding: '8px 12px 8px 33px', fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
        </div>
      </div>

      {/* Blueprint filter row: Tags · Alert · Warehouse · Item (+ category) */}
      <div className="flex flex-wrap items-center gap-2 mb-4 p-2.5 rounded-2xl" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <Filter size={13} style={{ color: INV_ACCENT }} />
        <div style={{ minWidth: 130 }}>
          <Select size="sm" value={tag} onChange={setTag} placeholder="Tags"
            options={[{ value: '', label: 'Any tag' }, ...tags.map(t => ({ value: t, label: t }))]} />
        </div>
        <div style={{ minWidth: 150 }}>
          <Select size="sm" value={alert} onChange={setAlert} placeholder="Alert"
            options={[{ value: '', label: 'Any alert' }, ...ALERT_OPTIONS]} />
        </div>
        <div style={{ minWidth: 140 }}>
          <Select size="sm" value={warehouse} onChange={setWarehouse} placeholder="Warehouse"
            options={[{ value: '', label: 'Any warehouse' }, ...warehouses.map(w => ({ value: String(w.id), label: w.name }))]} />
        </div>
        <div style={{ minWidth: 150 }}>
          <Select size="sm" value={item} onChange={setItem} placeholder="Item"
            options={[{ value: '', label: 'Any item' }, ...allItems.map(p => ({ value: String(p.id), label: `${p.sku} · ${p.name}` }))]} />
        </div>
        <div style={{ minWidth: 140 }}>
          <Select size="sm" value={category} onChange={setCategory} placeholder="Any category"
            options={[{ value: '', label: 'Any category' }, ...categories.map(c => ({ value: String(c.id), label: c.name }))]} />
        </div>
        {anyFilter && (
          <button onClick={clearFilters} className="flex items-center gap-1 text-[11px] font-bold px-2 py-1.5 rounded-lg"
            style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
            <X size={11} /> Clear
          </button>
        )}
      </div>

      {/* Selection toolbar — only once something is checked */}
      {selected.size > 0 && (
        <div className="flex flex-wrap items-center gap-2 mb-3 px-3 py-2.5 rounded-2xl"
          style={{ background: `color-mix(in srgb, ${INV_ACCENT} 10%, transparent)`, border: `1px solid ${INV_ACCENT}` }}>
          <span className="text-xs font-bold" style={{ color: INV_ACCENT }}>{selected.size} selected</span>

          <div className="relative">
            <button onClick={() => setBulkOpen(o => !o)}
              className="flex items-center gap-1 text-xs font-bold px-3 py-1.5 rounded-lg"
              style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-body)' }}>
              Bulk actions <ChevronDown size={12} />
            </button>
            {bulkOpen && (
              <>
                <div className="fixed inset-0 z-10" onClick={() => setBulkOpen(false)} />
                <div className="absolute left-0 mt-1 z-20 rounded-xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', minWidth: 170, boxShadow: 'var(--shadow-card-3d)' }}>
                  {BULK_ACTIONS.filter(a => a.value !== 'delete' || isAdmin).map(a => (
                    <button key={a.value} onClick={() => runBulk(a.value)}
                      className="w-full text-left text-xs px-3 py-2 hover:opacity-75"
                      style={{ color: a.danger ? 'var(--color-danger-500)' : 'var(--text-body)' }}>
                      {a.label}
                    </button>
                  ))}
                </div>
              </>
            )}
          </div>

          <ToolBtn onClick={() => exportCsv(chosen, 'items-selected')}><FileDown size={13} /> Export selected</ToolBtn>
          <ToolBtn onClick={() => setPrinting(chosen)}><Barcode size={13} /> Print barcode</ToolBtn>
          <button onClick={() => setSelected(new Set())} className="ml-auto text-[11px] font-bold" style={{ color: 'var(--text-muted)' }}>Clear</button>
        </div>
      )}

      {scanErr && <p className="text-xs mb-3" style={{ color: 'var(--color-danger-500)' }}>{scanErr}</p>}
      {err && (
        <p className="text-xs px-3 py-2 rounded-lg mb-3"
          style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>
      )}

      <div className="overflow-x-auto rounded-2xl" style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
        <table className="w-full text-sm" style={{ minWidth: 940 }}>
          <thead>
            <tr className="text-left text-[11px] uppercase tracking-wide" style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
              <th className="px-3 py-3">
                <input type="checkbox" checked={allChecked} onChange={toggleAll} aria-label="Select all"
                  style={{ accentColor: INV_ACCENT, width: 14, height: 14, cursor: 'pointer' }} />
              </th>
              <th className="px-4 py-3 font-bold">Item</th>
              <th className="px-4 py-3 font-bold">SKU</th>
              <th className="px-4 py-3 font-bold">Tags</th>
              <th className="px-4 py-3 font-bold">Category</th>
              <th className="px-4 py-3 font-bold text-right">On hand</th>
              <th className="px-4 py-3 font-bold text-right">Reserved</th>
              <th className="px-4 py-3 font-bold text-right">Cost</th>
              <th className="px-4 py-3 font-bold text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {isLoading && [1, 2, 3].map(i => (
              <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                {[...Array(9)].map((_, j) => (
                  <td key={j} className="px-4 py-3"><div className="h-4 rounded animate-pulse" style={{ background: 'var(--bg-input)' }} /></td>
                ))}
              </tr>
            ))}
            {!isLoading && products.length === 0 && (
              <tr><td colSpan={9} className="px-4 py-12 text-center text-sm" style={{ color: 'var(--text-muted)' }}>
                {anyFilter ? 'No items match these filters.' : 'No items yet — add your first one.'}
              </td></tr>
            )}
            {!isLoading && pagedProducts.map(p => (
              <tr key={p.id} onClick={() => navigate(`/app/inventory/products/${p.id}`)} className="cursor-pointer"
                style={{ borderBottom: '1px solid var(--border)', background: selected.has(p.id) ? 'var(--bg-input)' : 'transparent' }}
                onMouseEnter={e => { if (!selected.has(p.id)) e.currentTarget.style.background = 'var(--bg-input)' }}
                onMouseLeave={e => { if (!selected.has(p.id)) e.currentTarget.style.background = 'transparent' }}>
                <td className="px-3 py-3" onClick={e => e.stopPropagation()}>
                  <input type="checkbox" checked={selected.has(p.id)} onChange={() => toggleOne(p.id)} aria-label={`Select ${p.name}`}
                    style={{ accentColor: INV_ACCENT, width: 14, height: 14, cursor: 'pointer' }} />
                </td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-1.5">
                    {p.low_stock && <AlertTriangle size={12} style={{ color: '#f59e0b', flexShrink: 0 }} />}
                    <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{p.name}</span>
                  </div>
                  {p.brand && <span className="block text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{p.brand}</span>}
                </td>
                <td className="px-4 py-3 font-mono text-[11px]" style={{ color: 'var(--text-muted)' }}>{p.sku}</td>
                <td className="px-4 py-3">
                  <div className="flex flex-wrap gap-1">
                    {(p.tags || []).slice(0, 3).map(t => (
                      <span key={t} className="inline-flex items-center gap-0.5 text-[9px] font-bold px-1.5 py-0.5 rounded-full"
                        style={{ background: `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)`, color: INV_ACCENT }}>
                        <Tag size={8} />{t}
                      </span>
                    ))}
                    {(p.tags || []).length > 3 && <span className="text-[9px]" style={{ color: 'var(--text-muted)' }}>+{p.tags.length - 3}</span>}
                    {!(p.tags || []).length && <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>—</span>}
                  </div>
                </td>
                <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{p.category?.name || '—'}</td>
                <td className="px-4 py-3 text-right tabular-nums font-bold"
                  style={{ color: p.low_stock ? '#f59e0b' : 'var(--text-h)' }}>
                  {fmtQty(p.on_hand)} <span className="font-normal text-[10px]" style={{ color: 'var(--text-muted)' }}>{p.base_unit}</span>
                </td>
                <td className="px-4 py-3 text-right tabular-nums text-xs" style={{ color: 'var(--text-muted)' }}>{fmtQty(p.reserved)}</td>
                <td className="px-4 py-3 text-right tabular-nums text-xs" style={{ color: 'var(--text-muted)' }}>{money(p.cost_price)}</td>
                <td className="px-4 py-3" onClick={e => e.stopPropagation()}>
                  <div className="flex items-center justify-end gap-1">
                    <RowBtn onClick={() => setPrinting([p])} label="Print barcode"><Barcode size={12} /></RowBtn>
                    {/* can_edit / can_delete come from the server so the UI can
                        never offer an action the barrier would refuse. */}
                    {p.can_edit && <RowBtn onClick={() => { setEditing(p); setShowForm(true) }} label="Edit"><Pencil size={12} /></RowBtn>}
                    {p.can_delete && <RowBtn onClick={() => setConfirmDelete(p)} label="Delete" danger><Trash2 size={12} /></RowBtn>}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <ProductFormModal open={showForm} onClose={() => { setShowForm(false); setEditing(null) }}
        product={editing} onSaved={() => setErr('')} />

      <ImportModal kind={importKind} onClose={() => setImportKind(null)} onDone={refresh} />

      {printing && <BarcodeSheet products={printing} onClose={() => setPrinting(null)} />}

      <ConfirmModal open={Boolean(confirmDelete)} onClose={() => setConfirmDelete(null)}
        onConfirm={() => remove.mutate(confirmDelete.id)}
        title="Delete this item?"
        message={`“${confirmDelete?.name}” will be removed. Items that still hold stock can't be deleted.`}
        confirmLabel="Delete" danger />

      {/* Bulk action prompt — asks for the value the chosen action needs. */}
      {bulkAsk && (
        <div className="fixed inset-0 z-[70] flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,0.5)' }} onClick={() => setBulkAsk(null)}>
          <div className="w-full max-w-[380px] rounded-2xl p-5" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }} onClick={e => e.stopPropagation()}>
            <h3 className="font-bold text-sm mb-1" style={{ color: 'var(--text-h)' }}>{bulkAsk.label}</h3>
            <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>Applies to {selected.size} selected item{selected.size === 1 ? '' : 's'}.</p>

            {bulkAsk.action === 'status' && (
              <Select value={bulkValue} onChange={setBulkValue} placeholder="Choose a status"
                options={[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]} />
            )}
            {bulkAsk.action === 'category' && (
              <Select value={bulkValue} onChange={setBulkValue} placeholder="Choose a category"
                options={[{ value: '', label: '— none —' }, ...categories.map(c => ({ value: String(c.id), label: c.name }))]} />
            )}
            {(bulkAsk.action === 'add_tags' || bulkAsk.action === 'remove_tags') && (
              <input value={bulkValue} onChange={e => setBulkValue(e.target.value)} autoFocus
                placeholder="Tags, comma separated"
                className="w-full rounded-xl outline-none"
                style={{ padding: '9px 12px', fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
            )}
            {bulkAsk.action === 'delete' && (
              <p className="text-xs" style={{ color: 'var(--color-danger-500)' }}>
                This permanently removes the selected items. Items holding stock will be skipped.
              </p>
            )}

            <div className="flex justify-end gap-2 mt-5">
              <button onClick={() => setBulkAsk(null)} className="px-3 py-2 rounded-xl text-xs font-bold"
                style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
              <button
                disabled={bulk.isPending || (['status', 'add_tags', 'remove_tags'].includes(bulkAsk.action) && !bulkValue)}
                onClick={() => bulk.mutate({ action: bulkAsk.action, value: bulkValue })}
                className="flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold disabled:opacity-40"
                style={{ background: bulkAsk.action === 'delete' ? 'var(--color-danger-500)' : INV_ACCENT, color: '#fff' }}>
                <Check size={13} /> {bulk.isPending ? 'Applying…' : 'Apply'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

function ToolBtn({ onClick, disabled, children }) {
  return (
    <button onClick={onClick} disabled={disabled}
      className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40"
      style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-body)' }}>
      {children}
    </button>
  )
}

function RowBtn({ onClick, label, danger, children }) {
  return (
    <button onClick={onClick} aria-label={label} title={label}
      className="w-7 h-7 rounded-lg flex items-center justify-center"
      style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: danger ? 'var(--color-danger-500)' : 'var(--text-muted)' }}>
      {children}
    </button>
  )
}
