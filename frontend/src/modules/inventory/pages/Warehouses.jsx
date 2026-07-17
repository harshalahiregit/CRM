import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Warehouse, Plus, Trash2, Star, Boxes, MapPin, ChevronRight, X, Check } from 'lucide-react'
import { inventoryApi, INV_ACCENT, WAREHOUSE_TYPES, LOCATION_TYPES, fmtQty } from '@/services/inventoryApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'
import { ConfirmModal } from '@/components/ui/SearchPicker'

const typeLabel = (t) => WAREHOUSE_TYPES.find(x => x.value === t)?.label || t

/**
 * Warehouses + their bin hierarchy. Sites are infrastructure, so creating and
 * deleting them is admin-only (the backend enforces the same rule) — staff can
 * still see every site and what it holds.
 */
export default function Warehouses() {
  const qc = useQueryClient()
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'

  const [creating, setCreating] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(null)
  const [expanded, setExpanded] = useState(null)
  const [err, setErr] = useState('')

  const { data: warehouses = [], isLoading } = useQuery({ queryKey: ['inv-warehouses'], queryFn: inventoryApi.warehouses.list })
  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['inv-warehouses'] })
    qc.invalidateQueries({ queryKey: ['inv-summary'] })
  }

  const remove = useMutation({
    mutationFn: (id) => inventoryApi.warehouses.remove(id),
    onSuccess: () => { setConfirmDelete(null); setErr(''); refresh() },
    onError: (e) => { setConfirmDelete(null); setErr(e?.message || 'Could not delete that warehouse.') },
  })

  return (
    <div className="max-w-4xl">
      <header className="flex flex-wrap items-center gap-2 mb-4">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)` }}>
          <Warehouse size={17} style={{ color: INV_ACCENT }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Warehouses</h1>
        <span className="text-xs px-2 py-0.5 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>{warehouses.length}</span>
        {isAdmin && (
          <button onClick={() => setCreating(true)}
            className="ml-auto flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
            style={{ background: INV_ACCENT, color: '#fff' }}>
            <Plus size={14} /> New Warehouse
          </button>
        )}
      </header>

      {err && (
        <p className="text-xs px-3 py-2 rounded-lg mb-3"
          style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>
      )}

      {isLoading && <div className="rounded-2xl animate-pulse" style={{ height: 140, background: 'var(--bg-card)' }} />}

      {!isLoading && warehouses.length === 0 && (
        <div className="rounded-2xl p-10 text-center" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <Warehouse size={22} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>No warehouses yet</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            {isAdmin ? 'Add your first site — stock has to live somewhere before you can receive it.' : 'Ask an admin to add one.'}
          </p>
        </div>
      )}

      <div className="space-y-3">
        {warehouses.map(w => (
          <section key={w.id} className="rounded-2xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            <div className="flex items-center gap-3 p-4">
              <span className="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
                style={{ background: `color-mix(in srgb, ${INV_ACCENT} 12%, transparent)` }}>
                <Warehouse size={16} style={{ color: INV_ACCENT }} />
              </span>
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-1.5 flex-wrap">
                  <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{w.name}</span>
                  {w.is_default && (
                    <span className="flex items-center gap-0.5 text-[9px] font-bold px-1.5 py-0.5 rounded"
                      style={{ background: `color-mix(in srgb, ${INV_ACCENT} 15%, transparent)`, color: INV_ACCENT }}>
                      <Star size={8} fill="currentColor" /> DEFAULT
                    </span>
                  )}
                  <span className="text-[10px] px-1.5 py-0.5 rounded" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>{typeLabel(w.type)}</span>
                </div>
                <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
                  {w.code ? `${w.code} · ` : ''}{w.address || 'No address'}
                </p>
              </div>

              <div className="hidden sm:flex items-center gap-4 text-right shrink-0">
                <Stat label="Units" value={fmtQty(w.total_quantity)} />
                <Stat label="SKUs" value={w.sku_count ?? 0} />
                <Stat label="Bins" value={w.location_count ?? 0} />
              </div>

              <button onClick={() => setExpanded(expanded === w.id ? null : w.id)} aria-label="Show locations"
                className="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                <ChevronRight size={14} style={{ transform: expanded === w.id ? 'rotate(90deg)' : 'none', transition: 'transform .15s' }} />
              </button>
              {isAdmin && (
                <button onClick={() => setConfirmDelete(w)} aria-label={`Delete ${w.name}`}
                  className="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                  style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--color-danger-500)' }}>
                  <Trash2 size={13} />
                </button>
              )}
            </div>

            {expanded === w.id && <LocationPanel warehouse={w} isAdmin={isAdmin} />}
          </section>
        ))}
      </div>

      {creating && <WarehouseModal onClose={() => setCreating(false)} onSaved={refresh} />}

      <ConfirmModal open={Boolean(confirmDelete)} onClose={() => setConfirmDelete(null)}
        onConfirm={() => remove.mutate(confirmDelete.id)}
        title="Delete this warehouse?"
        message={`“${confirmDelete?.name}” will be removed. A warehouse holding stock can't be deleted.`}
        confirmLabel="Delete" danger />
    </div>
  )
}

