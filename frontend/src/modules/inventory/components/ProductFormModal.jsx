import { useState, useEffect, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { X, Check, PackagePlus, IndianRupee, Tag, ImagePlus, Trash2, Plus } from 'lucide-react'
import { inventoryApi, INV_ACCENT, calcSalePrice, calcProfitRatio } from '@/services/inventoryApi'
import Select from '@/components/ui/Select'

/**
 * Create / edit a product. Centered modal (matching the Task form), grouped into
 * the sections the blueprint's Product Master describes.
 *
 * SKU and barcode are left blank by default on purpose — the backend generates
 * both, so nobody has to invent a coding scheme before saving their first item.
 */

const EMPTY = {
  sku: '', sku_code: '', sku_name: '', name: '', description: '', barcode: '',
  parent_id: '', category_id: '', type_id: '', group_id: '', subgroup_id: '',
  brand: '', origin: '', model: '', variant: '', size: '', color: '',
  color_id: '', model_id: '', size_id: '', style_id: '',
  unit_id: '', weight: '', volume: '',
  hsn: '', gst_rate: '', tax_id: '',
  min_stock: 0, max_stock: '', reorder_point: 0, safety_stock: 0,
  track_batch: false, track_serial: false, shelf_life_days: '', warranty_months: '',
  cost_price: '', profit_ratio: '', sale_price: '', status: 'active',
  without_checking_warehouse: false,
  opening_stock: '', opening_warehouse_id: '',
  tags: [], custom_fields: {},
}

export default function ProductFormModal({ open, onClose, product = null, onSaved }) {
  const qc = useQueryClient()
  const editing = Boolean(product)
  const [form, setForm] = useState(EMPTY)
  const [err, setErr] = useState('')

  const { data: categories = [] } = useQuery({ queryKey: ['inv-categories'], queryFn: inventoryApi.categories.list, enabled: open })
  const { data: warehouses = [] } = useQuery({ queryKey: ['inv-warehouses'], queryFn: inventoryApi.warehouses.list, enabled: open })
  // One request behind every dropdown: units, types, groups(+subgroups), taxes, variations.
  const { data: settings } = useQuery({ queryKey: ['inv-settings'], queryFn: inventoryApi.settings.all, enabled: open })
  const { data: allProducts = [] } = useQuery({ queryKey: ['inv-products', {}], queryFn: () => inventoryApi.products.list(), enabled: open })
  // The tenant's custom field definitions drive the "Custom fields" section.
  const { data: customDefs = [] } = useQuery({
    queryKey: ['inv-custom-fields', 'product'],
    queryFn: () => inventoryApi.customFields.list('product'),
    enabled: open,
  })
  // Defaults for min/max/reorder + the profit rule come from Settings.
  const { data: config } = useQuery({ queryKey: ['inv-config'], queryFn: inventoryApi.config.get, enabled: open })

  const [tagDraft, setTagDraft] = useState('')
  const [imgUrl, setImgUrl] = useState(null)
  const [imgErr, setImgErr] = useState('')
  const imgInput = useRef(null)
  // Alternate pack units (Box=12, Carton=144…). Loaded on edit; empty for new.
  const [altUnits, setAltUnits] = useState([])
  // Supplier links (preferred / multiple vendors). Loaded on edit; empty for new.
  const [pv, setPv] = useState([])

  const units = settings?.units || []
  const types = settings?.types || []
  const groups = settings?.groups || []
  const taxes = settings?.taxes || []
  const attrs = settings?.attributes || {}
  const opt = (rows) => rows.map(r => ({ value: r.id, label: r.name }))

  // Sub-groups depend on the chosen group — the blueprint chains these dropdowns.
  const subgroups = groups.find(g => String(g.id) === String(form.group_id))?.subgroups || []

  useEffect(() => {
    if (!open) return
    setErr(''); setTagDraft(''); setImgErr('')
    setForm(editing
      ? {
          ...EMPTY, ...product,
          category_id: product.category_id ?? '', max_stock: product.max_stock ?? '',
          type_id: product.type_id ?? '', group_id: product.group_id ?? '', subgroup_id: product.subgroup_id ?? '',
          unit_id: product.unit_id ?? '', tax_id: product.tax_id ?? '', parent_id: product.parent_id ?? '',
          color_id: product.color_id ?? '', model_id: product.model_id ?? '', size_id: product.size_id ?? '', style_id: product.style_id ?? '',
          tags: product.tags ?? [], custom_fields: product.custom_fields ?? {},
        }
      : {
          ...EMPTY,
          opening_warehouse_id: warehouses.find(w => w.is_default)?.id ?? '',
          // New items start from the tenant's configured defaults (Settings →
          // Minimum/maximum inventory), so nobody retypes the same numbers.
          min_stock: config?.default_min_stock ?? 0,
          max_stock: config?.default_max_stock ?? '',
          reorder_point: config?.default_reorder_point ?? 0,
          profit_ratio: config?.sale_price_rule === 'profit_ratio' ? (config?.default_profit_ratio ?? '') : '',
        })
  }, [open, product, editing]) // eslint-disable-line react-hooks/exhaustive-deps

  // Active vendors for the supplier dropdown.
  const { data: vendorList = [] } = useQuery({ queryKey: ['inv-vendors-active'], queryFn: () => inventoryApi.vendors.list({ status: 'active' }), enabled: open })

  // Load the product's alternate units + supplier links when editing.
  useEffect(() => {
    if (open && editing && product?.id) {
      inventoryApi.products.units(product.id)
        .then(rows => setAltUnits((rows || []).map(u => ({ name: u.name, factor: String(u.factor), barcode: u.barcode || '' }))))
        .catch(() => setAltUnits([]))
      inventoryApi.products.vendors(product.id)
        .then(rows => setPv((rows || []).map(r => ({ vendor_id: String(r.vendor_id), vendor_sku: r.vendor_sku || '', price: r.price ?? '', moq: r.moq ?? '', is_preferred: !!r.is_preferred }))))
        .catch(() => setPv([]))
    } else if (open) {
      setAltUnits([]); setPv([])
    }
  }, [open, editing, product?.id])

  // Load the existing image (it's private, so it comes back as a blob URL).
  useEffect(() => {
    if (!open || !editing || !product?.image_path) { setImgUrl(null); return }
    let revoked = null
    inventoryApi.products.imageBlob(product.id).then(url => { revoked = url; setImgUrl(url) })
    return () => { if (revoked) URL.revokeObjectURL(revoked) }
  }, [open, editing, product?.id, product?.image_path])

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  /* ── Tags ─────────────────────────────────────────────────────── */
  const addTag = (raw) => {
    const next = String(raw || '').split(',').map(t => t.trim()).filter(Boolean)
    if (!next.length) return
    setForm(p => ({ ...p, tags: [...new Set([...(p.tags || []), ...next])].slice(0, 30) }))
    setTagDraft('')
  }
  const removeTag = (t) => setForm(p => ({ ...p, tags: (p.tags || []).filter(x => x !== t) }))

  /* ── Image ────────────────────────────────────────────────────── */
  const uploadImage = useMutation({
    mutationFn: (file) => inventoryApi.products.uploadImage(product.id, file),
    onSuccess: async () => {
      setImgErr('')
      qc.invalidateQueries({ queryKey: ['inv-products'] })
      qc.invalidateQueries({ queryKey: ['inv-product', String(product.id)] })
      setImgUrl(await inventoryApi.products.imageBlob(product.id))
    },
    onError: (e) => setImgErr(e?.message || 'Could not upload that image.'),
  })
  const removeImage = useMutation({
    mutationFn: () => inventoryApi.products.deleteImage(product.id),
    onSuccess: () => {
      setImgUrl(null); setImgErr('')
      qc.invalidateQueries({ queryKey: ['inv-products'] })
      qc.invalidateQueries({ queryKey: ['inv-product', String(product.id)] })
    },
  })
  // Snapshot the FileList before the input resets, or the File ref is gone.
  const pickImage = (list) => {
    const files = Array.from(list || [])
    if (files.length) uploadImage.mutate(files[0])
  }

  /**
   * Pricing is a triangle: cost + profit% → sale, and cost + sale → profit%.
   * Whichever field you touch, the other derived one follows (same rule the
   * backend applies), so the three numbers can never disagree on screen.
   */
  const setCost = (v) => setForm(p => ({
    ...p, cost_price: v,
    ...(p.profit_ratio !== '' ? { sale_price: calcSalePrice(v, p.profit_ratio) }
      : p.sale_price !== '' ? { profit_ratio: calcProfitRatio(v, p.sale_price) } : {}),
  }))
  const setRatio = (v) => setForm(p => ({ ...p, profit_ratio: v, sale_price: calcSalePrice(p.cost_price, v) || p.sale_price }))
  const setSale = (v) => setForm(p => ({ ...p, sale_price: v, profit_ratio: calcProfitRatio(p.cost_price, v) || p.profit_ratio }))

  const save = useMutation({
    mutationFn: async (payload) => {
      const saved = editing
        ? await inventoryApi.products.update(product.id, payload)
        : await inventoryApi.products.create(payload)
      const id = editing ? product.id : saved?.id
      // Persist alternate units + supplier links against the product id.
      if (id) {
        await inventoryApi.products.saveUnits(id, altUnits
          .filter(u => (u.name || '').trim() && Number(u.factor) > 0)
          .map(u => ({ name: u.name.trim(), factor: Number(u.factor), barcode: u.barcode || null })))
        await inventoryApi.products.saveVendors(id, pv
          .filter(r => r.vendor_id)
          .map(r => ({ vendor_id: Number(r.vendor_id), vendor_sku: r.vendor_sku || null, price: r.price === '' ? null : Number(r.price), moq: r.moq === '' ? null : Number(r.moq), is_preferred: !!r.is_preferred })))
      }
      return saved
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inv-products'] })
      qc.invalidateQueries({ queryKey: ['inv-summary'] })
      if (editing) qc.invalidateQueries({ queryKey: ['inv-product', String(product.id)] })
      onSaved?.(); onClose?.()
    },
    onError: (e) => setErr(e?.message || 'Could not save the product.'),
  })

  const submit = (e) => {
    e?.preventDefault?.()
    setErr('')
    const p = { ...form }
    // Blank numeric/optional fields must be dropped, not sent as '' (422).
    for (const k of Object.keys(p)) {
      if (p[k] === '' || p[k] === null) delete p[k]
    }
    if (editing) { delete p.opening_stock; delete p.opening_warehouse_id }
    if (!p.opening_stock) { delete p.opening_stock; delete p.opening_warehouse_id }
    save.mutate(p)
  }

  if (!open) return null

  return (
    <div className="fixed inset-0 z-[55] flex items-start justify-center p-3 sm:p-6 overflow-y-auto"
      style={{ background: 'rgba(15,23,42,0.55)', backdropFilter: 'blur(2px)' }} onClick={onClose}>
      <form onSubmit={submit} onClick={e => e.stopPropagation()}
        className="w-full rounded-2xl overflow-hidden my-2 flex flex-col"
        style={{ maxWidth: 780, background: 'var(--bg-global)', boxShadow: '0 24px 70px rgba(0,0,0,0.45)', maxHeight: '94vh' }}>

        <header className="flex items-center gap-2.5 px-6 py-4 shrink-0"
          style={{ background: `linear-gradient(120deg, ${INV_ACCENT}, #059669)` }}>
          <PackagePlus size={20} style={{ color: '#fff' }} />
          <h2 className="font-bold text-white" style={{ fontSize: 17 }}>{editing ? 'Edit Product' : 'New Product'}</h2>
          <button type="button" onClick={onClose} aria-label="Close" className="ml-auto opacity-90 hover:opacity-100">
            <X size={19} style={{ color: '#fff' }} />
          </button>
        </header>

        <div className="px-6 py-5 space-y-5 overflow-y-auto" style={{ flex: 1 }}>
          <Section title="Identity">
            <Field label="Product name" required>
              <input value={form.name} onChange={e => sf('name', e.target.value)} className={INPUT} style={INPUT_S} autoFocus placeholder="e.g. Wireless Mouse" />
            </Field>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <Field label="SKU" hint={editing ? null : 'Auto-generated if left blank'}>
                <input value={form.sku} onChange={e => sf('sku', e.target.value)} className={INPUT} style={INPUT_S} placeholder="Auto" />
              </Field>
              <Field label="Barcode" hint={editing ? null : 'Auto-generated if left blank'}>
                <input value={form.barcode || ''} onChange={e => sf('barcode', e.target.value)} className={INPUT} style={INPUT_S} placeholder="Auto" />
              </Field>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <Field label="Sku code"><input value={form.sku_code || ''} onChange={e => sf('sku_code', e.target.value)} className={INPUT} style={INPUT_S} /></Field>
              <Field label="Sku name"><input value={form.sku_name || ''} onChange={e => sf('sku_name', e.target.value)} className={INPUT} style={INPUT_S} /></Field>
              <Field label="Unit">
                <Select value={form.unit_id} onChange={v => sf('unit_id', v)} placeholder="Choose a unit" options={opt(units)} />
              </Field>
            </div>
            <Field label="Description">
              <textarea value={form.description || ''} onChange={e => sf('description', e.target.value)} rows={2} className={INPUT} style={{ ...INPUT_S, resize: 'vertical' }} />
            </Field>
          </Section>

          <Section title="Classification" hint="Groups, types and units are managed in Inventory → Settings.">
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <Field label="Commodity type">
                <Select value={form.type_id} onChange={v => sf('type_id', v)} placeholder="None" options={[{ value: '', label: 'None' }, ...opt(types)]} />
              </Field>
              <Field label="Commodity group">
                {/* Changing the group invalidates the chosen sub-group. */}
                <Select value={form.group_id} onChange={v => setForm(p => ({ ...p, group_id: v, subgroup_id: '' }))}
                  placeholder="None" options={[{ value: '', label: 'None' }, ...opt(groups)]} />
              </Field>
              <Field label="Sub group">
                <Select value={form.subgroup_id} onChange={v => sf('subgroup_id', v)}
                  placeholder={form.group_id ? (subgroups.length ? 'None' : 'No sub-groups') : 'Pick a group first'}
                  options={[{ value: '', label: 'None' }, ...opt(subgroups)]} />
              </Field>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <Field label="Category">
                <Select value={form.category_id} onChange={v => sf('category_id', v)} placeholder="Uncategorised"
                  options={[{ value: '', label: 'Uncategorised' }, ...categories.map(c => ({ value: c.id, label: c.name }))]} />
              </Field>
              <Field label="Parent item">
                <Select value={form.parent_id} onChange={v => sf('parent_id', v)} placeholder="None"
                  options={[{ value: '', label: 'None' }, ...allProducts.filter(p => p.id !== product?.id).map(p => ({ value: p.id, label: `${p.sku} · ${p.name}` }))]} />
              </Field>
              <Field label="Brand"><input value={form.brand || ''} onChange={e => sf('brand', e.target.value)} className={INPUT} style={INPUT_S} /></Field>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <Field label="Origin"><input value={form.origin || ''} onChange={e => sf('origin', e.target.value)} className={INPUT} style={INPUT_S} /></Field>
              <Field label="Warranty (months)">
                <input type="number" min="0" value={form.warranty_months ?? ''} onChange={e => sf('warranty_months', e.target.value)} className={INPUT} style={INPUT_S} />
              </Field>
            </div>
          </Section>

          <Section title="Variation" hint="Options come from Settings → Colors / Models / Sizes / Styles.">
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
              <Field label="Style">
                <Select value={form.style_id} onChange={v => sf('style_id', v)} placeholder="None" options={[{ value: '', label: 'None' }, ...opt(attrs.style || [])]} />
              </Field>
              <Field label="Model">
                <Select value={form.model_id} onChange={v => sf('model_id', v)} placeholder="None" options={[{ value: '', label: 'None' }, ...opt(attrs.model || [])]} />
              </Field>
              <Field label="Size">
                <Select value={form.size_id} onChange={v => sf('size_id', v)} placeholder="None" options={[{ value: '', label: 'None' }, ...opt(attrs.size || [])]} />
              </Field>
              <Field label="Color">
                <Select value={form.color_id} onChange={v => sf('color_id', v)} placeholder="None"
                  options={[{ value: '', label: 'None' }, ...(attrs.color || []).map(c => ({ value: c.id, label: c.name, dot: c.value || undefined }))]} />
              </Field>
            </div>
          </Section>

          <Section title="Pricing & tax" hint="Sale price and profit rate calculate from each other as you type.">
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-4">
              <Field label="Purchase price"><Money value={form.cost_price} onChange={setCost} /></Field>
              <Field label="Profit rate %">
                <input type="number" step="0.01" value={form.profit_ratio ?? ''} onChange={e => setRatio(e.target.value)} className={INPUT} style={INPUT_S} placeholder="0" />
              </Field>
              <Field label="Sale price"><Money value={form.sale_price} onChange={setSale} /></Field>
            </div>
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-4">
              <Field label="Tax">
                <Select value={form.tax_id} onChange={v => sf('tax_id', v)} placeholder="None"
                  options={[{ value: '', label: 'None' }, ...taxes.map(t => ({ value: t.id, label: `${t.name}` }))]} />
              </Field>
              <Field label="HSN"><input value={form.hsn || ''} onChange={e => sf('hsn', e.target.value)} className={INPUT} style={INPUT_S} /></Field>
              <Field label="GST %">
                <input type="number" min="0" max="100" step="0.01" value={form.gst_rate ?? ''} onChange={e => sf('gst_rate', e.target.value)} className={INPUT} style={INPUT_S} />
              </Field>
            </div>
          </Section>

          <Section title="Stock planning" hint="Drives the low-stock alerts on the dashboard.">
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
              <Field label="Min stock"><Num value={form.min_stock} onChange={v => sf('min_stock', v)} /></Field>
              <Field label="Max stock"><Num value={form.max_stock} onChange={v => sf('max_stock', v)} /></Field>
              <Field label="Reorder point"><Num value={form.reorder_point} onChange={v => sf('reorder_point', v)} /></Field>
              <Field label="Safety stock"><Num value={form.safety_stock} onChange={v => sf('safety_stock', v)} /></Field>
            </div>
          </Section>

          <Section title="Traceability" hint="Turn these on now; batch & serial screens arrive in the next phase.">
            <div className="flex flex-wrap items-center gap-5">
              <Check2 label="Track batches / lots" checked={!!form.track_batch} onChange={v => sf('track_batch', v)} />
              <Check2 label="Track serial numbers" checked={!!form.track_serial} onChange={v => sf('track_serial', v)} />
              <div style={{ width: 150 }}>
                <Field label="Shelf life (days)">
                  <input type="number" min="0" value={form.shelf_life_days ?? ''} onChange={e => sf('shelf_life_days', e.target.value)} className={INPUT} style={INPUT_S} />
                </Field>
              </div>
            </div>
          </Section>

          <Section title="Stock behaviour">
            <div className="rounded-xl p-3" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
              <Check2 label="Do not update inventory numbers" checked={!!form.without_checking_warehouse}
                onChange={v => sf('without_checking_warehouse', v)} />
              <p className="text-[11px] mt-1.5 ml-6" style={{ color: 'var(--text-muted)' }}>
                Keeps this item out of all stock maths — no balances, no movements, no low-stock alerts.
                Use it for services or anything you don't physically hold.
              </p>
            </div>
          </Section>

          <Section title="Alternate units" hint="Extra packs this item is counted or traded in — Box = 12, Carton = 144. The base unit is 1; ×base is how many base units each pack holds.">
            <div className="space-y-2">
              {altUnits.map((u, i) => (
                <div key={i} className="flex gap-2 items-center">
                  <input value={u.name} onChange={e => setAltUnits(a => a.map((x, j) => j === i ? { ...x, name: e.target.value } : x))} placeholder="Unit (e.g. Box)" className={INPUT} style={{ ...INPUT_S, flex: 2 }} />
                  <input type="number" min="0" step="any" value={u.factor} onChange={e => setAltUnits(a => a.map((x, j) => j === i ? { ...x, factor: e.target.value } : x))} placeholder="× base" className={INPUT} style={{ ...INPUT_S, flex: 1 }} />
                  <input value={u.barcode} onChange={e => setAltUnits(a => a.map((x, j) => j === i ? { ...x, barcode: e.target.value } : x))} placeholder="Barcode (optional)" className={INPUT} style={{ ...INPUT_S, flex: 2 }} />
                  <button type="button" onClick={() => setAltUnits(a => a.filter((_, j) => j !== i))} className="p-1.5 rounded-lg shrink-0" style={{ color: 'var(--color-danger-500)' }} aria-label="Remove unit"><Trash2 size={14} /></button>
                </div>
              ))}
              <button type="button" onClick={() => setAltUnits(a => [...a, { name: '', factor: '', barcode: '' }])}
                className="text-xs font-bold flex items-center gap-1.5" style={{ color: INV_ACCENT }}><Plus size={13} /> Add unit</button>
            </div>
          </Section>

          <Section title="Suppliers" hint="Vendors that supply this item. Mark one as preferred — that's who reordering suggests. Manage vendor records in Inventory → Vendors.">
            <div className="space-y-2">
              {pv.map((r, i) => (
                <div key={i} className="flex gap-2 items-center flex-wrap">
                  <div style={{ flex: '2 1 160px' }}>
                    <Select value={r.vendor_id} onChange={v => setPv(a => a.map((x, j) => j === i ? { ...x, vendor_id: v } : x))} placeholder="Choose vendor"
                      options={vendorList.map(v => ({ value: String(v.id), label: v.name }))} />
                  </div>
                  <input value={r.vendor_sku} onChange={e => setPv(a => a.map((x, j) => j === i ? { ...x, vendor_sku: e.target.value } : x))} placeholder="Vendor SKU" className={INPUT} style={{ ...INPUT_S, flex: '1 1 90px' }} />
                  <input type="number" min="0" value={r.price} onChange={e => setPv(a => a.map((x, j) => j === i ? { ...x, price: e.target.value } : x))} placeholder="Price" className={INPUT} style={{ ...INPUT_S, flex: '1 1 70px' }} />
                  <input type="number" min="0" value={r.moq} onChange={e => setPv(a => a.map((x, j) => j === i ? { ...x, moq: e.target.value } : x))} placeholder="MOQ" className={INPUT} style={{ ...INPUT_S, flex: '1 1 60px' }} />
                  <button type="button" onClick={() => setPv(a => a.map((x, j) => ({ ...x, is_preferred: j === i })))}
                    className="text-[11px] font-bold px-2 py-1.5 rounded-lg shrink-0"
                    title="Set as preferred vendor"
                    style={r.is_preferred ? { background: `color-mix(in srgb, ${INV_ACCENT} 16%, transparent)`, color: INV_ACCENT } : { border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                    ★ {r.is_preferred ? 'Preferred' : 'Prefer'}
                  </button>
                  <button type="button" onClick={() => setPv(a => a.filter((_, j) => j !== i))} className="p-1.5 rounded-lg shrink-0" style={{ color: 'var(--color-danger-500)' }} aria-label="Remove vendor"><Trash2 size={14} /></button>
                </div>
              ))}
              <button type="button" onClick={() => setPv(a => [...a, { vendor_id: '', vendor_sku: '', price: '', moq: '', is_preferred: a.length === 0 }])}
                className="text-xs font-bold flex items-center gap-1.5" style={{ color: INV_ACCENT }}><Plus size={13} /> Add supplier</button>
            </div>
          </Section>

          {/* Tags — freeform labels the Items filter groups by. */}
          <Section title="Tags" hint="Type a tag and press Enter. Used by the Items page's Tags filter.">
            <div className="flex flex-wrap gap-1.5 mb-2">
              {(form.tags || []).map(t => (
                <span key={t} className="inline-flex items-center gap-1 text-[11px] font-bold px-2 py-1 rounded-lg"
                  style={{ background: `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)`, color: INV_ACCENT }}>
                  <Tag size={10} />{t}
                  <button type="button" onClick={() => removeTag(t)} aria-label={`Remove ${t}`} className="hover:opacity-60"><X size={10} /></button>
                </span>
              ))}
              {!(form.tags || []).length && <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>No tags yet.</span>}
            </div>
            <input
              value={tagDraft}
              onChange={e => setTagDraft(e.target.value)}
              onKeyDown={e => { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); addTag(tagDraft) } }}
              onBlur={() => addTag(tagDraft)}
              placeholder="e.g. fragile, imported"
              className={INPUT} style={INPUT_S} />
          </Section>

          {/* Image — upload needs an id, so it's offered once the item exists. */}
          <Section title="Image" hint={editing ? 'JPG/PNG up to 5 MB.' : 'Save the item first, then reopen it to attach an image.'}>
            {editing ? (
              <div className="flex items-center gap-3">
                <div className="rounded-xl flex items-center justify-center overflow-hidden shrink-0"
                  style={{ width: 84, height: 84, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                  {imgUrl
                    ? <img src={imgUrl} alt={form.name} style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                    : <ImagePlus size={22} style={{ color: 'var(--text-muted)' }} />}
                </div>
                <div className="flex flex-col gap-2">
                  <button type="button" onClick={() => imgInput.current?.click()} disabled={uploadImage.isPending}
                    className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-50"
                    style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-body)' }}>
                    <ImagePlus size={13} /> {uploadImage.isPending ? 'Uploading…' : imgUrl ? 'Replace image' : 'Upload image'}
                  </button>
                  {imgUrl && (
                    <button type="button" onClick={() => removeImage.mutate()}
                      className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
                      style={{ border: '1px solid var(--border)', color: 'var(--color-danger-500)' }}>
                      <Trash2 size={13} /> Remove
                    </button>
                  )}
                  {imgErr && <span className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>{imgErr}</span>}
                </div>
                <input ref={imgInput} type="file" accept="image/*" hidden
                  onChange={e => { pickImage(e.target.files); e.target.value = '' }} />
              </div>
            ) : (
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Available after the item is created.</p>
            )}
          </Section>

          {/* Custom fields — whatever this tenant defined in Settings. */}
          {customDefs.length > 0 && (
            <Section title="Custom fields" hint="Defined in Inventory → Settings → Custom fields.">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {customDefs.map(def => {
                  const val = form.custom_fields?.[def.key] ?? ''
                  const setVal = (v) => setForm(p => ({ ...p, custom_fields: { ...(p.custom_fields || {}), [def.key]: v } }))
                  return (
                    <Field key={def.id} label={def.label + (def.required ? ' *' : '')}>
                      {def.type === 'select' ? (
                        <Select value={val} onChange={setVal} placeholder="— none —"
                          options={[{ value: '', label: '— none —' }, ...(def.options || []).map(o => ({ value: o, label: o }))]} />
                      ) : def.type === 'checkbox' ? (
                        <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-body)' }}>
                          <input type="checkbox" checked={Boolean(val)} onChange={e => setVal(e.target.checked)} style={{ accentColor: INV_ACCENT }} />
                          Yes
                        </label>
                      ) : (
                        <input type={def.type === 'number' ? 'number' : def.type === 'date' ? 'date' : 'text'}
                          value={val} onChange={e => setVal(e.target.value)} className={INPUT} style={INPUT_S} />
                      )}
                    </Field>
                  )
                })}
              </div>
            </Section>
          )}

          {!editing && !form.without_checking_warehouse && (
            <Section title="Opening stock" hint="Optional — recorded as a real movement, so day one has an audit trail too.">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <Field label="Quantity on hand"><Num value={form.opening_stock} onChange={v => sf('opening_stock', v)} /></Field>
                <Field label="At warehouse">
                  <Select value={form.opening_warehouse_id} onChange={v => sf('opening_warehouse_id', v)}
                    placeholder={warehouses.length ? 'Choose a warehouse' : 'Create a warehouse first'}
                    options={warehouses.map(w => ({ value: w.id, label: w.name }))} />
                </Field>
              </div>
            </Section>
          )}

          {err && (
            <p className="text-sm px-3 py-2 rounded-lg"
              style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>
          )}
        </div>

        <footer className="flex items-center justify-end gap-2 px-6 py-4 shrink-0" style={{ borderTop: '1px solid var(--border)', background: 'var(--bg-card)' }}>
          <button type="button" onClick={onClose} className="text-sm font-semibold px-4 py-2.5 rounded-xl"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
          <button type="submit" disabled={!form.name.trim() || save.isPending}
            className="flex items-center gap-1.5 text-sm font-bold px-5 py-2.5 rounded-xl disabled:opacity-40"
            style={{ background: INV_ACCENT, color: '#fff' }}>
            <Check size={16} /> {save.isPending ? 'Saving…' : editing ? 'Save changes' : 'Create product'}
          </button>
        </footer>
      </form>
    </div>
  )
}

/* ── Bits ─────────────────────────────────────────────────────── */

const INPUT = 'w-full rounded-xl outline-none'
const INPUT_S = { padding: '10px 12px', fontSize: 13.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }

function Section({ title, hint, children }) {
  return (
    <section className="space-y-4">
      <div>
        <h3 className="text-xs font-black uppercase tracking-wide" style={{ color: INV_ACCENT }}>{title}</h3>
        {hint && <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{hint}</p>}
      </div>
      {children}
    </section>
  )
}

function Field({ label, required, hint, children }) {
  return (
    <label className="block">
      <span className="text-xs font-bold block mb-1.5 capitalize" style={{ color: 'var(--text-body)' }}>
        {label}{required && <span style={{ color: 'var(--color-danger-500)' }}> *</span>}
      </span>
      {children}
      {hint && <span className="block text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>{hint}</span>}
    </label>
  )
}

const Num = ({ value, onChange }) => (
  <input type="number" min="0" step="0.001" value={value ?? ''} onChange={e => onChange(e.target.value)} className={INPUT} style={INPUT_S} />
)

const Money = ({ value, onChange }) => (
  <div className="relative">
    <IndianRupee size={13} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
    <input type="number" min="0" step="0.01" value={value ?? ''} onChange={e => onChange(e.target.value)}
      className={INPUT} style={{ ...INPUT_S, paddingLeft: 30 }} placeholder="0.00" />
  </div>
)

function Check2({ label, checked, onChange }) {
  return (
    <label className="flex items-center gap-2 cursor-pointer select-none">
      <input type="checkbox" checked={checked} onChange={e => onChange(e.target.checked)}
        style={{ accentColor: INV_ACCENT, width: 16, height: 16, cursor: 'pointer' }} />
      <span className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{label}</span>
    </label>
  )
}
