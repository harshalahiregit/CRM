import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Hourglass, TrendingDown, Check, X, Trash2, Tag, Truck, Recycle, Ban, Percent } from 'lucide-react'
import { inventoryApi, INV_ACCENT } from '@/services/inventoryApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'

const INP = { width: '100%', padding: '8px 10px', fontSize: 13, borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', outline: 'none' }
const money = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const ACTIONS = [
  { value: 'discount', label: 'Discount', icon: Percent },
  { value: 'liquidate', label: 'Liquidate', icon: Recycle },
  { value: 'transfer', label: 'Transfer', icon: Truck },
  { value: 'write_off', label: 'Write off', icon: Ban },
  { value: 'dismiss', label: 'Dismiss', icon: X },
]
const STATUS_COLORS = { open: '#F59E0B', in_progress: '#3B82F6', done: '#10B981', cancelled: '#94A3B8' }

export default function InventoryDeadStock() {
  const qc = useQueryClient()
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [tab, setTab] = useState('candidates')
  const [days, setDays] = useState(90)
  const [planFor, setPlanFor] = useState(null)   // candidate row being actioned
  const [err, setErr] = useState('')

  const { data: cand, isLoading: candLoading } = useQuery({
    queryKey: ['inv-dead-cand', days],
    queryFn: () => inventoryApi.deadStock.candidates(days),
    enabled: tab === 'candidates',
  })
  const { data: actions = [], isLoading: actLoading } = useQuery({
    queryKey: ['inv-dead-actions'],
    queryFn: () => inventoryApi.deadStock.list(),
    enabled: tab === 'actions',
  })
  const refresh = () => { qc.invalidateQueries({ queryKey: ['inv-dead-cand'] }); qc.invalidateQueries({ queryKey: ['inv-dead-actions'] }) }

  const setStatus = useMutation({ mutationFn: ({ id, status }) => inventoryApi.deadStock.setStatus(id, status), onSuccess: refresh, onError: (e) => setErr(e?.message || 'Failed.') })
  const del = useMutation({ mutationFn: (id) => inventoryApi.deadStock.remove(id), onSuccess: refresh, onError: (e) => setErr(e?.message || 'Failed.') })

  return (
    <div className="space-y-4 animate-fade-in">
      <div className="flex items-center gap-2">
        <div className="w-9 h-9 rounded-2xl flex items-center justify-center" style={{ background: `color-mix(in srgb, ${INV_ACCENT} 15%, transparent)` }}>
          <Hourglass size={17} style={{ color: INV_ACCENT }} />
        </div>
        <div>
          <h1 className="text-lg font-black" style={{ color: 'var(--text-h)' }}>Dead stock</h1>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Money sitting still — decide what to do with it and track it to done.</p>
        </div>
      </div>

      <div className="flex items-center gap-2 flex-wrap">
        {[['candidates', 'Candidates'], ['actions', 'Action plan']].map(([k, label]) => (
          <button key={k} onClick={() => { setTab(k); setErr('') }} className="text-xs font-bold px-3 py-1.5 rounded-xl"
            style={tab === k ? { background: INV_ACCENT, color: '#fff' } : { border: '1px solid var(--border)', color: 'var(--text-muted)' }}>{label}</button>
        ))}
        {tab === 'candidates' && (
          <div className="ml-auto" style={{ width: 160 }}>
            <Select size="sm" value={String(days)} onChange={(v) => setDays(Number(v))} options={[30, 60, 90, 180, 365].map(d => ({ value: String(d), label: `No sale in ${d} days` }))} />
          </div>
        )}
      </div>

      {err && <p className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      {tab === 'candidates' && (
        <>
          <div className="rounded-2xl p-4 flex items-center gap-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            <TrendingDown size={20} style={{ color: INV_ACCENT }} />
            <div>
              <div className="text-lg font-black tabular-nums" style={{ color: 'var(--text-h)' }}>{money(cand?.value)}</div>
              <div className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{cand?.count ?? 0} item(s) with no sale in {days} days</div>
            </div>
          </div>

          <div className="rounded-2xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
              <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>{['Item', 'On hand', 'Cost', 'Value at rest', ''].map((h, i) => <th key={i} className="text-left px-3 py-2.5 text-[11px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>{h}</th>)}</tr></thead>
              <tbody>
                {candLoading && <tr><td colSpan={5} className="px-3 py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>}
                {!candLoading && (cand?.rows?.length ?? 0) === 0 && <tr><td colSpan={5} className="px-3 py-10 text-center" style={{ color: 'var(--text-muted)' }}>Nothing dead — everything with stock has moved recently. 🎉</td></tr>}
                {cand?.rows?.map(r => (
                  <tr key={r.product_id} style={{ borderBottom: '1px solid var(--border)' }}>
                    <td className="px-3 py-2.5" style={{ color: 'var(--text-h)' }}>{r.name}<span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{r.sku}</span></td>
                    <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{r.quantity}</td>
                    <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{money(r.cost_price)}</td>
                    <td className="px-3 py-2.5 tabular-nums font-semibold" style={{ color: 'var(--text-h)' }}>{money(r.value)}</td>
                    <td className="px-3 py-2.5 text-right">
                      <button onClick={() => { setPlanFor(r); setErr('') }} className="text-xs font-bold px-3 py-1.5 rounded-xl" style={{ background: INV_ACCENT, color: '#fff' }}>Plan action</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}

      {tab === 'actions' && (
        <div className="rounded-2xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
            <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>{['Item', 'Action', 'Qty', 'Value', 'Status', ''].map((h, i) => <th key={i} className="text-left px-3 py-2.5 text-[11px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>{h}</th>)}</tr></thead>
            <tbody>
              {actLoading && <tr><td colSpan={6} className="px-3 py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>}
              {!actLoading && actions.length === 0 && <tr><td colSpan={6} className="px-3 py-10 text-center" style={{ color: 'var(--text-muted)' }}>No actions planned yet.</td></tr>}
              {actions.map(a => (
                <tr key={a.id} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="px-3 py-2.5" style={{ color: 'var(--text-h)' }}>{a.product?.name || '—'}<span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{a.product?.sku}</span></td>
                  <td className="px-3 py-2.5">
                    <span className="text-[11px] font-semibold capitalize" style={{ color: 'var(--text-body)' }}>{a.action.replace('_', ' ')}</span>
                    {a.action === 'discount' && a.new_price != null && <span className="block text-[10px]" style={{ color: INV_ACCENT }}>{a.applied ? 'applied ' : ''}₹{money(a.new_price)}</span>}
                  </td>
                  <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{a.qty != null ? Number(a.qty) : '—'}</td>
                  <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{money(a.value_snapshot)}</td>
                  <td className="px-3 py-2.5">
                    <div style={{ width: 130 }}>
                      <Select size="sm" value={a.status} onChange={(v) => setStatus.mutate({ id: a.id, status: v })}
                        options={['open', 'in_progress', 'done', 'cancelled'].map(s => ({ value: s, label: s.replace('_', ' '), dot: STATUS_COLORS[s] }))} />
                    </div>
                  </td>
                  <td className="px-3 py-2.5 text-right">
                    {isAdmin && <button onClick={() => del.mutate(a.id)} title="Delete" className="hover:opacity-60"><Trash2 size={13} style={{ color: 'var(--color-danger-500)' }} /></button>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {planFor && <PlanModal row={planFor} onClose={() => setPlanFor(null)} onSaved={() => { setPlanFor(null); setTab('actions'); refresh() }} />}
    </div>
  )
}

function PlanModal({ row, onClose, onSaved }) {
  const [action, setAction] = useState('discount')
  const [discount, setDiscount] = useState('')
  const [newPrice, setNewPrice] = useState('')
  const [applyNow, setApplyNow] = useState(true)
  const [toWarehouse, setToWarehouse] = useState('')
  const [note, setNote] = useState('')
  const [err, setErr] = useState('')

  const { data: warehouses = [] } = useQuery({ queryKey: ['inv-wh-all'], queryFn: () => inventoryApi.warehouses.list(), enabled: action === 'transfer' })
  const whList = Array.isArray(warehouses) ? warehouses : (warehouses?.data || [])

  const save = useMutation({
    mutationFn: () => inventoryApi.deadStock.create({
      product_id: row.product_id,
      action,
      qty: row.quantity,
      ...(action === 'discount' ? { discount_percent: discount ? Number(discount) : null, new_price: newPrice ? Number(newPrice) : null, apply_now: applyNow } : {}),
      ...(action === 'transfer' ? { to_warehouse_id: toWarehouse ? Number(toWarehouse) : null } : {}),
      note: note || null,
    }),
    onSuccess: onSaved,
    onError: (e) => setErr(e?.message || 'Could not save.'),
  })

  return (
    <div className="fixed inset-0 z-[90] flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,.5)' }} onClick={onClose}>
      <div className="rounded-2xl p-5 w-full max-w-md space-y-3" style={{ background: 'var(--bg-card)', border: '1px solid var(--border-purple)' }} onClick={e => e.stopPropagation()}>
        <div className="flex items-center justify-between">
          <h3 className="text-base font-black" style={{ color: 'var(--text-h)' }}>Plan action</h3>
          <button onClick={onClose}><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{row.name} · {row.quantity} on hand · {money(row.value)} at rest</p>

        <div className="flex flex-wrap gap-1.5">
          {ACTIONS.map(a => (
            <button key={a.value} onClick={() => setAction(a.value)} className="flex items-center gap-1 text-xs font-bold px-2.5 py-1.5 rounded-xl"
              style={action === a.value ? { background: INV_ACCENT, color: '#fff' } : { border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
              <a.icon size={12} /> {a.label}
            </button>
          ))}
        </div>

        {action === 'discount' && (
          <div className="space-y-2">
            <div className="grid grid-cols-2 gap-2">
              <label className="block"><span className="block text-[10px] font-bold uppercase mb-1" style={{ color: 'var(--text-muted)' }}>Discount %</span><input type="number" min="0" max="100" value={discount} onChange={e => { setDiscount(e.target.value); setNewPrice('') }} style={INP} /></label>
              <label className="block"><span className="block text-[10px] font-bold uppercase mb-1" style={{ color: 'var(--text-muted)' }}>or New price</span><input type="number" min="0" value={newPrice} onChange={e => { setNewPrice(e.target.value); setDiscount('') }} style={INP} /></label>
            </div>
            <label className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-body)' }}>
              <input type="checkbox" checked={applyNow} onChange={e => setApplyNow(e.target.checked)} /> Apply the new price to the item now
            </label>
          </div>
        )}
        {action === 'transfer' && (
          <label className="block"><span className="block text-[10px] font-bold uppercase mb-1" style={{ color: 'var(--text-muted)' }}>Move to warehouse</span>
            <Select size="sm" value={toWarehouse} onChange={setToWarehouse} placeholder="Choose destination…" options={whList.map(w => ({ value: String(w.id), label: w.name }))} />
            <span className="block text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>Records the intent — post the actual move on the Consignments screen.</span>
          </label>
        )}
        {action === 'write_off' && <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Records the decision. Post the stock removal on the Loss &amp; adjustment screen (with approvals).</p>}

        <label className="block"><span className="block text-[10px] font-bold uppercase mb-1" style={{ color: 'var(--text-muted)' }}>Note</span><input value={note} onChange={e => setNote(e.target.value)} style={INP} placeholder="Why / plan…" /></label>

        {err && <p className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
        <div className="flex gap-2">
          <button disabled={save.isPending} onClick={() => save.mutate()} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }}>
            <Check size={13} /> {save.isPending ? 'Saving…' : 'Record action'}
          </button>
          <button onClick={onClose} className="text-xs font-bold px-3 py-2 rounded-xl" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
        </div>
      </div>
    </div>
  )
}
