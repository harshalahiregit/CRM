import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { X, Check, ArrowLeftRight, ClipboardCheck } from 'lucide-react'
import { inventoryApi, INV_ACCENT, MOVEMENT_TYPES, fmtQty } from '@/services/inventoryApi'
import Select from '@/components/ui/Select'

/**
 * Two jobs behind one modal, because they're the same mental action ("fix the
 * number") but different maths:
 *   mode="move"   → add/remove/transfer a QUANTITY (delta).
 *   mode="adjust" → state the COUNTED total; the backend records the difference.
 */
export default function StockMoveModal({ open, onClose, mode = 'move', product, onDone }) {
  const qc = useQueryClient()
  const adjusting = mode === 'adjust'
  const [form, setForm] = useState({})
  const [err, setErr] = useState('')

  const { data: warehouses = [] } = useQuery({ queryKey: ['inv-warehouses'], queryFn: inventoryApi.warehouses.list, enabled: open })

  useEffect(() => {
    if (!open) return
    setErr('')
    const def = warehouses.find(w => w.is_default)?.id ?? warehouses[0]?.id ?? ''
    setForm({
      type: 'receive', quantity: '', warehouse_id: def,
      from_warehouse_id: def, to_warehouse_id: '', reason: '', notes: '',
    })
  }, [open, warehouses.length]) // eslint-disable-line react-hooks/exhaustive-deps

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))
  const isTransfer = form.type === 'transfer'

  // Current on-hand at the chosen site — shown so the person can sanity-check.
  const { data: levelData } = useQuery({
    queryKey: ['inv-product-levels', product?.id], queryFn: () => inventoryApi.products.levels(product.id), enabled: open && !!product?.id,
  })
  const atSite = (levelData?.levels || []).find(l => String(l.warehouse_id) === String(form.warehouse_id))
  const onHandHere = atSite ? fmtQty(atSite.quantity) : '0'

  const save = useMutation({
    mutationFn: (payload) => adjusting ? inventoryApi.stock.adjust(payload) : inventoryApi.stock.move(payload),
    onSuccess: () => {
      for (const k of ['inv-products', 'inv-summary', 'inv-low-stock', 'inv-product', 'inv-product-levels', 'inv-product-history']) {
        qc.invalidateQueries({ queryKey: [k] })
      }
      onDone?.(); onClose?.()
    },
    onError: (e) => setErr(e?.message || 'Could not update stock.'),
  })

  const submit = (e) => {
    e.preventDefault()
    setErr('')
    if (adjusting) {
      return save.mutate({
        product_id: product.id, warehouse_id: Number(form.warehouse_id),
        quantity: Number(form.quantity), reason: form.reason || undefined,
      })
    }
    const p = {
      product_id: product.id, type: form.type, quantity: Number(form.quantity),
      reason: form.reason || undefined, notes: form.notes || undefined,
    }
    if (isTransfer) {
      p.from_warehouse_id = Number(form.from_warehouse_id)
      p.to_warehouse_id = Number(form.to_warehouse_id)
    } else {
      p.warehouse_id = Number(form.warehouse_id)
    }
    save.mutate(p)
  }

  if (!open) return null

  const valid = Number(form.quantity) >= 0 && form.quantity !== ''
    && (adjusting ? form.warehouse_id : (isTransfer ? form.from_warehouse_id && form.to_warehouse_id && form.from_warehouse_id !== form.to_warehouse_id : form.warehouse_id))

  return (
    <div className="fixed inset-0 z-[55] flex items-start justify-center p-4 overflow-y-auto"
      style={{ background: 'rgba(15,23,42,0.55)', backdropFilter: 'blur(2px)' }} onClick={onClose}>
      <form onSubmit={submit} onClick={e => e.stopPropagation()}
        className="w-full rounded-2xl overflow-hidden my-8"
        style={{ maxWidth: 520, background: 'var(--bg-global)', boxShadow: '0 24px 70px rgba(0,0,0,0.45)' }}>

        <header className="flex items-center gap-2.5 px-5 py-4"
          style={{ background: `linear-gradient(120deg, ${adjusting ? '#8b5cf6' : INV_ACCENT}, ${adjusting ? '#6d28d9' : '#059669'})` }}>
          {adjusting ? <ClipboardCheck size={18} style={{ color: '#fff' }} /> : <ArrowLeftRight size={18} style={{ color: '#fff' }} />}
          <h2 className="font-bold text-white" style={{ fontSize: 15 }}>
            {adjusting ? 'Adjust counted stock' : 'Record stock movement'}
          </h2>
          <button type="button" onClick={onClose} aria-label="Close" className="ml-auto opacity-90 hover:opacity-100">
            <X size={18} style={{ color: '#fff' }} />
          </button>
        </header>

        <div className="p-5 space-y-4">
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            <span className="font-bold" style={{ color: 'var(--text-h)' }}>{product?.name}</span>
            {' '}· {product?.sku}
          </p>

          {!adjusting && (
            <Field label="What happened?">
              <Select value={form.type} onChange={v => sf('type', v)}
                options={MOVEMENT_TYPES.map(m => ({ value: m.value, label: m.label, dot: m.color }))} />
            </Field>
          )}

          {isTransfer ? (
            <div className="grid grid-cols-2 gap-3">
              <Field label="From">
                <Select value={form.from_warehouse_id} onChange={v => sf('from_warehouse_id', v)}
                  options={warehouses.map(w => ({ value: w.id, label: w.name }))} />
              </Field>
              <Field label="To">
                <Select value={form.to_warehouse_id} onChange={v => sf('to_warehouse_id', v)} placeholder="Choose destination"
                  options={warehouses.filter(w => String(w.id) !== String(form.from_warehouse_id)).map(w => ({ value: w.id, label: w.name }))} />
              </Field>
            </div>
          ) : (
            <Field label="Warehouse" hint={`On hand here: ${onHandHere} ${product?.base_unit || ''}`}>
              <Select value={form.warehouse_id} onChange={v => sf('warehouse_id', v)}
                options={warehouses.map(w => ({ value: w.id, label: w.name }))} />
            </Field>
          )}

          <Field label={adjusting ? 'Counted quantity (the new total)' : 'Quantity'}>
            <input type="number" min="0" step="0.001" value={form.quantity} onChange={e => sf('quantity', e.target.value)}
              className="w-full rounded-xl outline-none" autoFocus
              style={{ padding: '10px 12px', fontSize: 15, fontWeight: 700, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
          </Field>

          {adjusting && (
            <p className="text-[11px] px-3 py-2 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
              Enter what you actually counted. The difference from {onHandHere} is written to the ledger as an adjustment — nothing is overwritten silently.
            </p>
          )}

          <Field label="Reason">
            <input value={form.reason} onChange={e => sf('reason', e.target.value)} placeholder={adjusting ? 'e.g. Cycle count' : 'e.g. Sold to walk-in'}
              className="w-full rounded-xl outline-none"
              style={{ padding: '10px 12px', fontSize: 13.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
          </Field>

          {err && (
            <p className="text-xs px-3 py-2 rounded-lg"
              style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>
          )}
        </div>

        <footer className="flex items-center justify-end gap-2 px-5 py-4" style={{ borderTop: '1px solid var(--border)', background: 'var(--bg-card)' }}>
          <button type="button" onClick={onClose} className="text-sm font-semibold px-4 py-2.5 rounded-xl"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
          <button type="submit" disabled={!valid || save.isPending}
            className="flex items-center gap-1.5 text-sm font-bold px-5 py-2.5 rounded-xl disabled:opacity-40"
            style={{ background: adjusting ? '#8b5cf6' : INV_ACCENT, color: '#fff' }}>
            <Check size={16} /> {save.isPending ? 'Saving…' : adjusting ? 'Apply count' : 'Record'}
          </button>
        </footer>
      </form>
    </div>
  )
}

function Field({ label, hint, children }) {
  return (
    <label className="block">
      <span className="text-xs font-bold block mb-1.5" style={{ color: 'var(--text-body)' }}>{label}</span>
      {children}
      {hint && <span className="block text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>{hint}</span>}
    </label>
  )
}
