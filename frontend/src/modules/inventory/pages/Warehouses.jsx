import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Warehouse, Plus, Trash2, Star, Boxes, MapPin, ChevronRight, X, Check, Printer, AlertTriangle, Gauge, HelpCircle, Pencil, Thermometer, Droplets } from 'lucide-react'
import { inventoryApi, INV_ACCENT, WAREHOUSE_TYPES, LOCATION_TYPES, CAPACITY_UOMS, fmtQty } from '@/services/inventoryApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'
import { ConfirmModal } from '@/components/ui/SearchPicker'
import LocationLabelSheet from '../components/LocationLabelSheet'

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
  const [editing, setEditing] = useState(null)
  const [confirmDelete, setConfirmDelete] = useState(null)
  const [expanded, setExpanded] = useState(null)
  const [envExpanded, setEnvExpanded] = useState(null)
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

              {(w.track_environment || w.type === 'cold_storage') && (
                <button onClick={() => setEnvExpanded(envExpanded === w.id ? null : w.id)} aria-label="Environment"
                  title="Environment"
                  className="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                  style={{ background: envExpanded === w.id ? `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)` : 'var(--bg-input)', border: '1px solid var(--border)', color: envExpanded === w.id ? INV_ACCENT : 'var(--text-muted)' }}>
                  <Thermometer size={14} />
                </button>
              )}
              <button onClick={() => setExpanded(expanded === w.id ? null : w.id)} aria-label="Show locations"
                className="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                <ChevronRight size={14} style={{ transform: expanded === w.id ? 'rotate(90deg)' : 'none', transition: 'transform .15s' }} />
              </button>
              {isAdmin && (
                <button onClick={() => setEditing(w)} aria-label={`Edit ${w.name}`}
                  className="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                  style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                  <Pencil size={13} />
                </button>
              )}
              {isAdmin && (
                <button onClick={() => setConfirmDelete(w)} aria-label={`Delete ${w.name}`}
                  className="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                  style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--color-danger-500)' }}>
                  <Trash2 size={13} />
                </button>
              )}
            </div>

            {envExpanded === w.id && <EnvPanel warehouse={w} />}
            {expanded === w.id && <LocationPanel warehouse={w} isAdmin={isAdmin} />}
          </section>
        ))}
      </div>

      {creating && <WarehouseModal onClose={() => setCreating(false)} onSaved={refresh} />}
      {editing && <WarehouseModal warehouse={editing} onClose={() => setEditing(null)} onSaved={refresh} />}

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
  const [form, setForm] = useState({ name: '', type: 'bin', parent_id: '', capacity: '', capacity_uom: 'units' })
  const [err, setErr] = useState('')
  const [printing, setPrinting] = useState(false)
  // The plain list answers "what bins exist"; occupancy answers "how full, and
  // what's wrong" — a different question, so it's a mode rather than always-on.
  const [occupancy, setOccupancy] = useState(false)

  const { data: locations = [] } = useQuery({
    queryKey: ['inv-locations', warehouse.id], queryFn: () => inventoryApi.warehouses.locations(warehouse.id),
  })
  const bust = () => {
    qc.invalidateQueries({ queryKey: ['inv-locations', warehouse.id] })
    qc.invalidateQueries({ queryKey: ['inv-layout', warehouse.id] })
    qc.invalidateQueries({ queryKey: ['inv-warehouses'] })
  }

  const add = useMutation({
    mutationFn: () => inventoryApi.warehouses.createLocation(warehouse.id, {
      name: form.name.trim(), type: form.type, parent_id: form.parent_id || undefined,
      // A capacity is only meaningful with the unit it counts, so the two travel
      // together or not at all.
      capacity: form.capacity === '' ? undefined : Number(form.capacity),
      capacity_uom: form.capacity === '' ? undefined : form.capacity_uom,
    }),
    onSuccess: () => { setForm({ name: '', type: 'bin', parent_id: '', capacity: '', capacity_uom: 'units' }); setErr(''); bust() },
    onError: (e) => setErr(e?.message || 'Could not add that location.'),
  })
  const del = useMutation({
    mutationFn: (lid) => inventoryApi.warehouses.deleteLocation(warehouse.id, lid),
    onSuccess: () => { setErr(''); bust() },
    onError: (e) => setErr(e?.message || 'Could not delete that location.'),
  })

  return (
    <div className="px-4 pb-4" style={{ borderTop: '1px solid var(--border)' }}>
      <div className="flex flex-wrap items-center gap-2 mt-3 mb-2">
        <p className="text-[10px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>
          Storage layout — zone › rack › shelf › bin
        </p>
        {locations.length > 0 && (
          <button onClick={() => setOccupancy(v => !v)}
            className="flex items-center gap-1 text-[10px] font-bold px-2 py-1 rounded-lg"
            style={{
              border: `1px solid ${occupancy ? INV_ACCENT : 'var(--border)'}`,
              background: occupancy ? `color-mix(in srgb, ${INV_ACCENT} 12%, transparent)` : 'transparent',
              color: occupancy ? INV_ACCENT : 'var(--text-muted)',
            }}>
            <Gauge size={11} /> Occupancy
          </button>
        )}
        {/* A bin with no label on it is a bin nobody can scan, so printing sits
            next to the list rather than behind a menu somewhere else. */}
        {locations.length > 0 && (
          <button onClick={() => setPrinting(true)}
            className="ml-auto flex items-center gap-1 text-[10px] font-bold px-2 py-1 rounded-lg"
            style={{ border: '1px solid var(--border)', color: INV_ACCENT }}>
            <Printer size={11} /> Print labels
          </button>
        )}
      </div>

      {occupancy && <OccupancyView warehouseId={warehouse.id} />}

      {!occupancy && (
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
      )}

      {err && <p className="text-[11px] mb-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      {isAdmin && !occupancy && (
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
          {/* Capacity and its unit go together — a number with no unit can't be
              compared to anything, so entering one enables the other. */}
          <input type="number" min={0} step="0.001" value={form.capacity}
            onChange={e => setForm(f => ({ ...f, capacity: e.target.value }))} placeholder="Limit"
            title="How much this bin holds (optional)"
            className="rounded-lg outline-none text-right" style={{ width: 80, padding: '7px 10px', fontSize: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
          <div style={{ width: 100 }}>
            <Select size="sm" value={form.capacity_uom} onChange={v => setForm(f => ({ ...f, capacity_uom: v }))}
              disabled={form.capacity === ''} options={CAPACITY_UOMS.map(u => ({ value: u.value, label: u.label }))} />
          </div>
          <button type="submit" disabled={!form.name.trim() || add.isPending}
            className="px-3 rounded-lg disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }} aria-label="Add location">
            <Plus size={13} />
          </button>
        </form>
      )}

      {printing && (
        <LocationLabelSheet locations={locations} warehouseName={warehouse.name} onClose={() => setPrinting(false)} />
      )}
    </div>
  )
}