const Stat = ({ label, value }) => (
  <div>
    <p className="text-sm font-black tabular-nums" style={{ color: 'var(--text-h)' }}>{value}</p>
    <p className="text-[9px] uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{label}</p>
  </div>
)

/* ── Bin locations ────────────────────────────────────────────── */

function LocationPanel({ warehouse, isAdmin }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({ name: '', type: 'bin', parent_id: '' })
  const [err, setErr] = useState('')

  const { data: locations = [] } = useQuery({
    queryKey: ['inv-locations', warehouse.id], queryFn: () => inventoryApi.warehouses.locations(warehouse.id),
  })
  const bust = () => {
    qc.invalidateQueries({ queryKey: ['inv-locations', warehouse.id] })
    qc.invalidateQueries({ queryKey: ['inv-warehouses'] })
  }

  const add = useMutation({
    mutationFn: () => inventoryApi.warehouses.createLocation(warehouse.id, {
      name: form.name.trim(), type: form.type, parent_id: form.parent_id || undefined,
    }),
    onSuccess: () => { setForm({ name: '', type: 'bin', parent_id: '' }); setErr(''); bust() },
    onError: (e) => setErr(e?.message || 'Could not add that location.'),
  })
  const del = useMutation({
    mutationFn: (lid) => inventoryApi.warehouses.deleteLocation(warehouse.id, lid),
    onSuccess: () => { setErr(''); bust() },
    onError: (e) => setErr(e?.message || 'Could not delete that location.'),
  })

  return (
    <div className="px-4 pb-4" style={{ borderTop: '1px solid var(--border)' }}>
      <p className="text-[10px] font-bold uppercase tracking-wide mt-3 mb-2" style={{ color: 'var(--text-muted)' }}>
        Storage layout — zone › rack › shelf › bin
      </p>

      <ul className="space-y-1 mb-3">
        {locations.map(l => (
          <li key={l.id} className="flex items-center gap-2 px-2.5 py-1.5 rounded-lg" style={{ background: 'var(--bg-input)' }}>
            <MapPin size={11} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
            <span className="flex-1 text-xs truncate" style={{ color: 'var(--text-h)' }}>{l.path || l.name}</span>
            <span className="text-[9px] px-1.5 py-0.5 rounded capitalize" style={{ background: 'var(--bg-card)', color: 'var(--text-muted)' }}>{l.type}</span>
            {isAdmin && (
              <button onClick={() => del.mutate(l.id)} aria-label={`Delete ${l.name}`} className="hover:opacity-60">
                <Trash2 size={11} style={{ color: 'var(--color-danger-500)' }} />
              </button>
            )}
          </li>
        ))}
        {locations.length === 0 && (
          <li className="text-[11px] py-2" style={{ color: 'var(--text-muted)' }}>
            No bins mapped. Stock can still be held at the warehouse itself.
          </li>
        )}
      </ul>

      {err && <p className="text-[11px] mb-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      {isAdmin && (
        <form onSubmit={e => { e.preventDefault(); if (form.name.trim()) add.mutate() }} className="flex flex-wrap gap-2">
          <input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} placeholder="Location name (e.g. Rack A)"
            className="flex-1 rounded-lg outline-none" style={{ minWidth: 150, padding: '7px 10px', fontSize: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
          <div style={{ width: 110 }}>
            <Select size="sm" value={form.type} onChange={v => setForm(f => ({ ...f, type: v }))} options={LOCATION_TYPES} />
          </div>
          <div style={{ width: 150 }}>
            <Select size="sm" value={form.parent_id} onChange={v => setForm(f => ({ ...f, parent_id: v }))} placeholder="Top level"
              options={[{ value: '', label: 'Top level' }, ...locations.map(l => ({ value: l.id, label: l.path || l.name }))]} />
          </div>
          <button type="submit" disabled={!form.name.trim() || add.isPending}
            className="px-3 rounded-lg disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }} aria-label="Add location">
            <Plus size={13} />
          </button>
        </form>
      )}
    </div>
  )
}

