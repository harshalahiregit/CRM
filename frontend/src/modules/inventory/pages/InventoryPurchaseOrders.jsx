import { useState, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { ShoppingCart, Plus, Search, Check, X, Trash2, Zap, Send, PackageCheck, Ban, ChevronLeft, FileText, Truck } from 'lucide-react'
import { inventoryApi, INV_ACCENT } from '@/services/inventoryApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'

const INP = { width: '100%', padding: '8px 10px', fontSize: 13, borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', outline: 'none' }
const LBL = { display: 'block', fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em', marginBottom: 4, color: 'var(--text-muted)' }
const CURRENCIES = ['INR', 'USD', 'EUR', 'GBP', 'AED']

const STATUS_COLORS = {
  draft: '#94A3B8', submitted: '#F59E0B', approved: '#3B82F6',
  sent: '#8B5CF6', partial: '#F59E0B', received: '#10B981', cancelled: '#EF4444',
}
const money = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export default function InventoryPurchaseOrders() {
  const qc = useQueryClient()
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [search, setSearch] = useState('')
  const [statusF, setStatusF] = useState('')
  const [openId, setOpenId] = useState(null)   // detail panel
  const [creating, setCreating] = useState(false)
  const [notice, setNotice] = useState('')
  const [err, setErr] = useState('')

  const { data: rows = [], isLoading } = useQuery({
    queryKey: ['inv-po', search, statusF],
    queryFn: () => inventoryApi.purchaseOrders.list({ ...(search ? { search } : {}), ...(statusF ? { status: statusF } : {}) }),
  })
  const refresh = () => qc.invalidateQueries({ queryKey: ['inv-po'] })

  const generate = useMutation({
    mutationFn: () => inventoryApi.purchaseOrders.generate(),
    onSuccess: (res) => {
      setErr('')
      const n = res?.created?.length || 0
      const skip = res?.skipped?.length || 0
      setNotice(n === 0
        ? (skip > 0 ? `Nothing ordered — ${skip} low-stock item(s) have no vendor linked.` : 'Nothing to reorder — all items are above their reorder point.')
        : `Created ${n} draft PO(s)${skip > 0 ? `; ${skip} item(s) skipped (no vendor).` : '.'}`)
      refresh()
    },
    onError: (e) => setErr(e?.message || 'Could not generate purchase orders.'),
  })

  if (openId) return <PODetail id={openId} onBack={() => { setOpenId(null); refresh() }} isAdmin={isAdmin} />
  if (creating) return <POCreate onBack={(created) => { setCreating(false); if (created) setOpenId(created); refresh() }} />

  return (
    <div className="space-y-4 animate-fade-in">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-2">
          <div className="w-9 h-9 rounded-2xl flex items-center justify-center" style={{ background: `color-mix(in srgb, ${INV_ACCENT} 15%, transparent)` }}>
            <ShoppingCart size={17} style={{ color: INV_ACCENT }} />
          </div>
          <div>
            <h1 className="text-lg font-black" style={{ color: 'var(--text-h)' }}>Purchase orders</h1>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>What you've ordered from vendors — and auto-reorder for low stock.</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <button onClick={() => generate.mutate()} disabled={generate.isPending} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-50" style={{ border: `1px solid ${INV_ACCENT}`, color: INV_ACCENT }}>
            <Zap size={14} /> {generate.isPending ? 'Checking…' : 'Auto-reorder low stock'}
          </button>
          <button onClick={() => { setCreating(true); setNotice(''); setErr('') }} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl" style={{ background: INV_ACCENT, color: '#fff' }}>
            <Plus size={14} /> New PO
          </button>
        </div>
      </div>

      {notice && <div className="rounded-xl px-3 py-2 text-xs font-semibold" style={{ background: `color-mix(in srgb, ${INV_ACCENT} 12%, transparent)`, color: INV_ACCENT }}>{notice}</div>}
      {err && <p className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      <div className="flex items-center gap-2 flex-wrap">
        <div className="relative flex-1" style={{ minWidth: 200 }}>
          <Search size={14} style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search PO code / vendor…" style={{ ...INP, paddingLeft: 34 }} />
        </div>
        <div style={{ width: 170 }}>
          <Select size="sm" value={statusF} onChange={setStatusF} options={[{ value: '', label: 'All statuses' }, ...['draft', 'submitted', 'approved', 'sent', 'partial', 'received', 'cancelled'].map(s => ({ value: s, label: s[0].toUpperCase() + s.slice(1) }))]} />
        </div>
      </div>

      <div className="rounded-2xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
          <thead>
            <tr style={{ borderBottom: '1px solid var(--border)' }}>
              {['PO', 'Vendor', 'Lines', 'Total', 'Source', 'Status', ''].map((h, i) => (
                <th key={i} className="text-left px-3 py-2.5 text-[11px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading && <tr><td colSpan={7} className="px-3 py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>}
            {!isLoading && rows.length === 0 && <tr><td colSpan={7} className="px-3 py-10 text-center" style={{ color: 'var(--text-muted)' }}>No purchase orders yet. Use auto-reorder or create one.</td></tr>}
            {rows.map(po => (
              <tr key={po.id} className="cursor-pointer hover:opacity-80" style={{ borderBottom: '1px solid var(--border)' }} onClick={() => setOpenId(po.id)}>
                <td className="px-3 py-2.5 font-semibold" style={{ color: 'var(--text-h)' }}>{po.code}</td>
                <td className="px-3 py-2.5" style={{ color: 'var(--text-body)' }}>{po.vendor?.name || '—'}</td>
                <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{po.lines_count ?? 0}</td>
                <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{money(po.total)}</td>
                <td className="px-3 py-2.5">
                  {po.source === 'auto' && <span className="text-[10px] font-bold px-2 py-0.5 rounded-full" style={{ background: `color-mix(in srgb, ${INV_ACCENT} 16%, transparent)`, color: INV_ACCENT }}>AUTO</span>}
                </td>
                <td className="px-3 py-2.5">
                  <span className="text-[10px] font-bold px-2 py-0.5 rounded-full uppercase" style={{ background: `color-mix(in srgb, ${STATUS_COLORS[po.status]} 16%, transparent)`, color: STATUS_COLORS[po.status] }}>{po.status}</span>
                </td>
                <td className="px-3 py-2.5 text-right" style={{ color: 'var(--text-muted)' }}>›</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

/* ── Detail panel ─────────────────────────────────────────────── */
function PODetail({ id, onBack, isAdmin }) {
  const qc = useQueryClient()
  const [err, setErr] = useState('')
  const [recv, setRecv] = useState({})
  const { data: po, isLoading } = useQuery({ queryKey: ['inv-po', id], queryFn: () => inventoryApi.purchaseOrders.get(id) })
  const invalidate = () => qc.invalidateQueries({ queryKey: ['inv-po'] })

  const act = useMutation({
    mutationFn: ({ fn, arg }) => fn(id, arg),
    onSuccess: () => { setErr(''); setRecv({}); invalidate() },
    onError: (e) => setErr(e?.message || 'Action failed.'),
  })

  if (isLoading || !po) return <div className="p-6 text-sm" style={{ color: 'var(--text-muted)' }}>Loading…</div>

  const canEdit = ['draft', 'submitted'].includes(po.status)
  const canReceive = ['approved', 'sent', 'partial'].includes(po.status)
  const btn = (fn, label, Icon, primary) => (
    <button onClick={() => act.mutate({ fn })} disabled={act.isPending}
      className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40"
      style={primary ? { background: INV_ACCENT, color: '#fff' } : { border: '1px solid var(--border)', color: 'var(--text-body)' }}>
      <Icon size={13} /> {label}
    </button>
  )

  return (
    <div className="space-y-4 animate-fade-in">
      <button onClick={onBack} className="flex items-center gap-1 text-xs font-bold" style={{ color: 'var(--text-muted)' }}><ChevronLeft size={14} /> Back to purchase orders</button>

      <div className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border-purple)' }}>
        <div className="flex items-start justify-between flex-wrap gap-3">
          <div>
            <div className="flex items-center gap-2">
              <h2 className="text-lg font-black" style={{ color: 'var(--text-h)' }}>{po.code}</h2>
              <span className="text-[10px] font-bold px-2 py-0.5 rounded-full uppercase" style={{ background: `color-mix(in srgb, ${STATUS_COLORS[po.status]} 16%, transparent)`, color: STATUS_COLORS[po.status] }}>{po.status}</span>
              {po.source === 'auto' && <span className="text-[10px] font-bold px-2 py-0.5 rounded-full" style={{ background: `color-mix(in srgb, ${INV_ACCENT} 16%, transparent)`, color: INV_ACCENT }}>AUTO</span>}
            </div>
            {po.description && <p className="text-sm font-semibold mt-1" style={{ color: 'var(--text-body)' }}>{po.description}</p>}
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
              {po.vendor?.name}{po.warehouse ? ` → ${po.warehouse.name}` : ''}{po.expected_date ? ` · expected ${po.expected_date}` : ''}
              {po.type ? ` · ${po.type.toUpperCase()}` : ''}{po.tags ? ` · ${po.tags}` : ''}
            </p>
          </div>
          <div className="text-right">
            <div className="text-lg font-black tabular-nums" style={{ color: 'var(--text-h)' }}>{po.currency ? `${po.currency} ` : ''}{money(po.total)}</div>
            <div className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
              subtotal {money(po.subtotal)} + tax {money(po.tax_total)}
              {Number(po.discount_amount) > 0 ? ` – disc ${money(po.discount_amount)}` : ''}
              {Number(po.shipping_fee) > 0 ? ` + ship ${money(po.shipping_fee)}` : ''}
            </div>
          </div>
        </div>

        {err && <p className="text-[11px] mt-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

        <div className="flex flex-wrap gap-2 mt-3">
          {po.status === 'draft' && btn(inventoryApi.purchaseOrders.submit, 'Submit for approval', Send)}
          {['draft', 'submitted'].includes(po.status) && isAdmin && btn(inventoryApi.purchaseOrders.approve, 'Approve', Check, true)}
          {['approved', 'submitted'].includes(po.status) && btn(inventoryApi.purchaseOrders.send, 'Mark as sent', Send)}
          {!['received', 'cancelled'].includes(po.status) && btn(inventoryApi.purchaseOrders.cancel, 'Cancel', Ban)}
        </div>
      </div>

      <div className="rounded-2xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
          <thead>
            <tr style={{ borderBottom: '1px solid var(--border)' }}>
              {['Item', 'Qty', 'Received', 'Unit price', 'Tax %', 'Disc %', 'Line total', canReceive ? 'Receive' : ''].map((h, i) => (
                <th key={i} className="text-left px-3 py-2.5 text-[11px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {po.lines?.map(l => {
              const outstanding = Math.max(0, Number(l.qty) - Number(l.received_qty))
              return (
                <tr key={l.id} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="px-3 py-2.5" style={{ color: 'var(--text-h)' }}>{l.product?.name || l.description || '—'}<span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{l.product?.sku}</span></td>
                  <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{Number(l.qty)}</td>
                  <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{Number(l.received_qty)}</td>
                  <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{money(l.unit_price)}</td>
                  <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{Number(l.tax_rate)}</td>
                  <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{Number(l.discount_pct || 0)}</td>
                  <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{money(l.line_total)}</td>
                  {canReceive && (
                    <td className="px-3 py-2.5">
                      {outstanding > 0
                        ? <input type="number" min="0" max={outstanding} value={recv[l.id] ?? ''} onChange={e => setRecv(r => ({ ...r, [l.id]: e.target.value }))} placeholder={`≤ ${outstanding}`} style={{ ...INP, width: 90 }} />
                        : <span className="text-[11px]" style={{ color: INV_ACCENT }}>complete</span>}
                    </td>
                  )}
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {canReceive && (
        <button
          onClick={() => act.mutate({ fn: (id) => inventoryApi.purchaseOrders.receive(id, Object.fromEntries(Object.entries(recv).filter(([, v]) => Number(v) > 0).map(([k, v]) => [k, Number(v)]))) })}
          disabled={act.isPending || Object.values(recv).every(v => !Number(v))}
          className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }}>
          <PackageCheck size={14} /> Record receipt
        </button>
      )}
    </div>
  )
}

/* ── Create form ──────────────────────────────────────────────── */
const EMPTY_LINE = { product_id: '', description: '', qty: '', unit_price: '', tax_rate: '', discount_pct: '' }

function POCreate({ onBack }) {
  const [tab, setTab] = useState('general')
  const [form, setForm] = useState({
    description: '', vendor_id: '', order_date: new Date().toISOString().slice(0, 10),
    warehouse_id: '', expected_date: '', delivery_date: '', currency: '', type: '', tags: '',
    discount_type: 'before_tax', discount_mode: 'percent', discount_value: '', shipping_fee: '',
    vendor_note: '', terms: '',
    ship_address: '', ship_city: '', ship_state: '', ship_zip: '', ship_country: '',
  })
  const [lines, setLines] = useState([{ ...EMPTY_LINE }])
  const [err, setErr] = useState('')
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  const { data: vendors = [] } = useQuery({ queryKey: ['inv-vendors-all'], queryFn: () => inventoryApi.vendors.list({ status: 'active' }) })
  const { data: products = [] } = useQuery({ queryKey: ['inv-products-lookup'], queryFn: () => inventoryApi.products.list({ per_page: 1000 }) })
  const { data: warehouses = [] } = useQuery({ queryKey: ['inv-warehouses-all'], queryFn: inventoryApi.warehouses.list })
  const productList = Array.isArray(products) ? products : (products?.data || [])
  const warehouseList = Array.isArray(warehouses) ? warehouses : (warehouses?.data || [])

  // Live totals — mirror the server's recalc() exactly so the figure shown is
  // the figure saved (line discounts → tax → order discount → shipping).
  const t = useMemo(() => {
    let subtotal = 0, tax = 0
    for (const l of lines) {
      const qty = Number(l.qty) || 0, price = Number(l.unit_price) || 0
      const disc = Math.min(100, Math.max(0, Number(l.discount_pct) || 0))
      let net = qty * price; net -= net * disc / 100
      subtotal += net; tax += net * (Number(l.tax_rate) || 0) / 100
    }
    const val = Number(form.discount_value) || 0
    let discountAmount = 0
    if (val > 0) {
      if (form.discount_mode === 'percent') {
        const base = form.discount_type === 'after_tax' ? subtotal + tax : subtotal
        discountAmount = base * Math.min(100, val) / 100
      } else discountAmount = val
    }
    const shipping = Math.max(0, Number(form.shipping_fee) || 0)
    return { subtotal, tax, discountAmount, shipping, total: Math.max(0, subtotal + tax - discountAmount + shipping) }
  }, [lines, form.discount_value, form.discount_mode, form.discount_type, form.shipping_fee])

  const lineTotal = (l) => {
    const qty = Number(l.qty) || 0, price = Number(l.unit_price) || 0
    const disc = Math.min(100, Math.max(0, Number(l.discount_pct) || 0))
    const net = qty * price * (1 - disc / 100)
    return net + net * (Number(l.tax_rate) || 0) / 100
  }

  const create = useMutation({
    mutationFn: () => inventoryApi.purchaseOrders.create({
      ...form,
      vendor_id: Number(form.vendor_id),
      warehouse_id: form.warehouse_id ? Number(form.warehouse_id) : null,
      type: form.type || null,
      currency: form.currency || null,
      order_date: form.order_date || null,
      expected_date: form.expected_date || null,
      delivery_date: form.delivery_date || null,
      discount_value: Number(form.discount_value) || 0,
      shipping_fee: Number(form.shipping_fee) || 0,
      lines: lines.filter(l => Number(l.qty) > 0).map(l => ({
        product_id: l.product_id ? Number(l.product_id) : null,
        description: l.description || null,
        qty: Number(l.qty),
        unit_price: Number(l.unit_price || 0),
        tax_rate: Number(l.tax_rate || 0),
        discount_pct: Number(l.discount_pct || 0),
      })),
    }),
    onSuccess: (po) => onBack(po.id),
    onError: (e) => setErr(e?.message || 'Could not create the purchase order.'),
  })

  const setLine = (i, k, v) => setLines(ls => ls.map((l, j) => j === i ? { ...l, [k]: v } : l))
  const addLine = () => setLines(ls => [...ls, { ...EMPTY_LINE }])
  const removeLine = (i) => setLines(ls => ls.filter((_, j) => j !== i))

  const TAB = (key, label, Icon) => (
    <button onClick={() => setTab(key)}
      className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl transition-colors"
      style={tab === key
        ? { background: `color-mix(in srgb, ${INV_ACCENT} 13%, transparent)`, color: INV_ACCENT }
        : { color: 'var(--text-muted)' }}>
      <Icon size={13} /> {label}
    </button>
  )

  return (
    <div className="space-y-4 animate-fade-in">
      <button onClick={() => onBack(null)} className="flex items-center gap-1 text-xs font-bold" style={{ color: 'var(--text-muted)' }}><ChevronLeft size={14} /> Cancel</button>
      <h2 className="text-lg font-black" style={{ color: 'var(--text-h)' }}>New purchase order</h2>

      <div className="flex items-center gap-1">{TAB('general', 'General', FileText)}{TAB('shipping', 'Shipping', Truck)}</div>

      <div className="rounded-2xl p-4 space-y-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border-purple)' }}>
        {tab === 'general' ? (
          <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))' }}>
            <label className="block" style={{ gridColumn: '1 / -1' }}>
              <span style={LBL}>Purchase order description *</span>
              <input value={form.description} onChange={e => set('description', e.target.value)} placeholder="What is this order for?" style={INP} />
            </label>
            <label className="block">
              <span style={LBL}>PO number</span>
              <input value="Assigned on save" disabled style={{ ...INP, opacity: 0.6, cursor: 'not-allowed' }} />
            </label>
            <label className="block">
              <span style={LBL}>Vendor *</span>
              <Select size="sm" value={form.vendor_id} onChange={v => set('vendor_id', v)} placeholder="Choose a vendor…" searchable options={vendors.map(v => ({ value: String(v.id), label: v.name }))} />
            </label>
            <label className="block">
              <span style={LBL}>Order date *</span>
              <input type="date" value={form.order_date} onChange={e => set('order_date', e.target.value)} style={INP} />
            </label>
            <label className="block">
              <span style={LBL}>Deliver to warehouse</span>
              <Select size="sm" value={form.warehouse_id} onChange={v => set('warehouse_id', v)} placeholder="— none —" options={[{ value: '', label: '— none —' }, ...warehouseList.map(w => ({ value: String(w.id), label: w.name }))]} />
            </label>
            <label className="block">
              <span style={LBL}>Expected date</span>
              <input type="date" value={form.expected_date} onChange={e => set('expected_date', e.target.value)} style={INP} />
            </label>
            <label className="block">
              <span style={LBL}>Delivery date</span>
              <input type="date" value={form.delivery_date} onChange={e => set('delivery_date', e.target.value)} style={INP} />
            </label>
            <label className="block">
              <span style={LBL}>Currency</span>
              <Select size="sm" value={form.currency} onChange={v => set('currency', v)} placeholder="Base currency" options={[{ value: '', label: 'Base currency' }, ...CURRENCIES.map(c => ({ value: c, label: c }))]} />
            </label>
            <label className="block">
              <span style={LBL}>Type</span>
              <Select size="sm" value={form.type} onChange={v => set('type', v)} placeholder="— none —" options={[{ value: '', label: '— none —' }, { value: 'capex', label: 'CAPEX' }, { value: 'opex', label: 'OPEX' }]} />
            </label>
            <label className="block">
              <span style={LBL}>Tags</span>
              <input value={form.tags} onChange={e => set('tags', e.target.value)} placeholder="comma, separated" style={INP} />
            </label>
          </div>
        ) : (
          <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))' }}>
            <label className="block" style={{ gridColumn: '1 / -1' }}>
              <span style={LBL}>Ship-to address</span>
              <input value={form.ship_address} onChange={e => set('ship_address', e.target.value)} placeholder="Street address" style={INP} />
            </label>
            <label className="block"><span style={LBL}>City</span><input value={form.ship_city} onChange={e => set('ship_city', e.target.value)} style={INP} /></label>
            <label className="block"><span style={LBL}>State</span><input value={form.ship_state} onChange={e => set('ship_state', e.target.value)} style={INP} /></label>
            <label className="block"><span style={LBL}>Zip code</span><input value={form.ship_zip} onChange={e => set('ship_zip', e.target.value)} style={INP} /></label>
            <label className="block"><span style={LBL}>Country</span><input value={form.ship_country} onChange={e => set('ship_country', e.target.value)} style={INP} /></label>
          </div>
        )}

        {/* ── Line items (shared across tabs) ── */}
        <div className="space-y-2 pt-1" style={{ borderTop: '1px solid var(--border)' }}>
          <span style={{ ...LBL, marginTop: 12 }}>Items</span>
          <div className="hidden md:grid gap-2 text-[10px] font-bold uppercase" style={{ gridTemplateColumns: '2.4fr 0.9fr 1fr 0.9fr 0.9fr 1fr auto', color: 'var(--text-muted)' }}>
            <span>Item</span><span>Qty</span><span>Unit price</span><span>Tax %</span><span>Disc %</span><span className="text-right">Line total</span><span></span>
          </div>
          {lines.map((l, i) => (
            <div key={i} className="grid gap-2 items-center" style={{ gridTemplateColumns: '2.4fr 0.9fr 1fr 0.9fr 0.9fr 1fr auto' }}>
              <Select size="sm" value={l.product_id} onChange={(v) => { const p = productList.find(x => String(x.id) === String(v)); setLine(i, 'product_id', v); if (p) { setLine(i, 'description', p.name); setLine(i, 'unit_price', p.cost_price ?? ''); setLine(i, 'tax_rate', p.gst_rate ?? '') } }} placeholder="Item…" searchable options={productList.map(p => ({ value: String(p.id), label: `${p.name}${p.sku ? ` (${p.sku})` : ''}` }))} />
              <input type="number" min="0" placeholder="Qty" value={l.qty} onChange={e => setLine(i, 'qty', e.target.value)} style={INP} />
              <input type="number" min="0" placeholder="Price" value={l.unit_price} onChange={e => setLine(i, 'unit_price', e.target.value)} style={INP} />
              <input type="number" min="0" placeholder="Tax" value={l.tax_rate} onChange={e => setLine(i, 'tax_rate', e.target.value)} style={INP} />
              <input type="number" min="0" max="100" placeholder="Disc" value={l.discount_pct} onChange={e => setLine(i, 'discount_pct', e.target.value)} style={INP} />
              <span className="text-right text-xs font-semibold tabular-nums" style={{ color: 'var(--text-h)' }}>{money(lineTotal(l))}</span>
              <button onClick={() => removeLine(i)} className="hover:opacity-60" title="Remove"><Trash2 size={14} style={{ color: 'var(--color-danger-500)' }} /></button>
            </div>
          ))}
          <button onClick={addLine} className="flex items-center gap-1 text-xs font-bold" style={{ color: INV_ACCENT }}><Plus size={13} /> Add line</button>
        </div>

        {/* ── Discount / shipping / totals ── */}
        <div className="grid gap-4 pt-3 md:grid-cols-2" style={{ borderTop: '1px solid var(--border)' }}>
          <div className="space-y-3">
            <div className="grid gap-2" style={{ gridTemplateColumns: '1fr 1fr' }}>
              <label className="block">
                <span style={LBL}>Discount applies</span>
                <Select size="sm" value={form.discount_type} onChange={v => set('discount_type', v)} options={[{ value: 'before_tax', label: 'Before tax' }, { value: 'after_tax', label: 'After tax' }]} />
              </label>
              <label className="block">
                <span style={LBL}>Discount</span>
                <div className="flex gap-1.5">
                  <input type="number" min="0" value={form.discount_value} onChange={e => set('discount_value', e.target.value)} placeholder="0" style={INP} />
                  <div style={{ width: 96 }}>
                    <Select size="sm" value={form.discount_mode} onChange={v => set('discount_mode', v)} options={[{ value: 'percent', label: '%' }, { value: 'amount', label: 'Amount' }]} />
                  </div>
                </div>
              </label>
            </div>
            <label className="block" style={{ maxWidth: 200 }}>
              <span style={LBL}>Shipping fee</span>
              <input type="number" min="0" value={form.shipping_fee} onChange={e => set('shipping_fee', e.target.value)} placeholder="0.00" style={INP} />
            </label>
          </div>

          <div className="rounded-xl p-3 space-y-1.5 self-start" style={{ background: 'var(--bg-input)' }}>
            {[['Subtotal', t.subtotal], ['Tax', t.tax], ['Discount', -t.discountAmount], ['Shipping', t.shipping]].map(([k, v]) => (
              <div key={k} className="flex items-center justify-between text-xs" style={{ color: 'var(--text-body)' }}>
                <span>{k}</span><span className="tabular-nums">{v < 0 ? `– ${money(-v)}` : money(v)}</span>
              </div>
            ))}
            <div className="flex items-center justify-between pt-1.5 mt-1" style={{ borderTop: '1px solid var(--border)' }}>
              <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>Grand total</span>
              <span className="text-sm font-black tabular-nums" style={{ color: INV_ACCENT }}>{money(t.total)}</span>
            </div>
          </div>
        </div>

        {/* ── Footer notes ── */}
        <div className="grid gap-3 pt-3 md:grid-cols-2" style={{ borderTop: '1px solid var(--border)' }}>
          <label className="block">
            <span style={LBL}>Vendor note</span>
            <textarea value={form.vendor_note} onChange={e => set('vendor_note', e.target.value)} placeholder="A note to the vendor…" style={{ ...INP, minHeight: 72, resize: 'vertical' }} />
          </label>
          <label className="block">
            <span style={LBL}>Terms &amp; conditions</span>
            <textarea value={form.terms} onChange={e => set('terms', e.target.value)} placeholder="Payment terms, warranty, delivery conditions…" style={{ ...INP, minHeight: 72, resize: 'vertical' }} />
          </label>
        </div>

        {err && <p className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
        <div className="flex gap-2 pt-1">
          <button disabled={!form.vendor_id || !form.description.trim() || create.isPending} onClick={() => create.mutate()} className="flex items-center gap-1.5 text-xs font-bold px-4 py-2 rounded-xl disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }}>
            <Check size={13} /> {create.isPending ? 'Creating…' : 'Create purchase order'}
          </button>
          <button onClick={() => onBack(null)} className="text-xs font-bold px-3 py-2 rounded-xl" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
        </div>
      </div>
    </div>
  )
}
