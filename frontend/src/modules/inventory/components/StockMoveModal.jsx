import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { X, Check, ArrowLeftRight, ClipboardCheck, MapPin, Camera, Loader2 } from 'lucide-react'
import { inventoryApi, INV_ACCENT, MOVEMENT_TYPES, fmtQty } from '@/services/inventoryApi'
import Select from '@/components/ui/Select'
import { useDiscardGuard } from '@/lib/confirmClose'

/**
 * Two jobs behind one modal, because they're the same mental action ("fix the
 * number") but different maths:
 *   mode="move"   → add/remove/transfer a QUANTITY (delta).
 *   mode="adjust" → state the COUNTED total; the backend records the difference.
 */
export default function StockMoveModal({ open, onClose, mode = 'move', product, onDone }) {
  const { guard, dialog } = useDiscardGuard()
  const qc = useQueryClient()
  const adjusting = mode === 'adjust'
  const [form, setForm] = useState({})
  const [err, setErr] = useState('')
  const [geo, setGeo] = useState(null)          // { lat, lng, address }
  const [geoBusy, setGeoBusy] = useState(false)
  const [photo, setPhoto] = useState(null)      // base64 data URL

  const isDirty = () => Boolean(form.quantity || form.reason || form.notes || photo)
  const handleClose = () => guard(onClose, isDirty())

  const { data: warehouses = [] } = useQuery({ queryKey: ['inv-warehouses'], queryFn: inventoryApi.warehouses.list, enabled: open })

  useEffect(() => {
    if (!open) return
    setErr(''); setGeo(null); setPhoto(null); setGeoBusy(false)
    const def = warehouses.find(w => w.is_default)?.id ?? warehouses[0]?.id ?? ''
    setForm({
      type: 'receive', quantity: '', unit: '', warehouse_id: def,
      from_warehouse_id: def, to_warehouse_id: '', reason: '', notes: '',
    })
  }, [open, warehouses.length]) // eslint-disable-line react-hooks/exhaustive-deps

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))
  const isTransfer = form.type === 'transfer'

  // The site the move physically happens at drives the compliance switches.
  const activeWhId = isTransfer ? form.from_warehouse_id : form.warehouse_id
  const activeWh = warehouses.find(w => String(w.id) === String(activeWhId))
  const needsGps = !adjusting && activeWh?.require_move_gps
  const needsPhoto = !adjusting && activeWh?.require_move_photo

  const captureLocation = () => {
    if (!navigator.geolocation) { setErr('This device has no location support.'); return }
    setGeoBusy(true)
    navigator.geolocation.getCurrentPosition(
      (pos) => { setGeo({ lat: +pos.coords.latitude.toFixed(7), lng: +pos.coords.longitude.toFixed(7), address: '' }); setGeoBusy(false) },
      () => { setErr('Could not get your location — allow location access and retry.'); setGeoBusy(false) },
      { enableHighAccuracy: true, timeout: 10000 },
    )
  }

  const pickPhoto = (e) => {
    const file = e.target.files?.[0]
    if (!file) return
    if (file.size > 8 * 1024 * 1024) { setErr('Photo is too large (max 8 MB).'); return }
    const reader = new FileReader()
    reader.onload = () => setPhoto(reader.result)
    reader.readAsDataURL(file)
  }

  // Current on-hand at the chosen site — shown so the person can sanity-check.
  const { data: levelData } = useQuery({
    queryKey: ['inv-product-levels', product?.id], queryFn: () => inventoryApi.products.levels(product.id), enabled: open && !!product?.id,
  })
  const atSite = (levelData?.levels || []).find(l => String(l.warehouse_id) === String(form.warehouse_id))
  const onHandHere = atSite ? fmtQty(atSite.quantity) : '0'

  // Alternate units for this item — lets qty be entered in Box/Carton etc.
  const { data: unitList = [] } = useQuery({
    queryKey: ['inv-product-units', product?.id], queryFn: () => inventoryApi.products.units(product.id), enabled: open && !!product?.id,
  })
  const factor = form.unit ? Number(unitList.find(u => u.name === form.unit)?.factor || 1) : 1
  const baseQty = form.quantity === '' ? '' : +(Number(form.quantity) * factor).toFixed(3)

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
        quantity: Number(baseQty), reason: form.reason || undefined,
      })
    }
    const p = {
      product_id: product.id, type: form.type, quantity: Number(baseQty),
      reason: form.reason || undefined, notes: form.notes || undefined,
    }
    if (geo) { p.gps_lat = geo.lat; p.gps_lng = geo.lng; if (geo.address) p.geo_address = geo.address }
    if (photo) p.photo = photo
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
    && (!needsGps || geo) && (!needsPhoto || photo)

  return (
    <>
      <div className="fixed inset-0 z-[55] flex items-start justify-center p-4 overflow-y-auto"
        style={{ background: 'rgba(15,23,42,0.55)', backdropFilter: 'blur(2px)' }}>
        <form onSubmit={submit} onClick={e => e.stopPropagation()}
          className="w-full rounded-2xl overflow-hidden my-8"
          style={{ maxWidth: 520, background: 'var(--bg-global)', boxShadow: '0 24px 70px rgba(0,0,0,0.45)' }}>

          <header className="flex items-center gap-2.5 px-5 py-4"
            style={{ background: `linear-gradient(120deg, ${adjusting ? '#8b5cf6' : INV_ACCENT}, ${adjusting ? '#6d28d9' : '#059669'})` }}>
            {adjusting ? <ClipboardCheck size={18} style={{ color: '#fff' }} /> : <ArrowLeftRight size={18} style={{ color: '#fff' }} />}
            <h2 className="font-bold text-white" style={{ fontSize: 15 }}>
              {adjusting ? 'Adjust counted stock' : 'Record stock movement'}
            </h2>
            <button type="button" onClick={handleClose} aria-label="Close" className="ml-auto opacity-90 hover:opacity-100">
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

          <Field label={adjusting ? 'Counted quantity (the new total)' : 'Quantity'}
            hint={form.unit && form.quantity !== '' ? `= ${baseQty} ${product?.base_unit || 'base'}` : null}>
            <div className="flex gap-2">
              <input type="number" min="0" step="0.001" value={form.quantity} onChange={e => sf('quantity', e.target.value)}
                className="flex-1 rounded-xl outline-none" autoFocus
                style={{ padding: '10px 12px', fontSize: 15, fontWeight: 700, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
              {unitList.length > 0 && (
                <div style={{ minWidth: 132 }}>
                  <Select value={form.unit} onChange={v => sf('unit', v)}
                    options={[{ value: '', label: product?.base_unit || 'Base unit' }, ...unitList.map(u => ({ value: u.name, label: `${u.name} (×${u.factor})` }))]} />
                </div>
              )}
            </div>
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

          {/* Per-movement provenance. Always available; a site can *require* either
              one, in which case it's marked and the submit button waits for it. */}
          {!adjusting && (
            <div className="flex flex-wrap gap-2">
              <button type="button" onClick={captureLocation} disabled={geoBusy}
                className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
                style={{ border: `1px solid ${geo ? INV_ACCENT : (needsGps ? 'var(--color-danger-500)' : 'var(--border)')}`, color: geo ? INV_ACCENT : 'var(--text-body)' }}>
                {geoBusy ? <Loader2 size={13} className="animate-spin" /> : <MapPin size={13} />}
                {geo ? `Location set (${geo.lat}, ${geo.lng})` : (needsGps ? 'Location required — capture' : 'Capture location')}
              </button>

              <label className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl cursor-pointer"
                style={{ border: `1px solid ${photo ? INV_ACCENT : (needsPhoto ? 'var(--color-danger-500)' : 'var(--border)')}`, color: photo ? INV_ACCENT : 'var(--text-body)' }}>
                <Camera size={13} /> {photo ? 'Photo attached' : (needsPhoto ? 'Photo required — add' : 'Add photo')}
                <input type="file" accept="image/*" capture="environment" onChange={pickPhoto} style={{ display: 'none' }} />
              </label>

              {photo && (
                <div className="flex items-center gap-2">
                  <img src={photo} alt="move" style={{ height: 34, width: 34, objectFit: 'cover', borderRadius: 8 }} />
                  <button type="button" onClick={() => setPhoto(null)} className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>remove</button>
                </div>
              )}
            </div>
          )}

          {err && (
            <p className="text-xs px-3 py-2 rounded-lg"
              style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>
          )}
        </div>

        <footer className="flex items-center justify-end gap-2 px-5 py-4" style={{ borderTop: '1px solid var(--border)', background: 'var(--bg-card)' }}>
          <button type="button" onClick={handleClose} className="text-sm font-semibold px-4 py-2.5 rounded-xl"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
          <button type="submit" disabled={!valid || save.isPending}
            className="flex items-center gap-1.5 text-sm font-bold px-5 py-2.5 rounded-xl disabled:opacity-40"
            style={{ background: adjusting ? '#8b5cf6' : INV_ACCENT, color: '#fff' }}>
            <Check size={16} /> {save.isPending ? 'Saving…' : adjusting ? 'Apply count' : 'Record'}
          </button>
        </footer>
      </form>
    </div>
    {dialog}
    </>
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
