import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Truck, Plus, Search, Check, X, Trash2, Pencil, Mail, Phone } from 'lucide-react'
import { inventoryApi, INV_ACCENT } from '@/services/inventoryApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'

const INP = { width: '100%', padding: '8px 10px', fontSize: 13, borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', outline: 'none' }
const EMPTY = { name: '', code: '', email: '', phone: '', gstin: '', city: '', state: '', payment_terms: '', lead_time_days: '', status: 'active', note: '' }

export default function InventoryVendors() {
  const qc = useQueryClient()
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [search, setSearch] = useState('')
  const [statusF, setStatusF] = useState('')
  const [editing, setEditing] = useState(null)   // null = closed, {} = new, {…} = edit
  const [err, setErr] = useState('')

  const { data: rows = [], isLoading } = useQuery({
    queryKey: ['inv-vendors', search, statusF],
    queryFn: () => inventoryApi.vendors.list({ ...(search ? { search } : {}), ...(statusF ? { status: statusF } : {}) }),
  })
  const refresh = () => qc.invalidateQueries({ queryKey: ['inv-vendors'] })

  const save = useMutation({
    mutationFn: (v) => {
      const payload = { ...v, lead_time_days: v.lead_time_days === '' ? null : Number(v.lead_time_days) }
      return v.id ? inventoryApi.vendors.update(v.id, payload) : inventoryApi.vendors.create(payload)
    },
    onSuccess: () => { setEditing(null); setErr(''); refresh() },
    onError: (e) => setErr(e?.message || 'Could not save the vendor.'),
  })
  const del = useMutation({ mutationFn: (id) => inventoryApi.vendors.remove(id), onSuccess: refresh, onError: (e) => setErr(e?.message || 'Could not delete the vendor.') })

  const form = editing || EMPTY
  const sf = (k, v) => setEditing(e => ({ ...(e || EMPTY), [k]: v }))

  return (
    <div className="space-y-4 animate-fade-in">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-2">
          <div className="w-9 h-9 rounded-2xl flex items-center justify-center" style={{ background: `color-mix(in srgb, ${INV_ACCENT} 15%, transparent)` }}>
            <Truck size={17} style={{ color: INV_ACCENT }} />
          </div>
          <div>
            <h1 className="text-lg font-black" style={{ color: 'var(--text-h)' }}>Vendors</h1>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Suppliers you buy from — linked to items for reordering.</p>
          </div>
        </div>
        <button onClick={() => { setEditing({ ...EMPTY }); setErr('') }} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl" style={{ background: INV_ACCENT, color: '#fff' }}>
          <Plus size={14} /> New vendor
        </button>
      </div>

      <div className="flex items-center gap-2 flex-wrap">
        <div className="relative flex-1" style={{ minWidth: 200 }}>
          <Search size={14} style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search name / code / GSTIN…" style={{ ...INP, paddingLeft: 34 }} />
        </div>
        <div style={{ width: 150 }}>
          <Select size="sm" value={statusF} onChange={setStatusF} options={[{ value: '', label: 'All statuses' }, { value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]} />
        </div>
      </div>

      {editing && (
        <div className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border-purple)' }}>
          <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))' }}>
            <Fld label="Name *"><input value={form.name} onChange={e => sf('name', e.target.value)} style={INP} autoFocus /></Fld>
            <Fld label="Code"><input value={form.code || ''} onChange={e => sf('code', e.target.value)} style={INP} /></Fld>
            <Fld label="Email"><input value={form.email || ''} onChange={e => sf('email', e.target.value)} style={INP} /></Fld>
            <Fld label="Phone"><input value={form.phone || ''} onChange={e => sf('phone', e.target.value)} style={INP} /></Fld>
            <Fld label="GSTIN"><input value={form.gstin || ''} onChange={e => sf('gstin', e.target.value)} style={INP} /></Fld>
            <Fld label="City"><input value={form.city || ''} onChange={e => sf('city', e.target.value)} style={INP} /></Fld>
            <Fld label="State"><input value={form.state || ''} onChange={e => sf('state', e.target.value)} style={INP} /></Fld>
            <Fld label="Payment terms"><input value={form.payment_terms || ''} onChange={e => sf('payment_terms', e.target.value)} placeholder="e.g. Net 30" style={INP} /></Fld>
            <Fld label="Lead time (days)"><input type="number" min="0" value={form.lead_time_days ?? ''} onChange={e => sf('lead_time_days', e.target.value)} style={INP} /></Fld>
            <Fld label="Status"><Select size="sm" value={form.status || 'active'} onChange={v => sf('status', v)} options={[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]} /></Fld>
          </div>
          {err && <p className="text-[11px] mt-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
          <div className="flex gap-2 mt-3">
            <button disabled={!form.name?.trim() || save.isPending} onClick={() => save.mutate(form)} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }}>
              <Check size={13} /> {save.isPending ? 'Saving…' : (form.id ? 'Save vendor' : 'Create vendor')}
            </button>
            <button onClick={() => { setEditing(null); setErr('') }} className="text-xs font-bold px-3 py-2 rounded-xl" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
          </div>
        </div>
      )}

      {err && !editing && <p className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      <div className="rounded-2xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
          <thead>
            <tr style={{ borderBottom: '1px solid var(--border)' }}>
              {['Vendor', 'Contact', 'Location', 'Lead time', 'Items', 'Status', ''].map((h, i) => (
                <th key={i} className="text-left px-3 py-2.5 text-[11px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading && <tr><td colSpan={7} className="px-3 py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>}
            {!isLoading && rows.length === 0 && <tr><td colSpan={7} className="px-3 py-10 text-center" style={{ color: 'var(--text-muted)' }}>No vendors yet. Add your first supplier.</td></tr>}
            {rows.map(v => (
              <tr key={v.id} style={{ borderBottom: '1px solid var(--border)' }}>
                <td className="px-3 py-2.5">
                  <span className="block font-semibold" style={{ color: 'var(--text-h)' }}>{v.name}</span>
                  {v.code && <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{v.code}{v.gstin ? ` · ${v.gstin}` : ''}</span>}
                </td>
                <td className="px-3 py-2.5" style={{ color: 'var(--text-body)' }}>
                  {v.email && <span className="flex items-center gap-1 text-[11px]"><Mail size={11} />{v.email}</span>}
                  {v.phone && <span className="flex items-center gap-1 text-[11px]"><Phone size={11} />{v.phone}</span>}
                  {!v.email && !v.phone && '—'}
                </td>
                <td className="px-3 py-2.5" style={{ color: 'var(--text-body)' }}>{[v.city, v.state].filter(Boolean).join(', ') || '—'}</td>
                <td className="px-3 py-2.5" style={{ color: 'var(--text-body)' }}>{v.lead_time_days != null ? `${v.lead_time_days} d` : '—'}</td>
                <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-body)' }}>{v.product_links_count ?? 0}</td>
                <td className="px-3 py-2.5">
                  <span className="text-[10px] font-bold px-2 py-0.5 rounded-full" style={{ background: v.status === 'active' ? 'color-mix(in srgb, #10B981 16%, transparent)' : 'var(--border)', color: v.status === 'active' ? '#10B981' : 'var(--text-muted)' }}>{v.status}</span>
                </td>
                <td className="px-3 py-2.5 text-right">
                  <span className="inline-flex items-center gap-2 justify-end">
                    <button onClick={() => { setEditing({ ...v }); setErr('') }} title="Edit" className="hover:opacity-60"><Pencil size={13} style={{ color: 'var(--text-muted)' }} /></button>
                    {isAdmin && <button onClick={() => del.mutate(v.id)} title="Delete" className="hover:opacity-60"><Trash2 size={13} style={{ color: 'var(--color-danger-500)' }} /></button>}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function Fld({ label, children }) {
  return (
    <label className="block">
      <span className="block text-[10px] font-bold uppercase tracking-wide mb-1" style={{ color: 'var(--text-muted)' }}>{label}</span>
      {children}
    </label>
  )
}