/* ── Occupancy ────────────────────────────────────────────────── */

const PROBLEM_LABEL = {
  unlocated:            'Stock nobody can find',
  over_capacity:        'Bins over their limit',
  mixed_bins:           'More than one item in a bin',
  missing_product_data: 'Items with no size on file',
}

/**
 * How full the building is, and what's wrong with the picture. `measure` decides
 * what "full" counts — a rack beam's limit is weight, a pick face's is units.
 */
function OccupancyView({ warehouseId }) {
  const [measure, setMeasure] = useState('units')

  const { data, isLoading } = useQuery({
    queryKey: ['inv-layout', warehouseId, measure],
    queryFn: () => inventoryApi.warehouses.layout(warehouseId, measure),
  })

  if (isLoading || !data) {
    return <div className="h-24 rounded-xl animate-pulse mb-3" style={{ background: 'var(--bg-input)' }} />
  }

  const t = data.totals

  return (
    <div className="mb-3">
      <div className="flex flex-wrap items-center gap-2 mb-2">
        <div style={{ width: 130 }}>
          <Select size="sm" value={measure} onChange={setMeasure}
            options={CAPACITY_UOMS.map(u => ({ value: u.value, label: `By ${u.label.toLowerCase()}` }))} />
        </div>
        <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
          {CAPACITY_UOMS.find(u => u.value === measure)?.hint}
        </span>
      </div>

      {/* The four numbers a warehouse is judged on. Utilisation counts only
          measured bins; unmeasured ones are reported separately so the figure
          isn't built out of unknowns. */}
      <div className="grid gap-2 mb-3" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(88px,1fr))' }}>
        <Tile label="Utilisation" value={t.utilisation == null ? '—' : `${t.utilisation}%`} />
        <Tile label="Bins" value={t.bins} />
        <Tile label="Over limit" value={t.over_capacity} danger={t.over_capacity > 0} />
        <Tile label="Empty" value={t.empty} />
        {t.unmeasured > 0 && <Tile label="Unmeasured" value={t.unmeasured} muted />}
      </div>

      {data.problems?.length > 0 && (
        <div className="rounded-xl px-3 py-2.5 mb-3"
          style={{ background: 'color-mix(in srgb, #f59e0b 9%, transparent)', border: '1px solid #f59e0b' }}>
          {data.problems.map(p => (
            <div key={p.kind} className="mb-1.5 last:mb-0">
              <p className="flex items-center gap-1.5 text-[11px] font-bold" style={{ color: '#b45309' }}>
                <AlertTriangle size={11} /> {PROBLEM_LABEL[p.kind] || p.kind}
                {p.count > 1 ? ` (${p.count})` : ''}
              </p>
              <p className="text-[11px] ml-4" style={{ color: 'var(--text-body)' }}>{p.detail}</p>
            </div>
          ))}
        </div>
      )}

      <div className="space-y-1">
        {data.tree.map(n => <BinRow key={n.id} node={n} />)}
        {data.tree.length === 0 && (
          <p className="text-[11px] py-2" style={{ color: 'var(--text-muted)' }}>
            No bins mapped yet — add one below to start tracking occupancy.
          </p>
        )}
      </div>
    </div>
  )
}

