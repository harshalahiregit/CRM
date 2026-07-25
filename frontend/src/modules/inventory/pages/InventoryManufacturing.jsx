import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Factory, Plus, Check, X, Trash2, ChevronLeft, Hammer, Play, Ban } from 'lucide-react'
import { inventoryApi, INV_ACCENT } from '@/services/inventoryApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'

const INP = { width: '100%', padding: '8px 10px', fontSize: 13, borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', outline: 'none' }
const num = (n) => Number(n || 0)
const B_STATUS = { draft: '#94A3B8', in_progress: '#F59E0B', completed: '#10B981', cancelled: '#EF4444' }

export default function InventoryManufacturing() {
  const [tab, setTab] = useState('boms')
  return (
    <div className="space-y-4 animate-fade-in">
      <div className="flex items-center gap-2">
        <div className="w-9 h-9 rounded-2xl flex items-center justify-center" style={{ background: `color-mix(in srgb, ${INV_ACCENT} 15%, transparent)` }}>
          <Factory size={17} style={{ color: INV_ACCENT }} />
        </div>
        <div>
          <h1 className="text-lg font-black" style={{ color: 'var(--text-h)' }}>Manufacturing</h1>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Recipes (BOMs) and build orders — completing a build moves real stock.</p>
        </div>
      </div>
      <div className="flex items-center gap-2">
        {[['boms', 'Bills of materials'], ['builds', 'Build orders']].map(([k, l]) => (
          <button key={k} onClick={() => setTab(k)} className="text-xs font-bold px-3 py-1.5 rounded-xl" style={tab === k ? { background: INV_ACCENT, color: '#fff' } : { border: '1px solid var(--border)', color: 'var(--text-muted)' }}>{l}</button>
        ))}
      </div>
      {tab === 'boms' ? <BomsTab /> : <BuildsTab />}
    </div>
  )
}

/* ── BOMs ─────────────────────────────────────────────────────── */
function BomsTab() {
  const qc = useQueryClient()
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [creating, setCreating] = useState(false)
  const [err, setErr] = useState('')
  const { data: rows = [], isLoading } = useQuery({ queryKey: ['inv-boms'], queryFn: () => inventoryApi.manufacturing.boms() })
  const refresh = () => qc.invalidateQueries({ queryKey: ['inv-boms'] })
  const del = useMutation({ mutationFn: (id) => inventoryApi.manufacturing.removeBom(id), onSuccess: refresh, onError: (e) => setErr(e?.message || 'Failed.') })

  if (creating) return <BomForm onBack={() => { setCreating(false); refresh() }} />

  return (
    <div className="space-y-3">
      <div className="flex justify-end"><button onClick={() => setCreating(true)} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl" style={{ background: INV_ACCENT, color: '#fff' }}><Plus size={14} /> New BOM</button></div>
      {err && <p className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
      <div className="rounded-2xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
          <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>{['Recipe', 'Finished good', 'Yields', 'Components', 'Status', ''].map((h, i) => <th key={i} className="text-left px-3 py-2.5 text-[11px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>{h}</th>)}</tr></thead>
          <tbody>
            {isLoading && <tr><td colSpan={6} className="px-3 py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>}
            {!isLoading && rows.length === 0 && <tr><td colSpan={6} className="px-3 py-10 text-center" style={{ color: 'var(--text-muted)' }}>No recipes yet.</td></tr>}
            {rows.map(b => (
              <tr key={b.id} style={{ borderBottom: '1px solid var(--border)' }}>
                <td className="px-3 py-2.5 font-semibold" style={{ color: 'var(--text-h)' }}>{b.name || `BOM #${b.id}`}</td>
                <td className="px-3 py-2.5" style={{ color: 'var(--text-body)' }}>{b.product?.name || '—'}</td>
                <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{num(b.output_qty)}</td>
                <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{b.lines_count ?? 0}</td>
                <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-full" style={{ background: `color-mix(in srgb, ${b.status === 'active' ? '#10B981' : '#94A3B8'} 16%, transparent)`, color: b.status === 'active' ? '#10B981' : '#94A3B8' }}>{b.status}</span></td>
                <td className="px-3 py-2.5 text-right">{isAdmin && <button onClick={() => del.mutate(b.id)} className="hover:opacity-60"><Trash2 size={13} style={{ color: 'var(--color-danger-500)' }} /></button>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function BomForm({ onBack }) {
  const [f, setF] = useState({ product_id: '', name: '', output_qty: 1 })
  const [lines, setLines] = useState([{ component_id: '', qty: '' }])
  const [err, setErr] = useState('')
  const { data: products = [] } = useQuery({ queryKey: ['inv-products-lite'], queryFn: () => inventoryApi.products.list({ per_page: 1000 }) })
  const pList = Array.isArray(products) ? products : (products?.data || [])

  const create = useMutation({
    mutationFn: () => inventoryApi.manufacturing.createBom({
      product_id: Number(f.product_id), name: f.name || null, output_qty: Number(f.output_qty || 1),
      lines: lines.filter(l => l.component_id && Number(l.qty) > 0).map(l => ({ component_id: Number(l.component_id), qty: Number(l.qty) })),
    }),
    onSuccess: onBack,
    onError: (e) => setErr(e?.message || 'Could not create.'),
  })
  const setLine = (i, k, v) => setLines(a => a.map((x, j) => j === i ? { ...x, [k]: v } : x))

  return (
    <div className="space-y-4">
      <button onClick={onBack} className="flex items-center gap-1 text-xs font-bold" style={{ color: 'var(--text-muted)' }}><ChevronLeft size={14} /> Back</button>
      <div className="rounded-2xl p-4 space-y-3" style={{ background: 'var(--bg-card)', border: '1px solid var(--border-purple)' }}>
        <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))' }}>
          <Fld label="Finished good *"><Select size="sm" value={f.product_id} onChange={v => setF(p => ({ ...p, product_id: v }))} placeholder="Item…" searchable options={pList.map(p => ({ value: String(p.id), label: `${p.name}${p.sku ? ` (${p.sku})` : ''}` }))} /></Fld>
          <Fld label="Recipe name"><input value={f.name} onChange={e => setF(p => ({ ...p, name: e.target.value }))} style={INP} /></Fld>
          <Fld label="Yields (per batch)"><input type="number" min="0.001" step="0.001" value={f.output_qty} onChange={e => setF(p => ({ ...p, output_qty: e.target.value }))} style={INP} /></Fld>
        </div>
        <div className="space-y-2">
          <span className="block text-[10px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Components consumed per batch</span>
          {lines.map((l, i) => (
            <div key={i} className="grid gap-2 items-center" style={{ gridTemplateColumns: '3fr 1fr auto' }}>
              <Select size="sm" value={l.component_id} onChange={v => setLine(i, 'component_id', v)} placeholder="Component…" searchable options={pList.map(p => ({ value: String(p.id), label: `${p.name}${p.sku ? ` (${p.sku})` : ''}` }))} />
              <input type="number" min="0" placeholder="Qty" value={l.qty} onChange={e => setLine(i, 'qty', e.target.value)} style={INP} />
              <button onClick={() => setLines(a => a.filter((_, j) => j !== i))} className="hover:opacity-60"><Trash2 size={14} style={{ color: 'var(--color-danger-500)' }} /></button>
            </div>
          ))}
          <button onClick={() => setLines(a => [...a, { component_id: '', qty: '' }])} className="flex items-center gap-1 text-xs font-bold" style={{ color: INV_ACCENT }}><Plus size={13} /> Add component</button>
        </div>
        {err && <p className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
        <div className="flex gap-2">
          <button disabled={!f.product_id || create.isPending} onClick={() => create.mutate()} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }}><Check size={13} /> {create.isPending ? 'Saving…' : 'Create BOM'}</button>
          <button onClick={onBack} className="text-xs font-bold px-3 py-2 rounded-xl" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
        </div>
      </div>
    </div>
  )
}

/* ── Build orders ─────────────────────────────────────────────── */
function BuildsTab() {
  const qc = useQueryClient()
  const [openId, setOpenId] = useState(null)
  const [creating, setCreating] = useState(false)
  const [err, setErr] = useState('')
  const { data: rows = [], isLoading } = useQuery({ queryKey: ['inv-builds'], queryFn: () => inventoryApi.manufacturing.builds() })
  const refresh = () => { qc.invalidateQueries({ queryKey: ['inv-builds'] }) }

  if (openId) return <BuildDetail id={openId} onBack={() => { setOpenId(null); refresh() }} />
  if (creating) return <BuildForm onBack={(id) => { setCreating(false); if (id) setOpenId(id); refresh() }} />

  return (
    <div className="space-y-3">
      <div className="flex justify-end"><button onClick={() => setCreating(true)} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl" style={{ background: INV_ACCENT, color: '#fff' }}><Plus size={14} /> New build</button></div>
      {err && <p className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
      <div className="rounded-2xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
          <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>{['Build', 'Product', 'Qty', 'Warehouse', 'Status'].map((h, i) => <th key={i} className="text-left px-3 py-2.5 text-[11px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>{h}</th>)}</tr></thead>
          <tbody>
            {isLoading && <tr><td colSpan={5} className="px-3 py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>}
            {!isLoading && rows.length === 0 && <tr><td colSpan={5} className="px-3 py-10 text-center" style={{ color: 'var(--text-muted)' }}>No build orders yet.</td></tr>}
            {rows.map(b => (
              <tr key={b.id} className="cursor-pointer hover:opacity-80" style={{ borderBottom: '1px solid var(--border)' }} onClick={() => setOpenId(b.id)}>
                <td className="px-3 py-2.5 font-semibold" style={{ color: 'var(--text-h)' }}>{b.code}</td>
                <td className="px-3 py-2.5" style={{ color: 'var(--text-body)' }}>{b.product?.name || '—'}</td>
                <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{num(b.qty)}</td>
                <td className="px-3 py-2.5" style={{ color: 'var(--text-body)' }}>{b.warehouse?.name || '—'}</td>
                <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-full" style={{ background: `color-mix(in srgb, ${B_STATUS[b.status]} 16%, transparent)`, color: B_STATUS[b.status] }}>{b.status?.replace('_', ' ')}</span></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function BuildForm({ onBack }) {
  const [f, setF] = useState({ bom_id: '', warehouse_id: '', qty: '' })
  const [err, setErr] = useState('')
  const { data: boms = [] } = useQuery({ queryKey: ['inv-boms-active'], queryFn: () => inventoryApi.manufacturing.boms({ status: 'active' }) })
  const { data: warehouses = [] } = useQuery({ queryKey: ['inv-wh-all'], queryFn: () => inventoryApi.warehouses.list() })
  const whList = Array.isArray(warehouses) ? warehouses : (warehouses?.data || [])

  const create = useMutation({
    mutationFn: () => inventoryApi.manufacturing.createBuild({ bom_id: Number(f.bom_id), warehouse_id: Number(f.warehouse_id), qty: Number(f.qty) }),
    onSuccess: (b) => onBack(b.id),
    onError: (e) => setErr(e?.message || 'Could not create.'),
  })

  return (
    <div className="space-y-4">
      <button onClick={() => onBack(null)} className="flex items-center gap-1 text-xs font-bold" style={{ color: 'var(--text-muted)' }}><ChevronLeft size={14} /> Cancel</button>
      <div className="rounded-2xl p-4 space-y-3" style={{ background: 'var(--bg-card)', border: '1px solid var(--border-purple)' }}>
        <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))' }}>
          <Fld label="Recipe *"><Select size="sm" value={f.bom_id} onChange={v => setF(p => ({ ...p, bom_id: v }))} placeholder="BOM…" searchable options={boms.map(b => ({ value: String(b.id), label: `${b.name || 'BOM #' + b.id} → ${b.product?.name || ''}` }))} /></Fld>
          <Fld label="Warehouse *"><Select size="sm" value={f.warehouse_id} onChange={v => setF(p => ({ ...p, warehouse_id: v }))} placeholder="Site…" options={whList.map(w => ({ value: String(w.id), label: w.name }))} /></Fld>
          <Fld label="Quantity to build *"><input type="number" min="0" value={f.qty} onChange={e => setF(p => ({ ...p, qty: e.target.value }))} style={INP} /></Fld>
        </div>
        {err && <p className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
        <button disabled={!f.bom_id || !f.warehouse_id || !f.qty || create.isPending} onClick={() => create.mutate()} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }}><Check size={13} /> Create build</button>
      </div>
    </div>
  )
}

function BuildDetail({ id, onBack }) {
  const qc = useQueryClient()
  const [err, setErr] = useState('')
  const { data: b } = useQuery({ queryKey: ['inv-build', id], queryFn: () => inventoryApi.manufacturing.getBuild(id) })
  const { data: av } = useQuery({ queryKey: ['inv-build-av', id], queryFn: () => inventoryApi.manufacturing.availability(id), enabled: !!b && ['draft', 'in_progress'].includes(b.status) })
  const invalidate = () => { qc.invalidateQueries({ queryKey: ['inv-build', id] }); qc.invalidateQueries({ queryKey: ['inv-builds'] }); qc.invalidateQueries({ queryKey: ['inv-build-av', id] }) }

  const setStatus = useMutation({ mutationFn: (s) => inventoryApi.manufacturing.setBuildStatus(id, s), onSuccess: invalidate, onError: (e) => setErr(e?.message || 'Failed.') })

  if (!b) return <div className="p-6 text-sm" style={{ color: 'var(--text-muted)' }}>Loading…</div>

  return (
    <div className="space-y-4">
      <button onClick={onBack} className="flex items-center gap-1 text-xs font-bold" style={{ color: 'var(--text-muted)' }}><ChevronLeft size={14} /> Back to builds</button>
      <div className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border-purple)' }}>
        <div className="flex items-start justify-between flex-wrap gap-2">
          <div>
            <div className="flex items-center gap-2"><h2 className="text-lg font-black" style={{ color: 'var(--text-h)' }}>{b.code}</h2><span className="text-[10px] font-bold px-2 py-0.5 rounded-full" style={{ background: `color-mix(in srgb, ${B_STATUS[b.status]} 16%, transparent)`, color: B_STATUS[b.status] }}>{b.status?.replace('_', ' ')}</span></div>
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>Build {num(b.qty)} × {b.product?.name} at {b.warehouse?.name}</p>
          </div>
          <div className="flex gap-2">
            {b.status === 'draft' && <button onClick={() => setStatus.mutate('in_progress')} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl" style={{ border: '1px solid var(--border)', color: 'var(--text-body)' }}><Play size={13} /> Start</button>}
            {['draft', 'in_progress'].includes(b.status) && <button onClick={() => setStatus.mutate('completed')} disabled={av && !av.can_build} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }}><Hammer size={13} /> Complete build</button>}
            {b.status !== 'completed' && b.status !== 'cancelled' && <button onClick={() => setStatus.mutate('cancelled')} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}><Ban size={13} /> Cancel</button>}
          </div>
        </div>
        {err && <p className="text-[11px] mt-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
        {av && !av.can_build && <p className="text-[11px] mt-2" style={{ color: 'var(--color-danger-500)' }}>Not enough components on hand to complete this build.</p>}
      </div>

      <div className="rounded-2xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
          <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>{['Component', 'Needed', 'On hand', ''].map((h, i) => <th key={i} className="text-left px-3 py-2.5 text-[11px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>{h}</th>)}</tr></thead>
          <tbody>
            {(av?.rows || []).map(r => (
              <tr key={r.component_id} style={{ borderBottom: '1px solid var(--border)' }}>
                <td className="px-3 py-2.5" style={{ color: 'var(--text-h)' }}>{r.name}<span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{r.sku}</span></td>
                <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{num(r.need)}</td>
                <td className="px-3 py-2.5 tabular-nums" style={{ color: r.ok ? 'var(--text-body)' : 'var(--color-danger-500)' }}>{num(r.have)}</td>
                <td className="px-3 py-2.5">{r.ok ? <Check size={14} style={{ color: '#10B981' }} /> : <X size={14} style={{ color: 'var(--color-danger-500)' }} />}</td>
              </tr>
            ))}
            {!av && b.status === 'completed' && <tr><td colSpan={4} className="px-3 py-6 text-center" style={{ color: 'var(--text-muted)' }}>Build completed — components issued and finished goods received on the ledger.</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function Fld({ label, children }) {
  return <label className="block"><span className="block text-[10px] font-bold uppercase tracking-wide mb-1" style={{ color: 'var(--text-muted)' }}>{label}</span>{children}</label>
}
