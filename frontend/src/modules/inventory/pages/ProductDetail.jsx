import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, Pencil, ArrowLeftRight, ClipboardCheck, Warehouse, History,
  AlertTriangle, Barcode, Package, HardHat,
} from 'lucide-react'
import { inventoryApi, INV_ACCENT, MOVEMENT_META, fmtQty, money } from '@/services/inventoryApi'
import { tpvApi } from '@/services/tpvApi'
import ProductFormModal from '../components/ProductFormModal'
import StockMoveModal from '../components/StockMoveModal'

const fmtDateTime = d => d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'

/**
 * One product: where it is, and every reason the number ever changed.
 * The ledger is the point of the page — a balance you can't explain is a
 * balance nobody trusts.
 */
export default function ProductDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [editing, setEditing] = useState(false)
  const [stockModal, setStockModal] = useState(null)   // 'move' | 'adjust'

  const { data: product, isLoading, isError, error } = useQuery({
    queryKey: ['inv-product', id], queryFn: () => inventoryApi.products.get(id),
  })
  const { data: history = [] } = useQuery({
    queryKey: ['inv-product-history', id], queryFn: () => inventoryApi.products.history(id),
  })

  if (isLoading) return <div className="rounded-2xl animate-pulse" style={{ height: 220, background: 'var(--bg-card)' }} />
  if (isError) {
    return (
      <div className="p-6 rounded-2xl" style={{ border: '1px solid color-mix(in srgb, var(--color-danger-500) 30%, transparent)', background: 'var(--bg-card)' }}>
        <p className="text-sm" style={{ color: 'var(--color-danger-500)' }}>{error?.message}</p>
        <button onClick={() => navigate('/app/inventory/products')} className="text-xs mt-3 underline" style={{ color: 'var(--text-muted)' }}>Back to products</button>
      </div>
    )
  }

  const totals = product.totals || { quantity: 0, reserved: 0, available: 0 }
  const threshold = Number(product.reorder_point || product.min_stock || 0)
  const low = threshold > 0 && Number(totals.quantity) <= threshold

  return (
    <div className="max-w-5xl">
      <button onClick={() => navigate('/app/inventory/products')} className="flex items-center gap-1.5 text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={13} /> Products
      </button>

      {/* Header */}
      <div className="flex items-start justify-between gap-4 mb-4 flex-wrap">
        <div className="min-w-0">
          <div className="flex items-center gap-2 flex-wrap mb-1">
            <span className="font-mono text-[11px] px-2 py-0.5 rounded-lg" style={{ background: `color-mix(in srgb, ${INV_ACCENT} 12%, transparent)`, color: INV_ACCENT }}>
              {product.sku}
            </span>
            {product.category?.name && (
              <span className="text-[11px] px-2 py-0.5 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>{product.category.name}</span>
            )}
            {low && (
              <span className="flex items-center gap-1 text-[11px] font-bold px-2 py-0.5 rounded-lg"
                style={{ background: 'color-mix(in srgb, #f59e0b 15%, transparent)', color: '#f59e0b' }}>
                <AlertTriangle size={10} /> Low stock
              </span>
            )}
            {product.status === 'inactive' && (
              <span className="text-[11px] px-2 py-0.5 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>Inactive</span>
            )}
          </div>
          <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>{product.name}</h1>
          {product.barcode && (
            <p className="text-[11px] mt-1 flex items-center gap-1" style={{ color: 'var(--text-muted)' }}>
              <Barcode size={11} /> {product.barcode}
            </p>
          )}
        </div>

        <div className="flex items-center gap-2">
          <button onClick={() => setStockModal('move')}
            className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl" style={{ background: INV_ACCENT, color: '#fff' }}>
            <ArrowLeftRight size={13} /> Move stock
          </button>
          <button onClick={() => setStockModal('adjust')}
            className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
            style={{ background: 'color-mix(in srgb, #8b5cf6 14%, transparent)', color: '#8b5cf6' }}>
            <ClipboardCheck size={13} /> Adjust count
          </button>
          <button onClick={() => setEditing(true)} aria-label="Edit product"
            className="w-9 h-9 rounded-xl flex items-center justify-center"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
            <Pencil size={14} />
          </button>
        </div>
      </div>

      {/* Totals */}
      <div className="grid gap-3 mb-4" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))' }}>
        <Tile label="On hand" value={`${fmtQty(totals.quantity)} ${product.base_unit}`} color={low ? '#f59e0b' : 'var(--text-h)'} />
        <Tile label="Reserved" value={fmtQty(totals.reserved)} color="var(--text-muted)" />
        <Tile label="Available" value={fmtQty(totals.available)} color={INV_ACCENT} />
        <Tile label="Stock value" value={money((Number(totals.quantity) * Number(product.cost_price || 0)).toFixed(2))} color="var(--text-h)" />
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        {/* Where it is */}
        <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <h2 className="font-bold text-xs mb-3 flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
            <Warehouse size={14} style={{ color: INV_ACCENT }} /> Where it is
          </h2>
          <ul className="space-y-1.5">
            {(product.levels || []).filter(l => Number(l.quantity) !== 0).map(l => (
              <li key={l.id} className="flex items-center gap-2 px-3 py-2 rounded-xl" style={{ background: 'var(--bg-input)' }}>
                <span className="flex-1 min-w-0">
                  <span className="block text-xs font-semibold truncate" style={{ color: 'var(--text-h)' }}>{l.warehouse?.name}</span>
                  {l.location?.name && <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{l.location.name}</span>}
                </span>
                <span className="text-xs font-black tabular-nums" style={{ color: 'var(--text-h)' }}>{fmtQty(l.quantity)}</span>
              </li>
            ))}
            {(product.levels || []).filter(l => Number(l.quantity) !== 0).length === 0 && (
              <li className="text-xs py-4 text-center" style={{ color: 'var(--text-muted)' }}>No stock anywhere yet.</li>
            )}
          </ul>
        </section>

        {/* Spec */}
        <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <h2 className="font-bold text-xs mb-3 flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
            <Package size={14} style={{ color: INV_ACCENT }} /> Details
          </h2>
          <dl className="space-y-2">
            <Row label="Cost / Sale" value={`${money(product.cost_price)} / ${money(product.sale_price)}`} />
            <Row label="Reorder point" value={fmtQty(product.reorder_point)} />
            <Row label="Min / Max" value={`${fmtQty(product.min_stock)} / ${product.max_stock ? fmtQty(product.max_stock) : '—'}`} />
            <Row label="Brand / Model" value={[product.brand, product.model].filter(Boolean).join(' · ') || '—'} />
            <Row label="HSN / GST" value={[product.hsn, product.gst_rate ? product.gst_rate + '%' : null].filter(Boolean).join(' · ') || '—'} />
            <Row label="Tracking" value={[product.track_batch && 'Batch', product.track_serial && 'Serial'].filter(Boolean).join(' · ') || 'None'} />
          </dl>
        </section>
      </div>

      {/* The other half of the PPE integration: who is holding this item. */}
      {product.category?.name === 'PPE' && <PpeHolders productId={product.id} />}

      {/* Ledger */}
      <section className="rounded-2xl p-4 mt-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <h2 className="font-bold text-xs mb-3 flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
          <History size={14} style={{ color: INV_ACCENT }} /> Stock ledger
          <span className="font-normal" style={{ color: 'var(--text-muted)' }}>every change, and who made it</span>
        </h2>

        {history.length === 0 && <p className="text-xs py-6 text-center" style={{ color: 'var(--text-muted)' }}>No movements recorded yet.</p>}

        {history.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-xs" style={{ minWidth: 620 }}>
              <thead>
                <tr className="text-left text-[10px] uppercase tracking-wide" style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                  <th className="px-2 py-2 font-bold">What</th>
                  <th className="px-2 py-2 font-bold">Where</th>
                  <th className="px-2 py-2 font-bold text-right">Qty</th>
                  <th className="px-2 py-2 font-bold text-right">Balance</th>
                  <th className="px-2 py-2 font-bold">Reason</th>
                  <th className="px-2 py-2 font-bold">By / When</th>
                </tr>
              </thead>
              <tbody>
                {history.map(m => {
                  const meta = MOVEMENT_META[m.type] || { label: m.type, color: 'var(--text-muted)' }
                  const sign = m.direction === 'in' ? '+' : m.direction === 'out' ? '−' : '→'
                  const where = m.direction === 'transfer'
                    ? `${m.from_warehouse?.name || '?'} → ${m.to_warehouse?.name || '?'}`
                    : (m.to_warehouse?.name || m.from_warehouse?.name || '—')
                  return (
                    <tr key={m.id} style={{ borderBottom: '1px solid var(--border)' }}>
                      <td className="px-2 py-2">
                        <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg capitalize"
                          style={{ background: `color-mix(in srgb, ${meta.color} 15%, transparent)`, color: meta.color }}>{meta.label}</span>
                      </td>
                      <td className="px-2 py-2" style={{ color: 'var(--text-muted)' }}>{where}</td>
                      <td className="px-2 py-2 text-right tabular-nums font-bold"
                        style={{ color: m.direction === 'out' ? 'var(--color-danger-500)' : m.direction === 'in' ? INV_ACCENT : '#3b82f6' }}>
                        {sign}{fmtQty(m.quantity)}
                      </td>
                      <td className="px-2 py-2 text-right tabular-nums" style={{ color: 'var(--text-h)' }}>{m.balance_after != null ? fmtQty(m.balance_after) : '—'}</td>
                      <td className="px-2 py-2 truncate" style={{ color: 'var(--text-muted)', maxWidth: 160 }}>{m.reason || '—'}</td>
                      <td className="px-2 py-2 text-[10px]" style={{ color: 'var(--text-muted)' }}>
                        {m.actor?.name || 'System'}<br />{fmtDateTime(m.created_at)}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <ProductFormModal open={editing} onClose={() => setEditing(false)} product={product} />
      <StockMoveModal open={Boolean(stockModal)} mode={stockModal || 'move'} product={product} onClose={() => setStockModal(null)} />
    </div>
  )
}

/**
 * Everyone currently holding this PPE item.
 *
 * The Inventory→PPE direction of the integration: the ledger above says stock
 * left, this says who has it. Both read the same issue records.
 */
function PpeHolders({ productId }) {
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)

  const { data: holders = [], isLoading } = useQuery({
    queryKey: ['ppe-holders', productId],
    queryFn: () => tpvApi.ppe.holders(productId),
    enabled: open,
    staleTime: 0,
  })

  return (
    <section className="rounded-2xl p-4 mt-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <h2 className="font-bold text-xs flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
          <HardHat size={14} style={{ color: INV_ACCENT }} /> Workers using this PPE
        </h2>
        <button onClick={() => setOpen(v => !v)} className="text-[11px] font-bold px-3 py-1.5 rounded-lg"
          style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
          {open ? 'Hide' : 'View workers using this PPE'}
        </button>
      </div>

      {open && (
        isLoading ? (
          <p className="text-xs py-5 text-center" style={{ color: 'var(--text-muted)' }}>Loading holders…</p>
        ) : holders.length === 0 ? (
          <p className="text-xs py-5 text-center" style={{ color: 'var(--text-muted)' }}>Nobody is currently holding this item.</p>
        ) : (
          <div className="overflow-x-auto mt-3">
            <table className="w-full text-xs" style={{ minWidth: 520 }}>
              <thead>
                <tr className="text-left text-[10px] uppercase tracking-wide" style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                  {['Worker', 'Vendor', 'Assigned qty', 'Issue date', 'Status'].map(h => <th key={h} className="px-2 py-2 font-bold">{h}</th>)}
                </tr>
              </thead>
              <tbody>
                {holders.map(h => (
                  <tr key={h.issue_id} className="cursor-pointer hover:opacity-80"
                      onClick={() => navigate(h.worker_url)} style={{ borderBottom: '1px solid var(--border)' }}>
                    <td className="px-2 py-2 font-bold" style={{ color: INV_ACCENT }}>{h.worker || '—'}</td>
                    <td className="px-2 py-2" style={{ color: 'var(--text-body)' }}>{h.vendor || '—'}</td>
                    <td className="px-2 py-2 tabular-nums" style={{ color: 'var(--text-h)' }}>{fmtQty(h.qty)}</td>
                    <td className="px-2 py-2" style={{ color: 'var(--text-muted)' }}>{h.issue_date || '—'}</td>
                    <td className="px-2 py-2">
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-full"
                        style={{ background: 'color-mix(in srgb, #fab219 16%, transparent)', color: '#fab219' }}>Issued</span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}
    </section>
  )
}

const Tile = ({ label, value, color }) => (
  <div className="rounded-2xl p-3" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
    <p className="text-[10px] font-bold uppercase tracking-wide mb-1" style={{ color: 'var(--text-muted)' }}>{label}</p>
    <p className="text-lg font-black tabular-nums" style={{ color }}>{value}</p>
  </div>
)

const Row = ({ label, value }) => (
  <div className="flex items-center justify-between gap-3">
    <dt className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{label}</dt>
    <dd className="text-xs font-semibold truncate" style={{ color: 'var(--text-h)' }}>{value}</dd>
  </div>
)
