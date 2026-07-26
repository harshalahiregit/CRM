import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { ShoppingCart, Plus, Search, Check, X, Trash2, Zap, Send, PackageCheck, Ban, ChevronLeft } from 'lucide-react'
import { inventoryApi, INV_ACCENT } from '@/services/inventoryApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'

const INP = { width: '100%', padding: '8px 10px', fontSize: 13, borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', outline: 'none' }

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
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
              {po.vendor?.name}{po.warehouse ? ` → ${po.warehouse.name}` : ''}{po.expected_date ? ` · expected ${po.expected_date}` : ''}
            </p>
          </div>
          <div className="text-right">
            <div className="text-lg font-black tabular-nums" style={{ color: 'var(--text-h)' }}>{money(po.total)}</div>
            <div className="text-[11px]" style={{ color: 'var(--text-muted)' }}>subtotal {money(po.subtotal)} + tax {money(po.tax_total)}</div>
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
              {['Item', 'Qty', 'Received', 'Unit price', 'Tax %', 'Line total', canReceive ? 'Receive' : ''].map((h, i) => (
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
function POCreate({ onBack }) {
  const [vendorId, setVendorId] = useState('')
  const [notes, setNotes] = useState('')
  const [expected, setExpected] = useState('')
  const [lines, setLines] = useState([{ product_id: '', description: '', qty: '', unit_price: '', tax_rate: '' }])
  const [err, setErr] = useState('')

  const { data: vendors = [] } = useQuery({ queryKey: ['inv-vendors-all'], queryFn: () => inventoryApi.vendors.list({ status: 'active' }) })
  const { data: products = [] } = useQuery({ queryKey: ['inv-products-lookup'], queryFn: () => inventoryApi.products.list({ per_page: 1000 }) })
  const productList = Array.isArray(products) ? products : (products?.data || [])

  const create = useMutation({
    mutationFn: () => inventoryApi.purchaseOrders.create({
      vendor_id: Number(vendorId),
      expected_date: expected || null,
      notes: notes || null,
      lines: lines.filter(l => Number(l.qty) > 0).map(l => ({
        product_id: l.product_id ? Number(l.product_id) : null,
        description: l.description || null,
        qty: Number(l.qty),
        unit_price: Number(l.unit_price || 0),
        tax_rate: Number(l.tax_rate || 0),
      })),
    }),
    onSuccess: (po) => onBack(po.id),
    onError: (e) => setErr(e?.message || 'Could not create the purchase order.'),
  })

  const setLine = (i, k, v) => setLines(ls => ls.map((l, j) => j === i ? { ...l, [k]: v } : l))
  const addLine = () => setLines(ls => [...ls, { product_id: '', description: '', qty: '', unit_price: '', tax_rate: '' }])
  const removeLine = (i) => setLines(ls => ls.filter((_, j) => j !== i))

  return (
    <div className="space-y-4 animate-fade-in">
      <button onClick={() => onBack(null)} className="flex items-center gap-1 text-xs font-bold" style={{ color: 'var(--text-muted)' }}><ChevronLeft size={14} /> Cancel</button>
      <h2 className="text-lg font-black" style={{ color: 'var(--text-h)' }}>New purchase order</h2>

      <div className="rounded-2xl p-4 space-y-3" style={{ background: 'var(--bg-card)', border: '1px solid var(--border-purple)' }}>
        <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))' }}>
          <label className="block">
            <span className="block text-[10px] font-bold uppercase tracking-wide mb-1" style={{ color: 'var(--text-muted)' }}>Vendor *</span>
            <Select size="sm" value={vendorId} onChange={setVendorId} placeholder="Choose a vendor…" searchable options={vendors.map(v => ({ value: String(v.id), label: v.name }))} />
          </label>
          <label className="block">
            <span className="block text-[10px] font-bold uppercase tracking-wide mb-1" style={{ color: 'var(--text-muted)' }}>Expected date</span>
            <input type="date" value={expected} onChange={e => setExpected(e.target.value)} style={INP} />
          </label>
          <label className="block">
            <span className="block text-[10px] font-bold uppercase tracking-wide mb-1" style={{ color: 'var(--text-muted)' }}>Notes</span>
            <input value={notes} onChange={e => setNotes(e.target.value)} style={INP} />
          </label>
        </div>

        <div className="space-y-2">
          <span className="block text-[10px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Lines</span>
          {lines.map((l, i) => (
            <div key={i} className="grid gap-2 items-center" style={{ gridTemplateColumns: '2fr 1fr 1fr 1fr auto' }}>
              <Select size="sm" value={l.product_id} onChange={(v) => { const p = productList.find(x => String(x.id) === String(v)); setLine(i, 'product_id', v); if (p) { setLine(i, 'description', p.name); setLine(i, 'unit_price', p.cost_price ?? ''); setLine(i, 'tax_rate', p.gst_rate ?? '') } }} placeholder="Item…" searchable options={productList.map(p => ({ value: String(p.id), label: `${p.name}${p.sku ? ` (${p.sku})` : ''}` }))} />
              <input type="number" min="0" placeholder="Qty" value={l.qty} onChange={e => setLine(i, 'qty', e.target.value)} style={INP} />
              <input type="number" min="0" placeholder="Unit price" value={l.unit_price} onChange={e => setLine(i, 'unit_price', e.target.value)} style={INP} />
              <input type="number" min="0" placeholder="Tax %" value={l.tax_rate} onChange={e => setLine(i, 'tax_rate', e.target.value)} style={INP} />
              <button onClick={() => removeLine(i)} className="hover:opacity-60" title="Remove"><Trash2 size={14} style={{ color: 'var(--color-danger-500)' }} /></button>
            </div>
          ))}
          <button onClick={addLine} className="flex items-center gap-1 text-xs font-bold" style={{ color: INV_ACCENT }}><Plus size={13} /> Add line</button>
        </div>

        {err && <p className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
        <div className="flex gap-2">
          <button disabled={!vendorId || create.isPending} onClick={() => create.mutate()} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }}>
            <Check size={13} /> {create.isPending ? 'Creating…' : 'Create purchase order'}
          </button>
          <button onClick={() => onBack(null)} className="text-xs font-bold px-3 py-2 rounded-xl" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
        </div>
      </div>
    </div>
  )
}
