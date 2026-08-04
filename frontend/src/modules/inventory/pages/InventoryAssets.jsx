import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Wrench, Plus, Search, Check, X, Trash2, Pencil, ChevronLeft, UserCircle, CalendarClock } from 'lucide-react'
import { inventoryApi, INV_ACCENT } from '@/services/inventoryApi'
import { hrApi } from '@/services/hrApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'

const INP = { width: '100%', padding: '8px 10px', fontSize: 13, borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', outline: 'none' }
const money = (n) => n == null ? '—' : Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const STATUS = { in_service: '#10B981', maintenance: '#F59E0B', idle: '#94A3B8', reserved: '#2a78d6', damaged: '#ec835a', retired: '#64748B', cancelled: '#EF4444', lost: '#EF4444' }
const STATUS_OPTS = ['in_service', 'maintenance', 'idle', 'reserved', 'damaged', 'retired', 'lost']
const EVENT_TYPES = ['service', 'repair', 'inspection', 'note']

/** Employees are HR records; assets are Inventory records. This is the one link. */
const useEmployees = () => useQuery({
  queryKey: ['inv-hr-employees'],
  queryFn: () => hrApi.employees.list({ per_page: 500 }),
  staleTime: 1000 * 60 * 5,
}).data || []

export default function InventoryAssets() {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [search, setSearch] = useState('')
  const [statusF, setStatusF] = useState('')
  const [dueOnly, setDueOnly] = useState(false)
  const [editing, setEditing] = useState(null)
  const [openId, setOpenId] = useState(null)
  const [err, setErr] = useState('')

  const { data: rows = [], isLoading } = useQuery({
    queryKey: ['inv-assets', search, statusF, dueOnly],
    queryFn: () => inventoryApi.assets.list({ ...(search ? { search } : {}), ...(statusF ? { status: statusF } : {}), ...(dueOnly ? { due: 1 } : {}) }),
  })
  const { data: staff = [] } = useQuery({ queryKey: ['inv-staff'], queryFn: inventoryApi.staff })
  const refresh = () => qc.invalidateQueries({ queryKey: ['inv-assets'] })

  const del = useMutation({ mutationFn: (id) => inventoryApi.assets.remove(id), onSuccess: refresh, onError: (e) => setErr(e?.message || 'Failed.') })

  if (openId) return <AssetDetail id={openId} staff={staff} onBack={() => { setOpenId(null); refresh() }} />

  return (
    <div className="space-y-4 animate-fade-in">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-2">
          <div className="w-9 h-9 rounded-2xl flex items-center justify-center" style={{ background: `color-mix(in srgb, ${INV_ACCENT} 15%, transparent)` }}>
            <Wrench size={17} style={{ color: INV_ACCENT }} />
          </div>
          <div>
            <h1 className="text-lg font-black" style={{ color: 'var(--text-h)' }}>Assets</h1>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Company equipment &amp; tools — who holds them and when they're next due for service.</p>
          </div>
        </div>
        <button onClick={() => { setEditing({}); setErr('') }} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl" style={{ background: INV_ACCENT, color: '#fff' }}>
          <Plus size={14} /> New asset
        </button>
      </div>

      <div className="flex items-center gap-2 flex-wrap">
        <div className="relative flex-1" style={{ minWidth: 200 }}>
          <Search size={14} style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search name / tag / serial…" style={{ ...INP, paddingLeft: 34 }} />
        </div>
        <div style={{ width: 160 }}>
          <Select size="sm" value={statusF} onChange={setStatusF} options={[{ value: '', label: 'All statuses' }, ...STATUS_OPTS.map(s => ({ value: s, label: s.replace('_', ' '), dot: STATUS[s] }))]} />
        </div>
        <button onClick={() => setDueOnly(v => !v)} className="flex items-center gap-1 text-xs font-bold px-3 py-2 rounded-xl"
          style={dueOnly ? { background: INV_ACCENT, color: '#fff' } : { border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
          <CalendarClock size={13} /> Service due
        </button>
      </div>

      {err && <p className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      <div className="rounded-2xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
          <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>{['Asset', 'Holder', 'Next service', 'Status', ''].map((h, i) => <th key={i} className="text-left px-3 py-2.5 text-[11px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>{h}</th>)}</tr></thead>
          <tbody>
            {isLoading && <tr><td colSpan={5} className="px-3 py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>}
            {!isLoading && rows.length === 0 && <tr><td colSpan={5} className="px-3 py-10 text-center" style={{ color: 'var(--text-muted)' }}>No assets yet.</td></tr>}
            {rows.map(a => {
              const overdue = a.next_service_due && new Date(a.next_service_due) < new Date()
              return (
                <tr key={a.id} className="cursor-pointer hover:opacity-80" style={{ borderBottom: '1px solid var(--border)' }} onClick={() => setOpenId(a.id)}>
                  <td className="px-3 py-2.5" style={{ color: 'var(--text-h)' }}>{a.name}<span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{[a.code, a.category, a.serial_no].filter(Boolean).join(' · ')}</span></td>
                  <td className="px-3 py-2.5" style={{ color: 'var(--text-body)' }} onClick={e => a.employee && e.stopPropagation()}>
                    {a.employee ? (
                      <button onClick={() => navigate(`/app/hr/employees/${a.employee.id}`)} className="text-left hover:underline" style={{ color: INV_ACCENT }}>
                        {a.employee.name}
                        {a.employee.employee_code && <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{a.employee.employee_code}</span>}
                      </button>
                    ) : (a.assignee?.name || '—')}
                  </td>
                  <td className="px-3 py-2.5" style={{ color: overdue ? 'var(--color-danger-500)' : 'var(--text-body)' }}>{a.next_service_due || '—'}{overdue ? ' ⚠' : ''}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-full" style={{ background: `color-mix(in srgb, ${STATUS[a.status]} 16%, transparent)`, color: STATUS[a.status] }}>{a.status?.replace('_', ' ')}</span></td>
                  <td className="px-3 py-2.5 text-right" onClick={e => e.stopPropagation()}>
                    <span className="inline-flex items-center gap-2 justify-end">
                      <button onClick={() => { setEditing(a); setErr('') }} title="Edit" className="hover:opacity-60"><Pencil size={13} style={{ color: 'var(--text-muted)' }} /></button>
                      {isAdmin && <button onClick={() => del.mutate(a.id)} title="Delete" className="hover:opacity-60"><Trash2 size={13} style={{ color: 'var(--color-danger-500)' }} /></button>}
                    </span>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {editing && <AssetModal asset={editing} staff={staff} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); refresh() }} />}
    </div>
  )
}

function AssetModal({ asset, staff, onClose, onSaved }) {
  const editing = Boolean(asset?.id)
  const [f, setF] = useState({
    name: asset?.name || '', code: asset?.code || '', category: asset?.category || '', serial_no: asset?.serial_no || '',
    status: asset?.status || 'in_service', assigned_to: asset?.assigned_to || '', location: asset?.location || '',
    purchase_date: asset?.purchase_date || '', purchase_cost: asset?.purchase_cost || '', warranty_until: asset?.warranty_until || '',
    next_service_due: asset?.next_service_due || '', note: asset?.note || '',
  })
  const [err, setErr] = useState('')
  const sf = (k, v) => setF(p => ({ ...p, [k]: v }))

  const save = useMutation({
    mutationFn: () => {
      const p = { ...f, assigned_to: f.assigned_to || null, purchase_cost: f.purchase_cost === '' ? null : Number(f.purchase_cost) }
      for (const k of ['purchase_date', 'warranty_until', 'next_service_due']) if (p[k] === '') p[k] = null
      return editing ? inventoryApi.assets.update(asset.id, p) : inventoryApi.assets.create(p)
    },
    onSuccess: onSaved,
    onError: (e) => setErr(e?.message || 'Could not save.'),
  })

  return (
    <div className="fixed inset-0 z-[80] flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,.5)' }} onClick={onClose}>
      <div className="rounded-2xl p-5 w-full max-w-lg space-y-3 max-h-[90vh] overflow-y-auto" style={{ background: 'var(--bg-card)', border: '1px solid var(--border-purple)' }} onClick={e => e.stopPropagation()}>
        <div className="flex items-center justify-between"><h3 className="text-base font-black" style={{ color: 'var(--text-h)' }}>{editing ? 'Edit asset' : 'New asset'}</h3><button onClick={onClose}><X size={16} style={{ color: 'var(--text-muted)' }} /></button></div>
        <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))' }}>
          <Fld label="Name *"><input value={f.name} onChange={e => sf('name', e.target.value)} style={INP} autoFocus /></Fld>
          <Fld label="Asset tag"><input value={f.code} onChange={e => sf('code', e.target.value)} style={INP} /></Fld>
          <Fld label="Category"><input value={f.category} onChange={e => sf('category', e.target.value)} style={INP} /></Fld>
          <Fld label="Serial no."><input value={f.serial_no} onChange={e => sf('serial_no', e.target.value)} style={INP} /></Fld>
          <Fld label="Status"><Select size="sm" value={f.status} onChange={v => sf('status', v)} options={STATUS_OPTS.map(s => ({ value: s, label: s.replace('_', ' '), dot: STATUS[s] }))} /></Fld>
          <Fld label="Assigned to"><Select size="sm" value={String(f.assigned_to)} onChange={v => sf('assigned_to', v)} placeholder="Nobody" options={[{ value: '', label: 'Nobody' }, ...staff.map(s => ({ value: String(s.id), label: s.name }))]} /></Fld>
          <Fld label="Location"><input value={f.location} onChange={e => sf('location', e.target.value)} style={INP} /></Fld>
          <Fld label="Purchase date"><input type="date" value={f.purchase_date || ''} onChange={e => sf('purchase_date', e.target.value)} style={INP} /></Fld>
          <Fld label="Purchase cost"><input type="number" value={f.purchase_cost} onChange={e => sf('purchase_cost', e.target.value)} style={INP} /></Fld>
          <Fld label="Warranty until"><input type="date" value={f.warranty_until || ''} onChange={e => sf('warranty_until', e.target.value)} style={INP} /></Fld>
          <Fld label="Next service due"><input type="date" value={f.next_service_due || ''} onChange={e => sf('next_service_due', e.target.value)} style={INP} /></Fld>
        </div>
        <Fld label="Note"><input value={f.note} onChange={e => sf('note', e.target.value)} style={INP} /></Fld>
        {err && <p className="text-[11px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
        <div className="flex gap-2">
          <button disabled={!f.name.trim() || save.isPending} onClick={() => save.mutate()} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }}><Check size={13} /> {save.isPending ? 'Saving…' : 'Save'}</button>
          <button onClick={onClose} className="text-xs font-bold px-3 py-2 rounded-xl" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
        </div>
      </div>
    </div>
  )
}

function AssetDetail({ id, staff, onBack }) {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const employees = useEmployees()
  const [err, setErr] = useState('')
  const [ev, setEv] = useState({ type: 'service', description: '', cost: '', vendor: '', next_due: '' })
  const { data: a, isLoading } = useQuery({ queryKey: ['inv-asset', id], queryFn: () => inventoryApi.assets.get(id) })
  const invalidate = () => { qc.invalidateQueries({ queryKey: ['inv-asset', id] }); qc.invalidateQueries({ queryKey: ['inv-assets'] }) }

  const assign = useMutation({ mutationFn: (uid) => inventoryApi.assets.assign(id, uid || null), onSuccess: invalidate, onError: (e) => setErr(e?.message || 'Failed.') })
  // Employee assignment goes through the lifecycle endpoint so status, holder and
  // history move together — the same path the HR profile reads back.
  const assignEmployee = useMutation({
    mutationFn: (eid) => inventoryApi.assets.lifecycle(id, { action: eid ? 'assign' : 'return', employee_id: eid ? Number(eid) : null }),
    onSuccess: invalidate,
    onError: (e) => setErr(e?.message || 'Failed.'),
  })
  const setStatus = useMutation({ mutationFn: (s) => inventoryApi.assets.setStatus(id, s), onSuccess: invalidate, onError: (e) => setErr(e?.message || 'Failed.') })
  const addEvent = useMutation({
    mutationFn: () => inventoryApi.assets.addEvent(id, { ...ev, cost: ev.cost === '' ? null : Number(ev.cost), next_due: ev.next_due || null }),
    onSuccess: () => { setEv({ type: 'service', description: '', cost: '', vendor: '', next_due: '' }); invalidate() },
    onError: (e) => setErr(e?.message || 'Failed.'),
  })

  if (isLoading || !a) return <div className="p-6 text-sm" style={{ color: 'var(--text-muted)' }}>Loading…</div>

  return (
    <div className="space-y-4 animate-fade-in">
      <button onClick={onBack} className="flex items-center gap-1 text-xs font-bold" style={{ color: 'var(--text-muted)' }}><ChevronLeft size={14} /> Back to assets</button>

      <div className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border-purple)' }}>
        <div className="flex items-start justify-between flex-wrap gap-3">
          <div>
            <h2 className="text-lg font-black" style={{ color: 'var(--text-h)' }}>{a.name}</h2>
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{[a.code, a.category, a.serial_no].filter(Boolean).join(' · ') || 'No tag'}</p>
          </div>
          <div className="flex items-center gap-2 flex-wrap">
            <div style={{ width: 150 }}>
              <Select size="sm" value={a.status} onChange={(v) => setStatus.mutate(v)} options={STATUS_OPTS.map(s => ({ value: s, label: s.replace('_', ' '), dot: STATUS[s] }))} />
            </div>
            <div style={{ width: 170 }}>
              <Select size="sm" value={String(a.assigned_to || '')} onChange={(v) => assign.mutate(v)} placeholder="Assign to user…" options={[{ value: '', label: 'Unassigned' }, ...staff.map(s => ({ value: String(s.id), label: s.name }))]} />
            </div>
            {/* Assigning to an employee is what makes the asset appear on their HR profile. */}
            <div style={{ width: 190 }}>
              <Select size="sm" value={String(a.assigned_employee_id || '')} onChange={(v) => assignEmployee.mutate(v)} placeholder="Assign to employee…"
                options={[{ value: '', label: 'No employee' }, ...employees.map(s => ({ value: String(s.id), label: s.employee_code ? `${s.name} · ${s.employee_code}` : s.name }))]} />
            </div>
          </div>
        </div>

        {a.employee && (
          <button onClick={() => navigate(`/app/hr/employees/${a.employee.id}`)}
            className="mt-3 inline-flex items-center gap-1.5 text-[11px] font-bold hover:underline" style={{ color: INV_ACCENT }}>
            <UserCircle size={13} /> View {a.employee.name} in HR
          </button>
        )}
        <div className="grid gap-3 mt-3 text-xs" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(120px,1fr))', color: 'var(--text-body)' }}>
          <div><span className="block text-[10px] uppercase" style={{ color: 'var(--text-muted)' }}>Cost</span>{money(a.purchase_cost)}</div>
          <div><span className="block text-[10px] uppercase" style={{ color: 'var(--text-muted)' }}>Purchased</span>{a.purchase_date || '—'}</div>
          <div><span className="block text-[10px] uppercase" style={{ color: 'var(--text-muted)' }}>Warranty</span>{a.warranty_until || '—'}</div>
          <div><span className="block text-[10px] uppercase" style={{ color: 'var(--text-muted)' }}>Next service</span>{a.next_service_due || '—'}</div>
          <div><span className="block text-[10px] uppercase" style={{ color: 'var(--text-muted)' }}>Location</span>{a.location || '—'}</div>
        </div>
        {err && <p className="text-[11px] mt-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
      </div>

      <div className="rounded-2xl p-4 space-y-3" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <p className="text-[11px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>Log maintenance / event</p>
        <div className="grid gap-2" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(120px,1fr))' }}>
          <Select size="sm" value={ev.type} onChange={v => setEv(e => ({ ...e, type: v }))} options={EVENT_TYPES.map(t => ({ value: t, label: t }))} />
          <input value={ev.description} onChange={e => setEv(s => ({ ...s, description: e.target.value }))} placeholder="What was done" style={INP} />
          <input type="number" value={ev.cost} onChange={e => setEv(s => ({ ...s, cost: e.target.value }))} placeholder="Cost" style={INP} />
          <input value={ev.vendor} onChange={e => setEv(s => ({ ...s, vendor: e.target.value }))} placeholder="Vendor" style={INP} />
          <input type="date" value={ev.next_due} onChange={e => setEv(s => ({ ...s, next_due: e.target.value }))} title="Next due" style={INP} />
          <button onClick={() => addEvent.mutate()} disabled={addEvent.isPending} className="text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }}>Log</button>
        </div>
        <div className="space-y-1">
          {(a.events || []).length === 0 && <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>No history yet.</p>}
          {(a.events || []).slice().reverse().map(e => (
            <div key={e.id} className="flex items-center gap-2 px-2.5 py-1.5 rounded-lg text-xs" style={{ background: 'var(--bg-input)' }}>
              <span className="font-bold capitalize" style={{ color: 'var(--text-h)' }}>{e.type}</span>
              <span style={{ color: 'var(--text-body)' }}>{e.description}</span>
              {e.cost != null && <span style={{ color: 'var(--text-muted)' }}>· ₹{money(e.cost)}</span>}
              {e.next_due && <span style={{ color: INV_ACCENT }}>· next {e.next_due}</span>}
              <span className="ml-auto text-[10px]" style={{ color: 'var(--text-muted)' }}>{e.performer?.name} · {e.performed_at ? new Date(e.performed_at).toLocaleDateString() : ''}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

function Fld({ label, children }) {
  return <label className="block"><span className="block text-[10px] font-bold uppercase tracking-wide mb-1" style={{ color: 'var(--text-muted)' }}>{label}</span>{children}</label>
}
