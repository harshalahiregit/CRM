import { useState, useEffect, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Package, Plus, Search, ScanLine, Pencil, Trash2, AlertTriangle, X } from 'lucide-react'
import { inventoryApi, INV_ACCENT, fmtQty, money } from '@/services/inventoryApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'
import { ConfirmModal } from '@/components/ui/SearchPicker'
import ProductFormModal from '../components/ProductFormModal'

/**
 * Product catalog. The scan box is first-class rather than buried: warehouse
 * staff navigate by barcode, so typing/scanning a code jumps straight to the
 * product instead of making them search by name.
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
  const [scan, setScan] = useState('')
  const [scanErr, setScanErr] = useState('')
  const [err, setErr] = useState('')

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
    return f
  }, [debounced, category])

  const { data: all = [], isLoading } = useQuery({ queryKey: ['inv-products', filters], queryFn: () => inventoryApi.products.list(filters) })
  const { data: categories = [] } = useQuery({ queryKey: ['inv-categories'], queryFn: inventoryApi.categories.list })

  const products = lowOnly ? all.filter(p => p.low_stock) : all

  const remove = useMutation({
    mutationFn: (id) => inventoryApi.products.remove(id),
    onSuccess: () => {
      setConfirmDelete(null)
      qc.invalidateQueries({ queryKey: ['inv-products'] })
      qc.invalidateQueries({ queryKey: ['inv-summary'] })
    },
    onError: (e) => { setConfirmDelete(null); setErr(e?.message || 'Could not delete that product.') },
  })

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

  return (
    <div>
      <header className="flex flex-wrap items-center gap-2 mb-4">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)` }}>
          <Package size={17} style={{ color: INV_ACCENT }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Products</h1>
        <span className="text-xs px-2 py-0.5 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>{products.length}</span>
        {lowOnly && (
          <button onClick={() => setParams({})} className="flex items-center gap-1 text-xs font-bold px-2 py-0.5 rounded-lg"
            style={{ background: 'color-mix(in srgb, #f59e0b 15%, transparent)', color: '#f59e0b' }}>
            Low stock only <X size={11} />
          </button>
        )}
        <button onClick={() => { setEditing(null); setShowForm(true) }}
          className="ml-auto flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
          style={{ background: INV_ACCENT, color: '#fff' }}>
          <Plus size={14} /> New Product
        </button>
      </header>

      {/* Scan + filters */}
      <div className="flex flex-wrap items-center gap-2 mb-4 p-2.5 rounded-2xl" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <form onSubmit={doScan} className="relative" style={{ minWidth: 210 }}>
          <ScanLine size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: INV_ACCENT }} />
          <input value={scan} onChange={e => { setScan(e.target.value); setScanErr('') }}
            placeholder="Scan barcode or SKU → Enter"
            className="w-full rounded-xl outline-none"
            style={{ padding: '8px 12px 8px 33px', fontSize: 13, background: 'var(--bg-input)', border: `1px solid ${scanErr ? 'var(--color-danger-500)' : INV_ACCENT}`, color: 'var(--text-h)' }} />
        </form>
        <div className="relative flex-1" style={{ minWidth: 180 }}>
          <Search size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search products…"
            className="w-full rounded-xl outline-none"
            style={{ padding: '8px 12px 8px 33px', fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
        </div>
        <div style={{ minWidth: 150 }}>
          <Select size="sm" value={category} onChange={setCategory} placeholder="Any category"
            options={[{ value: '', label: 'Any category' }, ...categories.map(c => ({ value: String(c.id), label: c.name }))]} />
        </div>
      </div>

      {scanErr && <p className="text-xs mb-3" style={{ color: 'var(--color-danger-500)' }}>{scanErr}</p>}
      {err && (
        <p className="text-xs px-3 py-2 rounded-lg mb-3"
          style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>
      )}

      <div className="overflow-x-auto rounded-2xl" style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
        <table className="w-full text-sm" style={{ minWidth: 860 }}>
          <thead>
            <tr className="text-left text-[11px] uppercase tracking-wide" style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
              <th className="px-4 py-3 font-bold">Product</th>
              <th className="px-4 py-3 font-bold">SKU</th>
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
                {[...Array(7)].map((_, j) => (
                  <td key={j} className="px-4 py-3"><div className="h-4 rounded animate-pulse" style={{ background: 'var(--bg-input)' }} /></td>
                ))}
              </tr>
            ))}
            {!isLoading && products.length === 0 && (
              <tr><td colSpan={7} className="px-4 py-12 text-center text-sm" style={{ color: 'var(--text-muted)' }}>
                {debounced || category || lowOnly ? 'No products match.' : 'No products yet — add your first one.'}
              </td></tr>
            )}
            {!isLoading && products.map(p => (
              <tr key={p.id} onClick={() => navigate(`/app/inventory/products/${p.id}`)} className="cursor-pointer"
                style={{ borderBottom: '1px solid var(--border)' }}
                onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
                onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-1.5">
                    {p.low_stock && <AlertTriangle size={12} style={{ color: '#f59e0b', flexShrink: 0 }} />}
                    <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{p.name}</span>
                  </div>
                  {p.brand && <span className="block text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{p.brand}</span>}
                </td>
                <td className="px-4 py-3 font-mono text-[11px]" style={{ color: 'var(--text-muted)' }}>{p.sku}</td>
                <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{p.category?.name || '—'}</td>
                <td className="px-4 py-3 text-right tabular-nums font-bold"
                  style={{ color: p.low_stock ? '#f59e0b' : 'var(--text-h)' }}>
                  {fmtQty(p.on_hand)} <span className="font-normal text-[10px]" style={{ color: 'var(--text-muted)' }}>{p.base_unit}</span>
                </td>
                <td className="px-4 py-3 text-right tabular-nums text-xs" style={{ color: 'var(--text-muted)' }}>{fmtQty(p.reserved)}</td>
                <td className="px-4 py-3 text-right tabular-nums text-xs" style={{ color: 'var(--text-muted)' }}>{money(p.cost_price)}</td>
                <td className="px-4 py-3" onClick={e => e.stopPropagation()}>
                  <div className="flex items-center justify-end gap-1">
                    <RowBtn onClick={() => { setEditing(p); setShowForm(true) }} label="Edit"><Pencil size={12} /></RowBtn>
                    {/* Deleting master data is admin-only (backend enforces it too). */}
                    {isAdmin && <RowBtn onClick={() => setConfirmDelete(p)} label="Delete" danger><Trash2 size={12} /></RowBtn>}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <ProductFormModal open={showForm} onClose={() => { setShowForm(false); setEditing(null) }}
        product={editing} onSaved={() => setErr('')} />

      <ConfirmModal open={Boolean(confirmDelete)} onClose={() => setConfirmDelete(null)}
        onConfirm={() => remove.mutate(confirmDelete.id)}
        title="Delete this product?"
        message={`“${confirmDelete?.name}” will be removed. Products that still hold stock can't be deleted.`}
        confirmLabel="Delete" danger />
    </div>
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