/* ── New warehouse ────────────────────────────────────────────── */

function WarehouseModal({ onClose, onSaved }) {
  const [form, setForm] = useState({ name: '', code: '', type: 'godown', address: '', is_default: false })
  const [err, setErr] = useState('')
  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const save = useMutation({
    mutationFn: () => inventoryApi.warehouses.create({
      name: form.name.trim(), code: form.code.trim() || undefined, type: form.type,
      address: form.address.trim() || undefined, is_default: form.is_default,
    }),
    onSuccess: () => { onSaved?.(); onClose() },
    onError: (e) => setErr(e?.message || 'Could not create the warehouse.'),
  })

  return (
    <div className="fixed inset-0 z-[55] flex items-start justify-center p-4 overflow-y-auto"
      style={{ background: 'rgba(15,23,42,0.55)', backdropFilter: 'blur(2px)' }} onClick={onClose}>
      <form onClick={e => e.stopPropagation()} onSubmit={e => { e.preventDefault(); if (form.name.trim()) save.mutate() }}
        className="w-full rounded-2xl overflow-hidden my-8" style={{ maxWidth: 480, background: 'var(--bg-global)', boxShadow: '0 24px 70px rgba(0,0,0,0.45)' }}>
        <header className="flex items-center gap-2.5 px-5 py-4" style={{ background: `linear-gradient(120deg, ${INV_ACCENT}, #059669)` }}>
          <Boxes size={18} style={{ color: '#fff' }} />
          <h2 className="font-bold text-white" style={{ fontSize: 15 }}>New Warehouse</h2>
          <button type="button" onClick={onClose} aria-label="Close" className="ml-auto opacity-90 hover:opacity-100">
            <X size={18} style={{ color: '#fff' }} />
          </button>
        </header>

        <div className="p-5 space-y-4">
          <L label="Name" required>
            <input value={form.name} onChange={e => sf('name', e.target.value)} className={I} style={IS} autoFocus placeholder="e.g. Main Godown" />
          </L>
          <div className="grid grid-cols-2 gap-3">
            <L label="Code"><input value={form.code} onChange={e => sf('code', e.target.value)} className={I} style={IS} placeholder="MG" /></L>
            <L label="Type"><Select value={form.type} onChange={v => sf('type', v)} options={WAREHOUSE_TYPES} /></L>
          </div>
          <L label="Address"><input value={form.address} onChange={e => sf('address', e.target.value)} className={I} style={IS} /></L>
          <label className="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" checked={form.is_default} onChange={e => sf('is_default', e.target.checked)}
              style={{ accentColor: INV_ACCENT, width: 15, height: 15 }} />
            <span className="text-xs font-semibold" style={{ color: 'var(--text-h)' }}>Make this the default site</span>
          </label>
          {err && <p className="text-xs px-3 py-2 rounded-lg" style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>}
        </div>

        <footer className="flex justify-end gap-2 px-5 py-4" style={{ borderTop: '1px solid var(--border)', background: 'var(--bg-card)' }}>
          <button type="button" onClick={onClose} className="text-sm font-semibold px-4 py-2.5 rounded-xl"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
          <button type="submit" disabled={!form.name.trim() || save.isPending}
            className="flex items-center gap-1.5 text-sm font-bold px-5 py-2.5 rounded-xl disabled:opacity-40"
            style={{ background: INV_ACCENT, color: '#fff' }}>
            <Check size={16} /> {save.isPending ? 'Saving…' : 'Create'}
          </button>
        </footer>
      </form>
    </div>
  )
}

const I = 'w-full rounded-xl outline-none'
const IS = { padding: '10px 12px', fontSize: 13.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }
const L = ({ label, required, children }) => (
  <label className="block">
    <span className="text-xs font-bold block mb-1.5" style={{ color: 'var(--text-body)' }}>
      {label}{required && <span style={{ color: 'var(--color-danger-500)' }}> *</span>}
    </span>
    {children}
  </label>
)
