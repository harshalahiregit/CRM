import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { CalendarRange, Plus, Search, Check, X, Trash2, LogOut, LogIn, Ban } from 'lucide-react'
import { inventoryApi, INV_ACCENT } from '@/services/inventoryApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'
import { useDiscardGuard } from '@/lib/confirmClose'

const INP = { width: '100%', padding: '8px 10px', fontSize: 13, borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', outline: 'none' }
const money = (n) => n == null ? '—' : Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const STATUS = { reserved: '#3B82F6', out: '#F59E0B', returned: '#10B981', overdue: '#EF4444', cancelled: '#94A3B8' }

export default function InventoryRentals() {
  const qc = useQueryClient()
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [search, setSearch] = useState('')
  const [statusF, setStatusF] = useState('')
  const [editing, setEditing] = useState(null)
  const [err, setErr] = useState('')

  const { data: rows = [], isLoading } = useQuery({
    queryKey: ['inv-rentals', search, statusF],
    queryFn: () => inventoryApi.rentals.list({ ...(search ? { search } : {}), ...(statusF ? { status: statusF } : {}) }),
  })
  const { data: products = [] } = useQuery({ queryKey: ['inv-products-lite'], queryFn: () => inventoryApi.products.list({ per_page: 1000 }) })
  const productList = Array.isArray(products) ? products : (products?.data || [])
  const refresh = () => qc.invalidateQueries({ queryKey: ['inv-rentals'] })

  const act = useMutation({ mutationFn: ({ fn, id, data }) => fn(id, data), onSuccess: refresh, onError: (e) => setErr(e?.message || 'Failed.') })
  const del = useMutation({ mutationFn: (id) => inventoryApi.rentals.remove(id), onSuccess: refresh, onError: (e) => setErr(e?.message || 'Failed.') })

  return (
    <div className="space-y-4 animate-fade-in">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-2">
          <div className="w-9 h-9 rounded-2xl flex items-center justify-center" style={{ background: `color-mix(in srgb, ${INV_ACCENT} 15%, transparent)` }}>
            <CalendarRange size={17} style={{ color: INV_ACCENT }} />
          </div>
          <div>
            <h1 className="text-lg font-black" style={{ color: 'var(--text-h)' }}>Rentals</h1>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Items let out to customers — who has what, and when it's due back.</p>
          </div>
        </div>
        <button onClick={() => { setEditing({}); setErr('') }} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl" style={{ background: INV_ACCENT, color: '#fff' }}>
          <Plus size={14} /> New rental
        </button>
      </div>

      <div className="flex items-center gap-2 flex-wrap">
        <div className="relative flex-1" style={{ minWidth: 200 }}>
          <Search size={14} style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search customer / code…" style={{ ...INP, paddingLeft: 34 }} />
        </div>
        <div style={{ width: 160 }}>
          <Select size="sm" value={statusF} onChange={setStatusF} options={[{ value: '', label: 'All statuses' }, ...['reserved', 'out', 'overdue', 'returned', 'cancelled'].map(s => ({ value: s, label: s, dot: STATUS[s] }))]} />
        </div>
      </div>

      {err && <p className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      <div className="rounded-2xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
          <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>{['Rental', 'Customer', 'Item', 'Due', 'Rate', 'Status', ''].map((h, i) => <th key={i} className="text-left px-3 py-2.5 text-[11px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>{h}</th>)}</tr></thead>
          <tbody>
            {isLoading && <tr><td colSpan={7} className="px-3 py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>}
            {!isLoading && rows.length === 0 && <tr><td colSpan={7} className="px-3 py-10 text-center" style={{ color: 'var(--text-muted)' }}>No rentals yet.</td></tr>}
            {rows.map(r => {
              const label = r.product?.name || r.asset?.name || r.item_label || '—'
              const eff = r.is_overdue ? 'overdue' : r.status
              return (
                <tr key={r.id} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="px-3 py-2.5 font-semibold" style={{ color: 'var(--text-h)' }}>{r.code}</td>
                  <td className="px-3 py-2.5" style={{ color: 'var(--text-body)' }}>{r.customer_name}<span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{r.customer_contact}</span></td>
                  <td className="px-3 py-2.5" style={{ color: 'var(--text-body)' }}>{label}{Number(r.qty) !== 1 ? ` ×${Number(r.qty)}` : ''}</td>
                  <td className="px-3 py-2.5" style={{ color: r.is_overdue ? 'var(--color-danger-500)' : 'var(--text-body)' }}>{r.due_date || '—'}</td>
                  <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{money(r.rate)}/{r.rate_period?.[0]}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-full" style={{ background: `color-mix(in srgb, ${STATUS[eff]} 16%, transparent)`, color: STATUS[eff] }}>{eff}</span></td>
                  <td className="px-3 py-2.5 text-right">
                    <span className="inline-flex items-center gap-2 justify-end">
                      {r.status === 'reserved' && <button onClick={() => act.mutate({ fn: inventoryApi.rentals.checkout, id: r.id, data: {} })} title="Check out" className="hover:opacity-60"><LogOut size={14} style={{ color: '#F59E0B' }} /></button>}
                      {(r.status === 'out') && <button onClick={() => act.mutate({ fn: inventoryApi.rentals.return, id: r.id, data: {} })} title="Return" className="hover:opacity-60"><LogIn size={14} style={{ color: '#10B981' }} /></button>}
                      {!['returned', 'cancelled'].includes(r.status) && <button onClick={() => act.mutate({ fn: (id) => inventoryApi.rentals.cancel(id), id: r.id })} title="Cancel" className="hover:opacity-60"><Ban size={13} style={{ color: 'var(--text-muted)' }} /></button>}
                      {isAdmin && <button onClick={() => del.mutate(r.id)} title="Delete" className="hover:opacity-60"><Trash2 size={13} style={{ color: 'var(--color-danger-500)' }} /></button>}
                    </span>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {editing && <RentalModal productList={productList} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); refresh() }} />}
    </div>
  )
}

function RentalModal({ productList, onClose, onSaved }) {
  const { guard, dialog } = useDiscardGuard()
  const [f, setF] = useState({ customer_name: '', customer_contact: '', product_id: '', item_label: '', qty: 1, rate: '', rate_period: 'day', deposit: '', due_date: '', note: '' })
  const [err, setErr] = useState('')
  const sf = (k, v) => setF(p => ({ ...p, [k]: v }))

  const isDirty = () => Boolean(f.customer_name.trim() || f.customer_contact.trim() || f.item_label.trim() || f.rate || f.note.trim())
  const handleClose = () => guard(onClose, isDirty())

  const save = useMutation({
    mutationFn: () => inventoryApi.rentals.create({
      ...f,
      product_id: f.product_id ? Number(f.product_id) : null,
      qty: Number(f.qty || 1), rate: Number(f.rate || 0),
      deposit: f.deposit === '' ? null : Number(f.deposit),
      due_date: f.due_date || null,
    }),
    onSuccess: onSaved,
    onError: (e) => setErr(e?.message || 'Could not save.'),
  })

  return (
    <>
      <div className="fixed inset-0 z-[80] flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,.5)' }}>
        <div className="rounded-2xl p-5 w-full max-w-lg space-y-3" style={{ background: 'var(--bg-card)', border: '1px solid var(--border-purple)' }} onClick={e => e.stopPropagation()}>
          <div className="flex items-center justify-between"><h3 className="text-base font-black" style={{ color: 'var(--text-h)' }}>New rental</h3><button onClick={handleClose}><X size={16} style={{ color: 'var(--text-muted)' }} /></button></div>
          <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))' }}>
            <Fld label="Customer *"><input value={f.customer_name} onChange={e => sf('customer_name', e.target.value)} style={INP} autoFocus /></Fld>
            <Fld label="Contact"><input value={f.customer_contact} onChange={e => sf('customer_contact', e.target.value)} style={INP} /></Fld>
            <Fld label="Item (from catalogue)"><Select size="sm" value={f.product_id} onChange={v => sf('product_id', v)} placeholder="— none —" searchable options={[{ value: '', label: '— none —' }, ...productList.map(p => ({ value: String(p.id), label: p.name }))]} /></Fld>
            <Fld label="Or free-text item"><input value={f.item_label} onChange={e => sf('item_label', e.target.value)} style={INP} placeholder="e.g. Scaffold set" /></Fld>
            <Fld label="Qty"><input type="number" min="0" value={f.qty} onChange={e => sf('qty', e.target.value)} style={INP} /></Fld>
            <Fld label="Rate"><input type="number" min="0" value={f.rate} onChange={e => sf('rate', e.target.value)} style={INP} /></Fld>
            <Fld label="Per"><Select size="sm" value={f.rate_period} onChange={v => sf('rate_period', v)} options={['day', 'week', 'month'].map(p => ({ value: p, label: p }))} /></Fld>
            <Fld label="Deposit"><input type="number" min="0" value={f.deposit} onChange={e => sf('deposit', e.target.value)} style={INP} /></Fld>
            <Fld label="Due back"><input type="date" value={f.due_date} onChange={e => sf('due_date', e.target.value)} style={INP} /></Fld>
          </div>
          <Fld label="Note"><input value={f.note} onChange={e => sf('note', e.target.value)} style={INP} /></Fld>
          {err && <p className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
          <div className="flex gap-2">
            <button disabled={!f.customer_name.trim() || save.isPending} onClick={() => save.mutate()} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }}><Check size={13} /> {save.isPending ? 'Saving…' : 'Create'}</button>
            <button onClick={handleClose} className="text-xs font-bold px-3 py-2 rounded-xl" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
          </div>
        </div>
      </div>
      {dialog}
    </>
  )
}

function Fld({ label, children }) {
  return <label className="block"><span className="block text-[10px] font-bold uppercase tracking-wide mb-1" style={{ color: 'var(--text-muted)' }}>{label}</span>{children}</label>
}
