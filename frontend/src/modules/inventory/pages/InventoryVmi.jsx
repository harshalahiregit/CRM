import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Handshake, Plus, Check, X, Trash2, ChevronLeft, Zap } from 'lucide-react'
import { inventoryApi, INV_ACCENT } from '@/services/inventoryApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'

const INP = { width: '100%', padding: '8px 10px', fontSize: 13, borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', outline: 'none' }
const num = (n) => Number(n || 0)

export default function InventoryVmi() {
  const qc = useQueryClient()
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [openId, setOpenId] = useState(null)
  const [creating, setCreating] = useState(false)
  const [err, setErr] = useState('')

  const { data: rows = [], isLoading } = useQuery({ queryKey: ['inv-vmi'], queryFn: () => inventoryApi.vmi.list() })
  const refresh = () => qc.invalidateQueries({ queryKey: ['inv-vmi'] })
  const del = useMutation({ mutationFn: (id) => inventoryApi.vmi.remove(id), onSuccess: refresh, onError: (e) => setErr(e?.message || 'Failed.') })

  if (openId) return <VmiDetail id={openId} onBack={() => { setOpenId(null); refresh() }} />
  if (creating) return <VmiCreate onBack={(created) => { setCreating(false); if (created) setOpenId(created); refresh() }} />

  return (
    <div className="space-y-4 animate-fade-in">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-2">
          <div className="w-9 h-9 rounded-2xl flex items-center justify-center" style={{ background: `color-mix(in srgb, ${INV_ACCENT} 15%, transparent)` }}>
            <Handshake size={17} style={{ color: INV_ACCENT }} />
          </div>
          <div>
            <h1 className="text-lg font-black" style={{ color: 'var(--text-h)' }}>Vendor-managed inventory</h1>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Vendors keep agreed items between a min and max — shortfalls become draft POs.</p>
          </div>
        </div>
        <button onClick={() => { setCreating(true); setErr('') }} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl" style={{ background: INV_ACCENT, color: '#fff' }}>
          <Plus size={14} /> New agreement
        </button>
      </div>

      {err && <p className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      <div className="rounded-2xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
          <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>{['Agreement', 'Vendor', 'Warehouse', 'Items', 'Status', ''].map((h, i) => <th key={i} className="text-left px-3 py-2.5 text-[11px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>{h}</th>)}</tr></thead>
          <tbody>
            {isLoading && <tr><td colSpan={6} className="px-3 py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>}
            {!isLoading && rows.length === 0 && <tr><td colSpan={6} className="px-3 py-10 text-center" style={{ color: 'var(--text-muted)' }}>No agreements yet.</td></tr>}
            {rows.map(a => (
              <tr key={a.id} className="cursor-pointer hover:opacity-80" style={{ borderBottom: '1px solid var(--border)' }} onClick={() => setOpenId(a.id)}>
                <td className="px-3 py-2.5 font-semibold" style={{ color: 'var(--text-h)' }}>{a.name || `Agreement #${a.id}`}</td>
                <td className="px-3 py-2.5" style={{ color: 'var(--text-body)' }}>{a.vendor?.name || '—'}</td>
                <td className="px-3 py-2.5" style={{ color: 'var(--text-body)' }}>{a.warehouse?.name || 'All sites'}</td>
                <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{a.items_count ?? 0}</td>
                <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-full" style={{ background: `color-mix(in srgb, ${a.status === 'active' ? '#10B981' : '#94A3B8'} 16%, transparent)`, color: a.status === 'active' ? '#10B981' : '#94A3B8' }}>{a.status}</span></td>
                <td className="px-3 py-2.5 text-right" onClick={e => e.stopPropagation()}>
                  {isAdmin && <button onClick={() => del.mutate(a.id)} title="Delete" className="hover:opacity-60"><Trash2 size={13} style={{ color: 'var(--color-danger-500)' }} /></button>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function VmiDetail({ id, onBack }) {
  const qc = useQueryClient()
  const [notice, setNotice] = useState('')
  const [err, setErr] = useState('')
  const { data: a } = useQuery({ queryKey: ['inv-vmi', id], queryFn: () => inventoryApi.vmi.get(id) })
  const { data: sug, isLoading } = useQuery({ queryKey: ['inv-vmi-sug', id], queryFn: () => inventoryApi.vmi.suggestions(id) })

  const gen = useMutation({
    mutationFn: () => inventoryApi.vmi.generatePO(id),
    onSuccess: (r) => { setErr(''); setNotice(r?.message || 'Done.'); qc.invalidateQueries({ queryKey: ['inv-po'] }) },
    onError: (e) => setErr(e?.message || 'Failed.'),
  })

  return (
    <div className="space-y-4 animate-fade-in">
      <button onClick={onBack} className="flex items-center gap-1 text-xs font-bold" style={{ color: 'var(--text-muted)' }}><ChevronLeft size={14} /> Back to agreements</button>
      <div className="flex items-center justify-between flex-wrap gap-2">
        <div>
          <h2 className="text-lg font-black" style={{ color: 'var(--text-h)' }}>{a?.name || `Agreement #${id}`}</h2>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{a?.vendor?.name} · {a?.warehouse?.name || 'All sites'}</p>
        </div>
        <button onClick={() => gen.mutate()} disabled={gen.isPending || (sug?.rows?.length ?? 0) === 0} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }}>
          <Zap size={14} /> {gen.isPending ? 'Creating…' : 'Generate PO from shortfall'}
        </button>
      </div>

      {notice && <div className="rounded-xl px-3 py-2 text-xs font-semibold" style={{ background: `color-mix(in srgb, ${INV_ACCENT} 12%, transparent)`, color: INV_ACCENT }}>{notice}</div>}
      {err && <p className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      <div className="rounded-2xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
          <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>{['Item', 'On hand', 'Min', 'Max', 'Suggest'].map((h, i) => <th key={i} className="text-left px-3 py-2.5 text-[11px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>{h}</th>)}</tr></thead>
          <tbody>
            {isLoading && <tr><td colSpan={5} className="px-3 py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>}
            {!isLoading && (sug?.rows?.length ?? 0) === 0 && <tr><td colSpan={5} className="px-3 py-10 text-center" style={{ color: 'var(--text-muted)' }}>Everything is above its minimum. Nothing to replenish. 🎉</td></tr>}
            {sug?.rows?.map(r => (
              <tr key={r.product_id} style={{ borderBottom: '1px solid var(--border)' }}>
                <td className="px-3 py-2.5" style={{ color: 'var(--text-h)' }}>{r.name}<span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{r.sku}</span></td>
                <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--color-danger-500)' }}>{num(r.on_hand)}</td>
                <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{num(r.min_level)}</td>
                <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{num(r.max_level)}</td>
                <td className="px-3 py-2.5 tabular-nums font-bold" style={{ color: INV_ACCENT }}>+{num(r.suggest)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function VmiCreate({ onBack }) {
  const [f, setF] = useState({ vendor_id: '', warehouse_id: '', name: '', review_frequency: 'monthly' })
  const [items, setItems] = useState([{ product_id: '', min_level: '', max_level: '' }])
  const [err, setErr] = useState('')
  const { data: vendors = [] } = useQuery({ queryKey: ['inv-vendors-all'], queryFn: () => inventoryApi.vendors.list({ status: 'active' }) })
  const { data: warehouses = [] } = useQuery({ queryKey: ['inv-wh-all'], queryFn: () => inventoryApi.warehouses.list() })
  const { data: products = [] } = useQuery({ queryKey: ['inv-products-lite'], queryFn: () => inventoryApi.products.list({ per_page: 1000 }) })
  const whList = Array.isArray(warehouses) ? warehouses : (warehouses?.data || [])
  const pList = Array.isArray(products) ? products : (products?.data || [])

  const create = useMutation({
    mutationFn: () => inventoryApi.vmi.create({
      vendor_id: Number(f.vendor_id), warehouse_id: f.warehouse_id ? Number(f.warehouse_id) : null,
      name: f.name || null, review_frequency: f.review_frequency,
      items: items.filter(i => i.product_id).map(i => ({ product_id: Number(i.product_id), min_level: Number(i.min_level || 0), max_level: Number(i.max_level || 0) })),
    }),
    onSuccess: (a) => onBack(a.id),
    onError: (e) => setErr(e?.message || 'Could not create.'),
  })

  const setItem = (i, k, v) => setItems(a => a.map((x, j) => j === i ? { ...x, [k]: v } : x))

  return (
    <div className="space-y-4 animate-fade-in">
      <button onClick={() => onBack(null)} className="flex items-center gap-1 text-xs font-bold" style={{ color: 'var(--text-muted)' }}><ChevronLeft size={14} /> Cancel</button>
      <h2 className="text-lg font-black" style={{ color: 'var(--text-h)' }}>New VMI agreement</h2>

      <div className="rounded-2xl p-4 space-y-3" style={{ background: 'var(--bg-card)', border: '1px solid var(--border-purple)' }}>
        <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))' }}>
          <Fld label="Vendor *"><Select size="sm" value={f.vendor_id} onChange={v => setF(p => ({ ...p, vendor_id: v }))} placeholder="Choose…" searchable options={vendors.map(v => ({ value: String(v.id), label: v.name }))} /></Fld>
          <Fld label="Warehouse"><Select size="sm" value={f.warehouse_id} onChange={v => setF(p => ({ ...p, warehouse_id: v }))} placeholder="All sites" options={[{ value: '', label: 'All sites' }, ...whList.map(w => ({ value: String(w.id), label: w.name }))]} /></Fld>
          <Fld label="Name"><input value={f.name} onChange={e => setF(p => ({ ...p, name: e.target.value }))} style={INP} /></Fld>
          <Fld label="Review"><Select size="sm" value={f.review_frequency} onChange={v => setF(p => ({ ...p, review_frequency: v }))} options={['weekly', 'fortnightly', 'monthly'].map(x => ({ value: x, label: x }))} /></Fld>
        </div>

        <div className="space-y-2">
          <span className="block text-[10px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Items (min / max)</span>
          {items.map((it, i) => (
            <div key={i} className="grid gap-2 items-center" style={{ gridTemplateColumns: '2fr 1fr 1fr auto' }}>
              <Select size="sm" value={it.product_id} onChange={v => setItem(i, 'product_id', v)} placeholder="Item…" searchable options={pList.map(p => ({ value: String(p.id), label: `${p.name}${p.sku ? ` (${p.sku})` : ''}` }))} />
              <input type="number" min="0" placeholder="Min" value={it.min_level} onChange={e => setItem(i, 'min_level', e.target.value)} style={INP} />
              <input type="number" min="0" placeholder="Max" value={it.max_level} onChange={e => setItem(i, 'max_level', e.target.value)} style={INP} />
              <button onClick={() => setItems(a => a.filter((_, j) => j !== i))} className="hover:opacity-60"><Trash2 size={14} style={{ color: 'var(--color-danger-500)' }} /></button>
            </div>
          ))}
          <button onClick={() => setItems(a => [...a, { product_id: '', min_level: '', max_level: '' }])} className="flex items-center gap-1 text-xs font-bold" style={{ color: INV_ACCENT }}><Plus size={13} /> Add item</button>
        </div>

        {err && <p className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
        <div className="flex gap-2">
          <button disabled={!f.vendor_id || create.isPending} onClick={() => create.mutate()} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }}><Check size={13} /> {create.isPending ? 'Creating…' : 'Create agreement'}</button>
          <button onClick={() => onBack(null)} className="text-xs font-bold px-3 py-2 rounded-xl" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
        </div>
      </div>
    </div>
  )
}

function Fld({ label, children }) {
  return <label className="block"><span className="block text-[10px] font-bold uppercase tracking-wide mb-1" style={{ color: 'var(--text-muted)' }}>{label}</span>{children}</label>
}
