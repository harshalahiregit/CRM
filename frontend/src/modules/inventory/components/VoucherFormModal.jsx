import { useState, useEffect, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { X, Check, Plus, Trash2, FileText, Send } from 'lucide-react'
import { inventoryApi, VOUCHER_TYPES, fmtQty, money } from '@/services/inventoryApi'
import Select from '@/components/ui/Select'
import { useDiscardGuard } from '@/lib/confirmClose'

/**
 * The blueprint's voucher form: a "General infor" header over a repeatable
 * line-item grid, with header totals derived from the lines.
 *
 * Save leaves it a DRAFT (moves nothing). "Save & post" is the button that
 * actually touches stock — the split is deliberate, so a half-typed document
 * can never move a warehouse.
 */

const today = () => new Date().toISOString().split('T')[0]
const emptyLine = () => ({
  product_id: '', warehouse_id: '', from_warehouse_id: '', to_warehouse_id: '',
  quantity: '', unit_price: '', tax_rate: '', discount: '', lot_number: '', expiry_date: '', note: '',
})

export default function VoucherFormModal({ open, onClose, type, voucher = null, onSaved }) {
  const { guard, dialog } = useDiscardGuard()
  const qc = useQueryClient()
  const cfg = VOUCHER_TYPES[type]
  const editing = Boolean(voucher)
  const readOnly = editing && voucher.status !== 'draft'

  const [form, setForm] = useState({})
  const [lines, setLines] = useState([emptyLine()])
  const [err, setErr] = useState('')

  const isDirty = () => {
    if (readOnly) return false
    if (lines.some(l => l.product_id || l.quantity || l.unit_price || l.note)) return true
    if (form.description?.trim() || form.reason?.trim() || form.customer_name?.trim() || form.supplier_name?.trim()) return true
    return false
  }

  const handleClose = () => guard(onClose, isDirty())

  const { data: warehouses = [] } = useQuery({ queryKey: ['inv-warehouses'], queryFn: inventoryApi.warehouses.list, enabled: open })
  const { data: products = [] } = useQuery({ queryKey: ['inv-products', {}], queryFn: () => inventoryApi.products.list(), enabled: open })
  const { data: staff = [] } = useQuery({ queryKey: ['inv-staff'], queryFn: inventoryApi.staff, enabled: open })
  // Projects a receipt/delivery can be booked against. Inventory only reads the
  // list; if the Projects module isn't reachable this degrades to "None".
  const { data: projects = [] } = useQuery({
    queryKey: ['inv-projects-lite'],
    queryFn: () => import('@/services/projectApi').then(m => m.projectApi.list()).catch(() => []),
    enabled: open,
  })
  // Items excluded from stock math can't go on a stock document (backend agrees).
  const stockable = useMemo(() => products.filter(p => !p.without_checking_warehouse), [products])
  const byId = useMemo(() => Object.fromEntries(products.map(p => [String(p.id), p])), [products])

  useEffect(() => {
    if (!open) return
    setErr('')
    const def = warehouses.find(w => w.is_default)?.id ?? warehouses[0]?.id ?? ''
    if (editing) {
      setForm({ ...voucher, warehouse_id: voucher.warehouse_id ?? '', staff_id: voucher.staff_id ?? '' })
      setLines((voucher.items || []).map(i => ({
        product_id: i.product_id, warehouse_id: i.warehouse_id ?? '',
        from_warehouse_id: i.from_warehouse_id ?? '', to_warehouse_id: i.to_warehouse_id ?? '',
        quantity: i.quantity, unit_price: i.unit_price, tax_rate: i.tax_rate, discount: i.discount ?? '',
        lot_number: i.lot_number ?? '', expiry_date: i.expiry_date ? String(i.expiry_date).split('T')[0] : '', note: i.note ?? '',
      })) || [emptyLine()])
    } else {
      setForm({
        date_add: today(), date_c: today(), warehouse_id: cfg.needsWarehouse ? def : '',
        adjustment_type: type === 'loss_adjustment' ? 'loss' : undefined,
        description: '', reason: '',
      })
      setLines([{ ...emptyLine(), from_warehouse_id: cfg.perLineTransfer ? def : '' }])
    }
  }, [open, voucher, editing]) // eslint-disable-line react-hooks/exhaustive-deps

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))
  const sl = (i, k, v) => setLines(rows => rows.map((r, j) => j === i ? { ...r, [k]: v } : r))

  // Totals mirror the server's rule exactly: amount = qty x price, discount comes
  // off the goods before tax, and "value of inventory" costs the stock moved at
  // each item's cost price (which is why it differs from the money total).
  const totals = useMemo(() => {
    const amt = (l) => (Number(l.quantity) || 0) * (Number(l.unit_price) || 0)
    const goods = lines.reduce((s, l) => s + amt(l), 0)
    const discount = lines.reduce((s, l) => s + (Number(l.discount) || 0), 0)
    const tax = lines.reduce((s, l) => {
      const share = goods > 0 ? (amt(l) / goods) * discount : 0
      return s + Math.max(0, amt(l) - share) * (Number(l.tax_rate) || 0) / 100
    }, 0)
    const inventoryValue = lines.reduce(
      (s, l) => s + (Number(l.quantity) || 0) * (Number(byId[String(l.product_id)]?.cost_price) || 0), 0)
    return { goods, discount, tax, total: Math.max(0, goods - discount) + tax, inventoryValue }
  }, [lines, byId])

  const save = useMutation({
    mutationFn: async ({ post }) => {
      const payload = {
        ...form,
        items: lines.filter(l => l.product_id && Number(l.quantity) >= 0).map(l => ({
          product_id: Number(l.product_id),
          quantity: Number(l.quantity) || 0,
          unit_price: Number(l.unit_price) || 0,
          tax_rate: Number(l.tax_rate) || 0,
          discount: Number(l.discount) || 0,
          warehouse_id: l.warehouse_id || undefined,
          from_warehouse_id: l.from_warehouse_id || undefined,
          to_warehouse_id: l.to_warehouse_id || undefined,
          lot_number: l.lot_number || undefined,
          expiry_date: l.expiry_date || undefined,
          note: l.note || undefined,
        })),
      }
      const saved = editing
        ? await inventoryApi.vouchers.update(type, voucher.id, payload)
        : await inventoryApi.vouchers.create(type, payload)
      if (post) await inventoryApi.vouchers.post(type, saved.id)
      return saved
    },
    onSuccess: () => {
      for (const k of ['inv-vouchers', 'inv-products', 'inv-summary', 'inv-low-stock', 'inv-product', 'inv-product-levels', 'inv-product-history']) {
        qc.invalidateQueries({ queryKey: [k] })
      }
      onSaved?.(); onClose?.()
    },
    onError: (e) => setErr(e?.message || 'Could not save the voucher.'),
  })

  if (!open) return null

  const validLines = lines.filter(l => l.product_id && Number(l.quantity) > 0)
  const canSave = validLines.length > 0 && (!cfg.needsWarehouse || form.warehouse_id)
    && (!cfg.perLineTransfer || validLines.every(l => l.from_warehouse_id && l.to_warehouse_id && l.from_warehouse_id !== l.to_warehouse_id))

  const whOpts = warehouses.map(w => ({ value: w.id, label: w.name }))
  const isRecount = type === 'loss_adjustment' && form.adjustment_type === 'adjustment'

  return (
    <>
      <div className="fixed inset-0 z-[55] flex items-start justify-center p-3 sm:p-6 overflow-y-auto"
        style={{ background: 'rgba(15,23,42,0.55)', backdropFilter: 'blur(2px)' }} onClick={handleClose}>
        <div onClick={e => e.stopPropagation()} className="w-full rounded-2xl overflow-hidden my-2 flex flex-col"
          style={{ maxWidth: 960, background: 'var(--bg-global)', boxShadow: '0 24px 70px rgba(0,0,0,0.45)', maxHeight: '94vh' }}>

          <header className="flex items-center gap-2.5 px-6 py-4 shrink-0" style={{ background: `linear-gradient(120deg, ${cfg.accent}, color-mix(in srgb, ${cfg.accent} 60%, #000))` }}>
            <FileText size={19} style={{ color: '#fff' }} />
            <div className="min-w-0">
              <h2 className="font-bold text-white" style={{ fontSize: 16 }}>
                {editing ? voucher.code : `New ${cfg.short.toLowerCase()}`}
              </h2>
              <p className="text-[11px] text-white opacity-80">{cfg.label}</p>
            </div>
            {readOnly && (
              <span className="text-[10px] font-black px-2 py-1 rounded-lg" style={{ background: 'rgba(255,255,255,0.25)', color: '#fff' }}>
                {voucher.status.toUpperCase()} · read only
              </span>
            )}
            <button onClick={handleClose} aria-label="Close" className="ml-auto opacity-90 hover:opacity-100">
              <X size={19} style={{ color: '#fff' }} />
            </button>
          </header>

        <div className="px-6 py-5 space-y-5 overflow-y-auto" style={{ flex: 1, opacity: readOnly ? 0.85 : 1, pointerEvents: readOnly ? 'none' : 'auto' }}>
          {/* ── General infor ── */}
          <Section title="General infor">
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <F label="Accounting date"><input type="date" value={form.date_c || ''} onChange={e => sf('date_c', e.target.value)} className={I} style={IS} /></F>
              <F label="Voucher day"><input type="date" value={form.date_add || ''} onChange={e => sf('date_add', e.target.value)} className={I} style={IS} /></F>
              {cfg.needsWarehouse && (
                <F label={`${cfg.verb} warehouse`} required>
                  <Select value={form.warehouse_id} onChange={v => sf('warehouse_id', v)} placeholder="Choose" options={whOpts} />
                </F>
              )}
            </div>

            {type === 'receipt' && (
              <>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <F label="Supplier"><input value={form.supplier_name || ''} onChange={e => sf('supplier_name', e.target.value)} className={I} style={IS} /></F>
                  <F label="Deliver name"><input value={form.deliver_name || ''} onChange={e => sf('deliver_name', e.target.value)} className={I} style={IS} /></F>
                  <F label="Invoice no"><input value={form.invoice_no || ''} onChange={e => sf('invoice_no', e.target.value)} className={I} style={IS} /></F>
                </div>
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                  <F label="Type">
                    <Select value={form.expense_type || ''} onChange={v => sf('expense_type', v)} placeholder="None"
                      options={[{ value: '', label: 'None' }, { value: 'CAPEX', label: 'CAPEX' }, { value: 'OPEX', label: 'OPEX' }]} />
                  </F>
                  <F label="Department"><input value={form.department || ''} onChange={e => sf('department', e.target.value)} className={I} style={IS} /></F>
                  <F label="Requester"><input value={form.requester || ''} onChange={e => sf('requester', e.target.value)} className={I} style={IS} /></F>
                  <F label="Expiry date"><input type="date" value={form.expiry_date ? String(form.expiry_date).split('T')[0] : ''} onChange={e => sf('expiry_date', e.target.value)} className={I} style={IS} /></F>
                </div>
                {/* Links back to the paperwork this receipt came from. */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <F label="Purchase order no"><NumOrBlank value={form.pr_order_id} onChange={v => sf('pr_order_id', v)} /></F>
                  <F label="Project">
                    <Select value={form.project_id ?? ''} onChange={v => sf('project_id', v)} placeholder="None"
                      options={[{ value: '', label: 'None' }, ...projects.map(pr => ({ value: pr.id, label: pr.name }))]} />
                  </F>
                  <F label="Buyer / salesman">
                    <Select value={form.staff_id ?? ''} onChange={v => sf('staff_id', v)} placeholder="None"
                      options={[{ value: '', label: 'None' }, ...staff.map(u => ({ value: u.id, label: u.name }))]} />
                  </F>
                </div>
              </>
            )}

            {type === 'delivery' && (
              <>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <F label="Customer name"><input value={form.customer_name || ''} onChange={e => sf('customer_name', e.target.value)} className={I} style={IS} /></F>
                  <F label="Address"><input value={form.address || ''} onChange={e => sf('address', e.target.value)} className={I} style={IS} /></F>
                  <F label="Invoice no"><input value={form.invoice_no || ''} onChange={e => sf('invoice_no', e.target.value)} className={I} style={IS} /></F>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <F label="Purchase order no"><NumOrBlank value={form.pr_order_id} onChange={v => sf('pr_order_id', v)} /></F>
                  <F label="Project">
                    <Select value={form.project_id ?? ''} onChange={v => sf('project_id', v)} placeholder="None"
                      options={[{ value: '', label: 'None' }, ...projects.map(pr => ({ value: pr.id, label: pr.name }))]} />
                  </F>
                  <F label="Salesman">
                    <Select value={form.staff_id ?? ''} onChange={v => sf('staff_id', v)} placeholder="None"
                      options={[{ value: '', label: 'None' }, ...staff.map(u => ({ value: u.id, label: u.name }))]} />
                  </F>
                </div>
              </>
            )}

            {type === 'loss_adjustment' && (
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <F label="Type" required>
                  <Select value={form.adjustment_type || 'loss'} onChange={v => sf('adjustment_type', v)}
                    options={[{ value: 'loss', label: 'Loss (write off)' }, { value: 'adjustment', label: 'Adjustment (recount)' }]} />
                </F>
                <div className="sm:col-span-2">
                  <F label="Reason"><input value={form.reason || ''} onChange={e => sf('reason', e.target.value)} className={I} style={IS} placeholder="Why is stock changing?" /></F>
                </div>
              </div>
            )}

            <F label="Note"><textarea value={form.description || ''} onChange={e => sf('description', e.target.value)} rows={2} className={I} style={{ ...IS, resize: 'vertical' }} /></F>
          </Section>

          {isRecount && (
            <p className="text-[11px] px-3 py-2 rounded-lg" style={{ background: 'color-mix(in srgb, #8b5cf6 10%, transparent)', color: 'var(--text-body)' }}>
              For a recount, enter the <strong>counted total</strong> per line — not the difference. Posting works out the delta against what's on hand.
            </p>
          )}

          {/* ── Line grid ── */}
          <Section title="Items">
            <div className="overflow-x-auto rounded-xl" style={{ border: '1px solid var(--border)' }}>
              <table className="w-full text-xs" style={{ minWidth: cfg.perLineTransfer ? 780 : 700 }}>
                <thead>
                  <tr className="text-left text-[10px] uppercase tracking-wide" style={{ color: 'var(--text-muted)', background: 'var(--bg-card)' }}>
                    <th className="px-2 py-2 font-bold" style={{ minWidth: 180 }}>Commodity</th>
                    {cfg.perLineTransfer && <><th className="px-2 py-2 font-bold">From stock</th><th className="px-2 py-2 font-bold">To stock</th></>}
                    <th className="px-2 py-2 font-bold">Unit</th>
                    <th className="px-2 py-2 font-bold text-right">Available</th>
                    <th className="px-2 py-2 font-bold text-right" style={{ minWidth: 90 }}>{isRecount ? 'Counted' : 'Quantity'}</th>
                    {cfg.pricing && <><th className="px-2 py-2 font-bold text-right">Unit price</th><th className="px-2 py-2 font-bold text-right">Tax %</th><th className="px-2 py-2 font-bold text-right">Discount</th><th className="px-2 py-2 font-bold text-right">Amount</th></>}
                    {cfg.lots && <><th className="px-2 py-2 font-bold">Lot</th><th className="px-2 py-2 font-bold">Expiry</th></>}
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {lines.map((l, i) => {
                    const p = byId[String(l.product_id)]
                    const amount = (Number(l.quantity) || 0) * (Number(l.unit_price) || 0)
                    return (
                      <tr key={i} style={{ borderTop: '1px solid var(--border)' }}>
                        <td className="px-2 py-1.5">
                          <Select size="sm" value={l.product_id} onChange={v => sl(i, 'product_id', v)} placeholder="Choose item"
                            options={stockable.map(p => ({ value: p.id, label: `${p.sku} · ${p.name}` }))} />
                        </td>
                        {cfg.perLineTransfer && (
                          <>
                            <td className="px-2 py-1.5"><Select size="sm" value={l.from_warehouse_id} onChange={v => sl(i, 'from_warehouse_id', v)} placeholder="From" options={whOpts} /></td>
                            <td className="px-2 py-1.5"><Select size="sm" value={l.to_warehouse_id} onChange={v => sl(i, 'to_warehouse_id', v)} placeholder="To" options={whOpts.filter(o => String(o.value) !== String(l.from_warehouse_id))} /></td>
                          </>
                        )}
                        <td className="px-2 py-1.5 text-[11px]" style={{ color: 'var(--text-muted)' }}>{p?.base_unit || '—'}</td>
                        <td className="px-2 py-1.5 text-right text-[11px] tabular-nums" style={{ color: 'var(--text-muted)' }}>{p ? fmtQty(p.on_hand) : '—'}</td>
                        <td className="px-2 py-1.5"><NumIn value={l.quantity} onChange={v => sl(i, 'quantity', v)} /></td>
                        {cfg.pricing && (
                          <>
                            <td className="px-2 py-1.5"><NumIn value={l.unit_price} onChange={v => sl(i, 'unit_price', v)} /></td>
                            <td className="px-2 py-1.5"><NumIn value={l.tax_rate} onChange={v => sl(i, 'tax_rate', v)} /></td>
                            <td className="px-2 py-1.5"><NumIn value={l.discount} onChange={v => sl(i, 'discount', v)} /></td>
                            <td className="px-2 py-1.5 text-right tabular-nums font-semibold" style={{ color: 'var(--text-h)' }}>{amount ? money(amount.toFixed(2)) : '—'}</td>
                          </>
                        )}
                        {cfg.lots && (
                          <>
                            <td className="px-2 py-1.5"><input value={l.lot_number} onChange={e => sl(i, 'lot_number', e.target.value)} className="rounded-lg outline-none" style={{ ...MINI, width: 80 }} /></td>
                            <td className="px-2 py-1.5"><input type="date" value={l.expiry_date} onChange={e => sl(i, 'expiry_date', e.target.value)} className="rounded-lg outline-none" style={{ ...MINI, width: 130 }} /></td>
                          </>
                        )}
                        <td className="px-1">
                          <button onClick={() => setLines(r => r.length > 1 ? r.filter((_, j) => j !== i) : [emptyLine()])}
                            aria-label="Remove line" className="hover:opacity-60">
                            <Trash2 size={12} style={{ color: 'var(--color-danger-500)' }} />
                          </button>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>

            <button onClick={() => setLines(r => [...r, { ...emptyLine(), from_warehouse_id: cfg.perLineTransfer ? (lines[0]?.from_warehouse_id || '') : '' }])}
              className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
              style={{ border: '1px dashed var(--border)', color: cfg.accent }}>
              <Plus size={13} /> Add line
            </button>

            {cfg.pricing && (
              <div className="flex justify-end">
                <dl className="text-xs space-y-1" style={{ minWidth: 230 }}>
                  <TotalRow label="Total goods" value={money(totals.goods.toFixed(2))} />
                  {totals.discount > 0 && <TotalRow label="Total discount" value={'- ' + money(totals.discount.toFixed(2))} />}
                  <TotalRow label="Total tax" value={money(totals.tax.toFixed(2))} />
                  <TotalRow label="Total payment" value={money(totals.total.toFixed(2))} strong accent={cfg.accent} />
                  <TotalRow label="Value of inventory" value={money(totals.inventoryValue.toFixed(2))} />
                </dl>
              </div>
            )}
          </Section>

          {err && <p className="text-sm px-3 py-2 rounded-lg" style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>}
        </div>

        <footer className="flex items-center gap-2 px-6 py-4 shrink-0" style={{ borderTop: '1px solid var(--border)', background: 'var(--bg-card)' }}>
          {!readOnly && (
            <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
              Saving keeps it a draft — <strong style={{ color: 'var(--text-h)' }}>posting</strong> is what moves stock.
            </p>
          )}
          <div className="ml-auto flex items-center gap-2">
            <button onClick={handleClose} className="text-sm font-semibold px-4 py-2.5 rounded-xl"
              style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
              {readOnly ? 'Close' : 'Cancel'}
            </button>
            {!readOnly && (
              <>
                <button onClick={() => save.mutate({ post: false })} disabled={!canSave || save.isPending}
                  className="flex items-center gap-1.5 text-sm font-bold px-4 py-2.5 rounded-xl disabled:opacity-40"
                  style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
                  <Check size={15} /> Save draft
                </button>
                <button onClick={() => save.mutate({ post: true })} disabled={!canSave || save.isPending}
                  className="flex items-center gap-1.5 text-sm font-bold px-5 py-2.5 rounded-xl disabled:opacity-40"
                  style={{ background: cfg.accent, color: '#fff' }}>
                  <Send size={15} /> {save.isPending ? 'Working…' : 'Save & post'}
                </button>
              </>
            )}
          </div>
        </footer>
      </div>
    </div>
    {dialog}
    </>
  )
}

/* ── Bits ─────────────────────────────────────────────────────── */

const I = 'w-full rounded-xl outline-none'
const IS = { padding: '10px 12px', fontSize: 13.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }
const MINI = { padding: '6px 8px', fontSize: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }

const NumIn = ({ value, onChange }) => (
  <input type="number" min="0" step="0.001" value={value} onChange={e => onChange(e.target.value)}
    className="w-full rounded-lg outline-none text-right" style={{ ...MINI, minWidth: 70 }} />
)

const Section = ({ title, children }) => (
  <section className="space-y-4">
    <h3 className="text-xs font-black uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{title}</h3>
    {children}
  </section>
)

const F = ({ label, required, children }) => (
  <label className="block">
    <span className="text-xs font-bold block mb-1.5" style={{ color: 'var(--text-body)' }}>
      {label}{required && <span style={{ color: 'var(--color-danger-500)' }}> *</span>}
    </span>
    {children}
  </label>
)

const NumOrBlank = ({ value, onChange }) => (
  <input type="number" min={1} value={value ?? ''} onChange={e => onChange(e.target.value === '' ? '' : Number(e.target.value))}
    className={I} style={IS} placeholder="—" />
)

const TotalRow = ({ label, value, strong, accent }) => (
  <div className="flex items-center justify-between gap-6">
    <dt style={{ color: 'var(--text-muted)' }}>{label}</dt>
    <dd className="tabular-nums font-bold" style={{ color: strong ? accent : 'var(--text-h)', fontSize: strong ? 15 : 12 }}>{value}</dd>
  </div>
)