/** One location and everything under it, indented by depth. */
function BinRow({ node }) {
  const pct = node.utilisation
  const leaf = !node.children?.length
  // A colour only where a real comparison exists: amber past 80%, red over.
  const barColor = node.over ? 'var(--color-danger-500)' : pct >= 80 ? '#f59e0b' : INV_ACCENT

  return (
    <>
      <div className="flex items-center gap-2 px-2.5 py-1.5 rounded-lg"
        style={{ background: 'var(--bg-input)', marginLeft: node.depth * 14 }}>
        <MapPin size={11} style={{ color: node.over ? 'var(--color-danger-500)' : 'var(--text-muted)', flexShrink: 0 }} />
        <span className="text-xs truncate" style={{ color: 'var(--text-h)', minWidth: 90 }}>{node.name}</span>
        <span className="text-[9px] px-1.5 py-0.5 rounded capitalize" style={{ background: 'var(--bg-card)', color: 'var(--text-muted)' }}>{node.type}</span>

        {node.skus > 1 && (
          <span className="text-[9px] px-1.5 py-0.5 rounded font-bold" style={{ background: 'color-mix(in srgb, #f59e0b 15%, transparent)', color: '#b45309' }}>
            {node.skus} items
          </span>
        )}

        <span className="ml-auto text-[11px] tabular-nums" style={{ color: 'var(--text-muted)' }}>
          {fmtQty(node.quantity)} on hand
        </span>

        {/* Only a bin with a comparable limit gets a fill bar. Everything else
            says why it can't be measured rather than showing a misleading one. */}
        {leaf && (
          node.comparable ? (
            <span className="flex items-center gap-1.5" style={{ width: 120 }}>
              <span className="flex-1 h-1.5 rounded-full overflow-hidden" style={{ background: 'var(--bg-card)' }}>
                <span className="block h-full rounded-full" style={{ width: `${Math.min(pct, 100)}%`, background: barColor }} />
              </span>
              <span className="text-[10px] tabular-nums font-bold" style={{ color: barColor, width: 34, textAlign: 'right' }}>{pct}%</span>
            </span>
          ) : (
            <span className="flex items-center gap-1 text-[10px]" style={{ color: 'var(--text-muted)', width: 120 }} title="No comparable limit set for this bin">
              <HelpCircle size={10} /> {node.capacity != null ? `limit in ${node.capacity_uom}` : 'no limit set'}
            </span>
          )
        )}
      </div>
      {node.children?.map(c => <BinRow key={c.id} node={c} />)}
    </>
  )
}

const Tile = ({ label, value, danger, muted }) => (
  <div className="rounded-xl px-2.5 py-2" style={{ background: 'var(--bg-input)' }}>
    <p className="text-[9px] uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{label}</p>
    <p className="text-sm font-black tabular-nums"
      style={{ color: danger ? 'var(--color-danger-500)' : muted ? 'var(--text-muted)' : 'var(--text-h)' }}>{value}</p>
  </div>
)

/* ── Environment monitoring ───────────────────────────────────── */

function EnvPanel({ warehouse }) {
  const qc = useQueryClient()
  const [temp, setTemp] = useState('')
  const [humidity, setHumidity] = useState('')
  const [note, setNote] = useState('')
  const [err, setErr] = useState('')

  const { data: readings = [], isLoading } = useQuery({
    queryKey: ['inv-env', warehouse.id], queryFn: () => inventoryApi.warehouses.environment(warehouse.id),
  })
  const band = (min, max) => (min == null && max == null) ? '—' : `${min ?? '−'}…${max ?? '−'}`

  const log = useMutation({
    mutationFn: () => inventoryApi.warehouses.logEnvironment(warehouse.id, {
      temperature: temp === '' ? null : Number(temp),
      humidity: humidity === '' ? null : Number(humidity),
      note: note || null,
    }),
    onSuccess: () => { setTemp(''); setHumidity(''); setNote(''); setErr(''); qc.invalidateQueries({ queryKey: ['inv-env', warehouse.id] }) },
    onError: (e) => setErr(e?.message || 'Could not log the reading.'),
  })

  return (
    <div className="px-4 pb-4" style={{ borderTop: '1px solid var(--border)' }}>
      <div className="flex flex-wrap items-center gap-3 mt-3 mb-2 text-[11px]" style={{ color: 'var(--text-muted)' }}>
        <span className="flex items-center gap-1"><Thermometer size={12} style={{ color: INV_ACCENT }} /> Band {band(warehouse.temp_min, warehouse.temp_max)}°</span>
        <span className="flex items-center gap-1"><Droplets size={12} style={{ color: INV_ACCENT }} /> Band {band(warehouse.humidity_min, warehouse.humidity_max)}%</span>
      </div>

      <form onSubmit={e => { e.preventDefault(); if (temp !== '' || humidity !== '') log.mutate() }} className="flex flex-wrap gap-2 mb-3">
        <input type="number" step="0.1" value={temp} onChange={e => setTemp(e.target.value)} placeholder="Temp °"
          className="rounded-lg outline-none" style={{ width: 90, padding: '7px 10px', fontSize: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
        <input type="number" step="0.1" value={humidity} onChange={e => setHumidity(e.target.value)} placeholder="Humidity %"
          className="rounded-lg outline-none" style={{ width: 100, padding: '7px 10px', fontSize: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
        <input value={note} onChange={e => setNote(e.target.value)} placeholder="Note (optional)"
          className="flex-1 rounded-lg outline-none" style={{ minWidth: 120, padding: '7px 10px', fontSize: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
        <button type="submit" disabled={(temp === '' && humidity === '') || log.isPending} className="px-3 rounded-lg text-xs font-bold disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }}>Log</button>
      </form>

      {err && <p className="text-[11px] mb-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      {isLoading ? <div className="h-12 rounded-lg animate-pulse" style={{ background: 'var(--bg-input)' }} /> : (
        <ul className="space-y-1">
          {readings.length === 0 && <li className="text-[11px] py-1" style={{ color: 'var(--text-muted)' }}>No readings logged yet.</li>}
          {readings.map(r => (
            <li key={r.id} className="flex items-center gap-2 px-2.5 py-1.5 rounded-lg text-xs" style={{ background: 'var(--bg-input)' }}>
              <span className="w-2 h-2 rounded-full shrink-0" style={{ background: r.in_band ? '#10B981' : 'var(--color-danger-500)' }} />
              <span style={{ color: 'var(--text-h)' }}>{r.temperature != null ? `${Number(r.temperature)}°` : ''} {r.humidity != null ? `${Number(r.humidity)}%` : ''}</span>
              {!r.in_band && <span className="text-[10px] font-bold" style={{ color: 'var(--color-danger-500)' }}>OUT OF RANGE</span>}
              {r.note && <span className="truncate" style={{ color: 'var(--text-muted)' }}>· {r.note}</span>}
              <span className="ml-auto text-[10px]" style={{ color: 'var(--text-muted)' }}>{r.recorder?.name || ''} · {r.recorded_at ? new Date(r.recorded_at).toLocaleString() : ''}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/* ── New warehouse ────────────────────────────────────────────── */

function WarehouseModal({ warehouse, onClose, onSaved }) {
  const editing = Boolean(warehouse?.id)
  const [form, setForm] = useState({
    name: warehouse?.name || '', code: warehouse?.code || '', type: warehouse?.type || 'godown',
    address: warehouse?.address || '', is_default: warehouse?.is_default || false,
    temp_min: warehouse?.temp_min ?? '', temp_max: warehouse?.temp_max ?? '',
    humidity_min: warehouse?.humidity_min ?? '', humidity_max: warehouse?.humidity_max ?? '',
    track_environment: warehouse?.track_environment || false,
    require_move_gps: warehouse?.require_move_gps || false,
    require_move_photo: warehouse?.require_move_photo || false,
  })
  const [err, setErr] = useState('')
  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const num = (v) => v === '' || v === null ? null : Number(v)
  const save = useMutation({
    mutationFn: () => {
      const payload = {
        name: form.name.trim(), code: form.code.trim() || undefined, type: form.type,
        address: form.address.trim() || undefined, is_default: form.is_default,
        temp_min: num(form.temp_min), temp_max: num(form.temp_max),
        humidity_min: num(form.humidity_min), humidity_max: num(form.humidity_max),
        track_environment: form.track_environment,
        require_move_gps: form.require_move_gps, require_move_photo: form.require_move_photo,
      }
      return editing ? inventoryApi.warehouses.update(warehouse.id, payload) : inventoryApi.warehouses.create(payload)
    },
    onSuccess: () => { onSaved?.(); onClose() },
    onError: (e) => setErr(e?.message || 'Could not save the warehouse.'),
  })

  return (
    <div className="fixed inset-0 z-[55] flex items-start justify-center p-4 overflow-y-auto"
      style={{ background: 'rgba(15,23,42,0.55)', backdropFilter: 'blur(2px)' }} onClick={onClose}>
      <form onClick={e => e.stopPropagation()} onSubmit={e => { e.preventDefault(); if (form.name.trim()) save.mutate() }}
        className="w-full rounded-2xl overflow-hidden my-8" style={{ maxWidth: 480, background: 'var(--bg-global)', boxShadow: '0 24px 70px rgba(0,0,0,0.45)' }}>
        <header className="flex items-center gap-2.5 px-5 py-4" style={{ background: `linear-gradient(120deg, ${INV_ACCENT}, #059669)` }}>
          <Boxes size={18} style={{ color: '#fff' }} />
          <h2 className="font-bold text-white" style={{ fontSize: 15 }}>{editing ? 'Edit Warehouse' : 'New Warehouse'}</h2>
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

          {/* Environment & compliance — matters for cold stores and controlled
              rooms; the switches turn a site into a field-audited one. */}
          <div className="rounded-xl p-3 space-y-3" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            <p className="text-[10px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Environment &amp; compliance</p>
            <div className="grid grid-cols-2 gap-3">
              <L label="Temp min (°)"><input type="number" step="0.1" value={form.temp_min} onChange={e => sf('temp_min', e.target.value)} className={I} style={IS} placeholder="e.g. 2" /></L>
              <L label="Temp max (°)"><input type="number" step="0.1" value={form.temp_max} onChange={e => sf('temp_max', e.target.value)} className={I} style={IS} placeholder="e.g. 8" /></L>
              <L label="Humidity min (%)"><input type="number" step="0.1" value={form.humidity_min} onChange={e => sf('humidity_min', e.target.value)} className={I} style={IS} /></L>
              <L label="Humidity max (%)"><input type="number" step="0.1" value={form.humidity_max} onChange={e => sf('humidity_max', e.target.value)} className={I} style={IS} /></L>
            </div>
            {[['track_environment', 'Track temperature / humidity at this site'],
              ['require_move_gps', 'Require a GPS location on every stock move here'],
              ['require_move_photo', 'Require a photo on every stock move here']].map(([k, label]) => (
              <label key={k} className="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" checked={form[k]} onChange={e => sf(k, e.target.checked)} style={{ accentColor: INV_ACCENT, width: 15, height: 15 }} />
                <span className="text-xs font-semibold" style={{ color: 'var(--text-h)' }}>{label}</span>
              </label>
            ))}
          </div>

          {err && <p className="text-xs px-3 py-2 rounded-lg" style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>}
        </div>

        <footer className="flex justify-end gap-2 px-5 py-4" style={{ borderTop: '1px solid var(--border)', background: 'var(--bg-card)' }}>
          <button type="button" onClick={onClose} className="text-sm font-semibold px-4 py-2.5 rounded-xl"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
          <button type="submit" disabled={!form.name.trim() || save.isPending}
            className="flex items-center gap-1.5 text-sm font-bold px-5 py-2.5 rounded-xl disabled:opacity-40"
            style={{ background: INV_ACCENT, color: '#fff' }}>
            <Check size={16} /> {save.isPending ? 'Saving…' : (editing ? 'Save' : 'Create')}
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
