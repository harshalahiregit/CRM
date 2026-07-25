import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Boxes, Plus, Trash2, CalendarClock, Hash, Lock, AlertTriangle, X, Check, Search,
} from 'lucide-react'
import { inventoryApi, INV_ACCENT, fmtQty, money } from '@/services/inventoryApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'

/**
 * Traceability — batches, serial numbers, reservations and shelf life, over one
 * shell. They belong together: a serial belongs to a batch, a batch has an
 * expiry, and a reservation is a claim on the stock those batches represent.
 *
 * Deleting a batch or serial is master data destruction (admin only); a
 * reservation can be released by an admin or by whoever placed it. The backend
 * enforces all of it — this only hides what the viewer can't do.
 */

const TABS = [
  { key: 'batches',      label: 'Batches',      icon: Boxes },
  { key: 'expiry',       label: 'Expiry',       icon: CalendarClock },
  { key: 'serials',      label: 'Serial numbers', icon: Hash },
  { key: 'reservations', label: 'Reservations', icon: Lock },
]

const QUALITY = [
  { value: 'passed', label: 'Passed' }, { value: 'pending', label: 'Pending QC' },
  { value: 'quarantine', label: 'Quarantine' }, { value: 'failed', label: 'Failed' },
]
const QUALITY_COLOR = { passed: '#10B981', pending: '#f59e0b', quarantine: '#8b5cf6', failed: '#ef4444' }

export default function InventoryTraceability() {
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [tab, setTab] = useState(TABS[0].key)

  return (
    <div>
      <header className="flex flex-wrap items-center gap-2 mb-4">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)` }}>
          <Boxes size={17} style={{ color: INV_ACCENT }} />
        </span>
        <div>
          <h1 className="text-lg font-bold leading-tight" style={{ color: 'var(--text-h)' }}>Traceability</h1>
          <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
            Batches, shelf life, serial numbers and stock commitments.
          </p>
        </div>
      </header>

      <div className="flex items-center gap-1 mb-4 overflow-x-auto" style={{ borderBottom: '1px solid var(--border)' }}>
        {TABS.map(t => {
          const Icon = t.icon
          return (
            <button key={t.key} onClick={() => setTab(t.key)}
              className="flex items-center gap-1.5 px-3 py-2 text-xs font-bold whitespace-nowrap"
              style={{
                color: tab === t.key ? INV_ACCENT : 'var(--text-muted)',
                borderBottom: `2px solid ${tab === t.key ? INV_ACCENT : 'transparent'}`,
                marginBottom: -1,
              }}>
              <Icon size={13} /> {t.label}
            </button>
          )
        })}
      </div>

      {tab === 'batches' && <BatchesTab isAdmin={isAdmin} />}
      {tab === 'expiry' && <ExpiryTab />}
      {tab === 'serials' && <SerialsTab isAdmin={isAdmin} />}
      {tab === 'reservations' && <ReservationsTab isAdmin={isAdmin} />}
    </div>
  )
}

/* ══ Batches ══════════════════════════════════════════════════════ */

function BatchesTab({ isAdmin }) {
  const qc = useQueryClient()
  const [adding, setAdding] = useState(false)
  const [search, setSearch] = useState('')
  const [err, setErr] = useState('')
  const [draft, setDraft] = useState({ product_id: '', warehouse_id: '', batch_no: '', expiry_date: '', received_qty: '', quality_status: 'passed', cost_price: '' })

  const { data: rows = [], isLoading } = useQuery({ queryKey: ['inv-batches', search], queryFn: () => inventoryApi.batches.list(search ? { search } : {}) })
  const { data: products = [] } = useQuery({ queryKey: ['inv-products', {}], queryFn: () => inventoryApi.products.list() })
  const { data: warehouses = [] } = useQuery({ queryKey: ['inv-warehouses'], queryFn: inventoryApi.warehouses.list })

  const refresh = () => qc.invalidateQueries({ queryKey: ['inv-batches'] })

  const add = useMutation({
    mutationFn: () => inventoryApi.batches.create({
      ...draft,
      product_id: Number(draft.product_id),
      warehouse_id: draft.warehouse_id ? Number(draft.warehouse_id) : null,
      received_qty: Number(draft.received_qty) || 0,
      cost_price: draft.cost_price === '' ? null : Number(draft.cost_price),
      expiry_date: draft.expiry_date || null,
    }),
    onSuccess: () => { setAdding(false); setErr(''); setDraft({ product_id: '', warehouse_id: '', batch_no: '', expiry_date: '', received_qty: '', quality_status: 'passed', cost_price: '' }); refresh() },
    onError: (e) => setErr(e?.message || 'Could not create that batch.'),
  })
  const setQuality = useMutation({
    mutationFn: ({ id, quality_status }) => inventoryApi.batches.update(id, { quality_status }),
    onSuccess: refresh,
    onError: (e) => setErr(e?.message || 'Could not update that batch.'),
  })
  const del = useMutation({ mutationFn: (id) => inventoryApi.batches.remove(id), onSuccess: refresh, onError: (e) => setErr(e?.message || 'Could not delete that batch.') })
  const recall = useMutation({ mutationFn: ({ id, reason }) => inventoryApi.batches.recall(id, reason), onSuccess: refresh, onError: (e) => setErr(e?.message || 'Could not recall that batch.') })
  const lift = useMutation({ mutationFn: (id) => inventoryApi.batches.liftRecall(id), onSuccess: refresh, onError: (e) => setErr(e?.message || 'Could not lift the recall.') })
  const doRecall = (b) => {
    const reason = window.prompt(`Recall batch ${b.batch_no}?\nThis quarantines the remaining stock and alerts the team.\n\nReason:`)
    if (reason && reason.trim()) recall.mutate({ id: b.id, reason: reason.trim() })
  }

  return (
    <>
      <Toolbar search={search} setSearch={setSearch} placeholder="Search batch / lot / vendor batch…"
        action={<button onClick={() => setAdding(a => !a)} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl" style={{ background: INV_ACCENT, color: '#fff' }}><Plus size={13} /> New batch</button>} />

      {adding && (
        <Panel>
          <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))' }}>
            <Fld label="Item *"><Select size="sm" value={draft.product_id} onChange={v => setDraft(d => ({ ...d, product_id: v }))} placeholder="Choose item"
              options={products.map(p => ({ value: String(p.id), label: `${p.sku} · ${p.name}` }))} /></Fld>
            <Fld label="Batch no *"><input value={draft.batch_no} onChange={e => setDraft(d => ({ ...d, batch_no: e.target.value }))} style={INP} /></Fld>
            <Fld label="Warehouse"><Select size="sm" value={draft.warehouse_id} onChange={v => setDraft(d => ({ ...d, warehouse_id: v }))} placeholder="Any"
              options={[{ value: '', label: 'Any' }, ...warehouses.map(w => ({ value: String(w.id), label: w.name }))]} /></Fld>
            <Fld label="Received qty *"><input type="number" value={draft.received_qty} onChange={e => setDraft(d => ({ ...d, received_qty: e.target.value }))} style={INP} /></Fld>
            <Fld label="Expiry date"><input type="date" value={draft.expiry_date} onChange={e => setDraft(d => ({ ...d, expiry_date: e.target.value }))} style={INP} /></Fld>
            <Fld label="Cost price"><input type="number" value={draft.cost_price} onChange={e => setDraft(d => ({ ...d, cost_price: e.target.value }))} style={INP} /></Fld>
            <Fld label="Quality"><Select size="sm" value={draft.quality_status} onChange={v => setDraft(d => ({ ...d, quality_status: v }))} options={QUALITY} /></Fld>
          </div>
          {err && <p className="text-[11px] mt-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
          <div className="flex gap-2 mt-3">
            <button disabled={!draft.product_id || !draft.batch_no || add.isPending} onClick={() => add.mutate()}
              className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }}>
              <Check size={13} /> {add.isPending ? 'Saving…' : 'Create batch'}
            </button>
            <button onClick={() => { setAdding(false); setErr('') }} className="text-xs font-bold px-3 py-2 rounded-xl" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
          </div>
        </Panel>
      )}

      {err && !adding && <p className="text-[11px] mb-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      <Table
        head={['Batch', 'Item', 'Warehouse', 'Remaining', 'Expiry', 'Quality', '']}
        loading={isLoading}
        empty="No batches yet. Create one when stock arrives with a lot number."
        rows={rows.map(b => [
          <span key="b"><span className="block font-mono font-bold text-[11px]" style={{ color: 'var(--text-h)' }}>{b.batch_no}</span>
            {b.lot_number && <span className="block text-[9px]" style={{ color: 'var(--text-muted)' }}>lot {b.lot_number}</span>}
            {b.is_recalled && <span className="inline-block mt-0.5 text-[8.5px] font-bold px-1.5 py-0.5 rounded-full" title={b.recall_reason || 'Recalled'}
              style={{ background: 'color-mix(in srgb, var(--color-danger-500) 16%, transparent)', color: 'var(--color-danger-500)' }}>⚠ RECALLED</span>}</span>,
          <span key="i"><span className="block font-semibold" style={{ color: 'var(--text-h)' }}>{b.product?.name}</span>
            <span className="block text-[9px]" style={{ color: 'var(--text-muted)' }}>{b.product?.sku}</span></span>,
          b.warehouse?.name || '—',
          <span key="r" className="tabular-nums font-bold" style={{ color: 'var(--text-h)' }}>{fmtQty(b.remaining_qty)} <span className="font-normal text-[10px]" style={{ color: 'var(--text-muted)' }}>/ {fmtQty(b.received_qty)}</span></span>,
          <ExpiryCell key="e" batch={b} />,
          <Select key="q" size="sm" value={b.quality_status} onChange={v => setQuality.mutate({ id: b.id, quality_status: v })} options={QUALITY}
            buttonStyle={{ color: QUALITY_COLOR[b.quality_status], background: `color-mix(in srgb, ${QUALITY_COLOR[b.quality_status]} 15%, transparent)`, border: 'none', borderRadius: 999, padding: '3px 9px', fontSize: 10.5, fontWeight: 700 }} />,
          <span key="d" className="inline-flex items-center gap-2 justify-end">
            {b.is_recalled
              ? (isAdmin && <button onClick={() => lift.mutate(b.id)} title="Lift recall" className="text-[10px] font-bold px-2 py-1 rounded-lg" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Lift</button>)
              : <button onClick={() => doRecall(b)} title="Recall this batch" className="hover:opacity-60"><AlertTriangle size={13} style={{ color: '#f59e0b' }} /></button>}
            {isAdmin && <button onClick={() => del.mutate(b.id)} aria-label="Delete batch" className="hover:opacity-60"><Trash2 size={13} style={{ color: 'var(--color-danger-500)' }} /></button>}
          </span>,
        ])}
      />
    </>
  )
}

function ExpiryCell({ batch }) {
  if (!batch.expiry_date) return <span style={{ color: 'var(--text-muted)' }}>—</span>
  const d = batch.days_to_expiry
  const color = batch.is_expired ? '#ef4444' : d <= 30 ? '#f59e0b' : 'var(--text-body)'
  return (
    <span style={{ color }}>
      {String(batch.expiry_date).split('T')[0]}
      <span className="block text-[9px]">{batch.is_expired ? 'expired' : `${d} days left`}</span>
    </span>
  )
}

/* ══ Expiry dashboard ═════════════════════════════════════════════ */

function ExpiryTab() {
  const [days, setDays] = useState(30)
  const { data, isLoading } = useQuery({ queryKey: ['inv-expiry', days], queryFn: () => inventoryApi.expiry(days) })

  return (
    <>
      <div className="flex items-center gap-2 mb-4">
        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Warn me about stock expiring within</span>
        <div style={{ width: 130 }}>
          <Select size="sm" value={String(days)} onChange={v => setDays(Number(v))}
            options={[7, 15, 30, 60, 90].map(d => ({ value: String(d), label: `${d} days` }))} />
        </div>
      </div>

      {isLoading && <div className="h-32 rounded-2xl animate-pulse" style={{ background: 'var(--bg-card)' }} />}

      {data && (
        <>
          <div className="grid gap-3 mb-4" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))' }}>
            <StatCard label="Already expired" count={data.expired?.count} value={data.expired?.value} color="#ef4444" />
            <StatCard label={`Expiring in ${data.window_days} days`} count={data.expiring?.count} value={data.expiring?.value} color="#f59e0b" />
            <StatCard label="Batches with a date" count={data.total_dated} color={INV_ACCENT} />
          </div>

          <Section title="Expired — act now" rows={data.expired?.batches || []} color="#ef4444" />
          <Section title={`Expiring within ${data.window_days} days`} rows={data.expiring?.batches || []} color="#f59e0b" />
        </>
      )}
    </>
  )
}

function StatCard({ label, count, value, color }) {
  return (
    <div className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: `1px solid ${color}` }}>
      <p className="text-[11px] font-bold uppercase tracking-wide mb-1" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-2xl font-black tabular-nums" style={{ color }}>{count ?? 0}</p>
      {value != null && <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{money(value)} at risk</p>}
    </div>
  )
}

function Section({ title, rows, color }) {
  if (!rows.length) return null
  return (
    <div className="mb-4">
      <p className="flex items-center gap-1.5 text-xs font-bold mb-2" style={{ color }}>
        <AlertTriangle size={13} /> {title}
      </p>
      <Table
        head={['Batch', 'Item', 'Warehouse', 'Remaining', 'Expiry']}
        rows={rows.map(b => [
          <span key="b" className="font-mono font-bold text-[11px]" style={{ color: 'var(--text-h)' }}>{b.batch_no}</span>,
          <span key="i"><span className="block font-semibold" style={{ color: 'var(--text-h)' }}>{b.product?.name}</span>
            <span className="block text-[9px]" style={{ color: 'var(--text-muted)' }}>{b.product?.sku}</span></span>,
          b.warehouse?.name || '—',
          fmtQty(b.remaining_qty),
          <ExpiryCell key="e" batch={b} />,
        ])}
      />
    </div>
  )
}

/* ══ Serials ══════════════════════════════════════════════════════ */

const SERIAL_STATUS = [
  { value: 'in_stock', label: 'In stock' }, { value: 'issued', label: 'Issued' },
  { value: 'returned', label: 'Returned' }, { value: 'scrapped', label: 'Scrapped' },
]
const SERIAL_COLOR = { in_stock: '#10B981', issued: '#3b82f6', returned: '#f59e0b', scrapped: '#ef4444' }

function SerialsTab({ isAdmin }) {
  const qc = useQueryClient()
  const [search, setSearch] = useState('')
  const [adding, setAdding] = useState(false)
  const [err, setErr] = useState('')
  const [draft, setDraft] = useState({ product_id: '', warehouse_id: '', numbers: '', warranty_until: '' })

  const { data: rows = [], isLoading } = useQuery({ queryKey: ['inv-serials', search], queryFn: () => inventoryApi.serials.list(search ? { search } : {}) })
  const { data: products = [] } = useQuery({ queryKey: ['inv-products', {}], queryFn: () => inventoryApi.products.list() })
  const { data: warehouses = [] } = useQuery({ queryKey: ['inv-warehouses'], queryFn: inventoryApi.warehouses.list })
  const refresh = () => qc.invalidateQueries({ queryKey: ['inv-serials'] })

  const add = useMutation({
    mutationFn: () => inventoryApi.serials.create({
      product_id: Number(draft.product_id),
      warehouse_id: draft.warehouse_id ? Number(draft.warehouse_id) : null,
      // Paste a whole intake — one per line or comma separated.
      serial_no: draft.numbers.split(/[\n,]+/).map(s => s.trim()).filter(Boolean),
      warranty_until: draft.warranty_until || null,
    }),
    onSuccess: (r) => {
      setErr(r.skipped?.length ? `${r.created} added · ${r.skipped.length} already existed (${r.skipped.slice(0, 3).join(', ')})` : '')
      setAdding(false); setDraft({ product_id: '', warehouse_id: '', numbers: '', warranty_until: '' }); refresh()
    },
    onError: (e) => setErr(e?.message || 'Could not add those serial numbers.'),
  })
  const setStatus = useMutation({ mutationFn: ({ id, status }) => inventoryApi.serials.update(id, { status }), onSuccess: refresh })
  const del = useMutation({ mutationFn: (id) => inventoryApi.serials.remove(id), onSuccess: refresh, onError: (e) => setErr(e?.message || 'Could not delete that serial.') })

  return (
    <>
      <Toolbar search={search} setSearch={setSearch} placeholder="Search serial or customer…"
        action={<button onClick={() => setAdding(a => !a)} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl" style={{ background: INV_ACCENT, color: '#fff' }}><Plus size={13} /> Add serials</button>} />

      {adding && (
        <Panel>
          <div className="grid gap-2.5 mb-2.5" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))' }}>
            <Fld label="Item *"><Select size="sm" value={draft.product_id} onChange={v => setDraft(d => ({ ...d, product_id: v }))} placeholder="Choose item"
              options={products.map(p => ({ value: String(p.id), label: `${p.sku} · ${p.name}` }))} /></Fld>
            <Fld label="Warehouse"><Select size="sm" value={draft.warehouse_id} onChange={v => setDraft(d => ({ ...d, warehouse_id: v }))} placeholder="Any"
              options={[{ value: '', label: 'Any' }, ...warehouses.map(w => ({ value: String(w.id), label: w.name }))]} /></Fld>
            <Fld label="Warranty until"><input type="date" value={draft.warranty_until} onChange={e => setDraft(d => ({ ...d, warranty_until: e.target.value }))} style={INP} /></Fld>
          </div>
          <Fld label="Serial numbers * — one per line, or comma separated">
            <textarea value={draft.numbers} onChange={e => setDraft(d => ({ ...d, numbers: e.target.value }))} rows={4}
              placeholder={'SN-0001\nSN-0002'} style={{ ...INP, resize: 'vertical', fontFamily: 'monospace' }} />
          </Fld>
          {err && <p className="text-[11px] mt-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
          <div className="flex gap-2 mt-3">
            <button disabled={!draft.product_id || !draft.numbers.trim() || add.isPending} onClick={() => add.mutate()}
              className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }}>
              <Check size={13} /> {add.isPending ? 'Adding…' : 'Add'}
            </button>
            <button onClick={() => { setAdding(false); setErr('') }} className="text-xs font-bold px-3 py-2 rounded-xl" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
          </div>
        </Panel>
      )}

      {err && !adding && <p className="text-[11px] mb-2" style={{ color: 'var(--text-muted)' }}>{err}</p>}

      <Table
        head={['Serial', 'Item', 'Warehouse', 'Warranty', 'Status', '']}
        loading={isLoading}
        empty="No serial numbers tracked yet."
        rows={rows.map(s => [
          <span key="s" className="font-mono font-bold text-[11px]" style={{ color: 'var(--text-h)' }}>{s.serial_no}</span>,
          <span key="i"><span className="block font-semibold" style={{ color: 'var(--text-h)' }}>{s.product?.name}</span>
            <span className="block text-[9px]" style={{ color: 'var(--text-muted)' }}>{s.product?.sku}</span></span>,
          s.warehouse?.name || '—',
          s.warranty_until
            ? <span key="w" style={{ color: s.under_warranty ? '#10B981' : 'var(--text-muted)' }}>
                {String(s.warranty_until).split('T')[0]}<span className="block text-[9px]">{s.under_warranty ? 'in warranty' : 'expired'}</span>
              </span>
            : '—',
          <Select key="st" size="sm" value={s.status} onChange={v => setStatus.mutate({ id: s.id, status: v })} options={SERIAL_STATUS}
            buttonStyle={{ color: SERIAL_COLOR[s.status], background: `color-mix(in srgb, ${SERIAL_COLOR[s.status]} 15%, transparent)`, border: 'none', borderRadius: 999, padding: '3px 9px', fontSize: 10.5, fontWeight: 700 }} />,
          isAdmin ? <button key="d" onClick={() => del.mutate(s.id)} aria-label="Delete serial" className="hover:opacity-60"><Trash2 size={13} style={{ color: 'var(--color-danger-500)' }} /></button> : null,
        ])}
      />
    </>
  )
}

/* ══ Reservations ═════════════════════════════════════════════════ */

const RESERVE_FOR = [
  { value: 'customer', label: 'Customer' }, { value: 'project', label: 'Project' },
  { value: 'sales_order', label: 'Sales order' }, { value: 'production', label: 'Production' },
]

function ReservationsTab({ isAdmin }) {
  const qc = useQueryClient()
  const [adding, setAdding] = useState(false)
  const [err, setErr] = useState('')
  const [draft, setDraft] = useState({ product_id: '', warehouse_id: '', quantity: '', reserved_for: 'customer', reference_label: '', priority: 5, expires_at: '' })

  const { data: rows = [], isLoading } = useQuery({ queryKey: ['inv-reservations'], queryFn: () => inventoryApi.reservations.list({ status: 'active' }) })
  const { data: products = [] } = useQuery({ queryKey: ['inv-products', {}], queryFn: () => inventoryApi.products.list() })
  const { data: warehouses = [] } = useQuery({ queryKey: ['inv-warehouses'], queryFn: inventoryApi.warehouses.list })

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['inv-reservations'] })
    qc.invalidateQueries({ queryKey: ['inv-products'] })   // reserved/available changed
    qc.invalidateQueries({ queryKey: ['inv-summary'] })
  }

  const add = useMutation({
    mutationFn: () => inventoryApi.reservations.reserve({
      product_id: Number(draft.product_id), warehouse_id: Number(draft.warehouse_id),
      quantity: Number(draft.quantity), reserved_for: draft.reserved_for,
      reference_label: draft.reference_label || null, priority: Number(draft.priority) || 5,
      expires_at: draft.expires_at || null,
    }),
    onSuccess: () => { setAdding(false); setErr(''); setDraft({ product_id: '', warehouse_id: '', quantity: '', reserved_for: 'customer', reference_label: '', priority: 5, expires_at: '' }); refresh() },
    onError: (e) => setErr(e?.message || 'Could not reserve that stock.'),
  })
  const close = useMutation({
    mutationFn: ({ id, as }) => inventoryApi.reservations.close(id, as),
    onSuccess: refresh,
    onError: (e) => setErr(e?.message || 'Could not release that reservation.'),
  })

  return (
    <>
      <div className="flex items-center justify-between gap-2 mb-3">
        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
          Active reservations are what make <strong>available</strong> smaller than <strong>on hand</strong>.
        </p>
        <button onClick={() => setAdding(a => !a)} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl" style={{ background: INV_ACCENT, color: '#fff' }}>
          <Plus size={13} /> Reserve stock
        </button>
      </div>

      {adding && (
        <Panel>
          <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))' }}>
            <Fld label="Item *"><Select size="sm" value={draft.product_id} onChange={v => setDraft(d => ({ ...d, product_id: v }))} placeholder="Choose item"
              options={products.map(p => ({ value: String(p.id), label: `${p.sku} · ${p.name}` }))} /></Fld>
            <Fld label="Warehouse *"><Select size="sm" value={draft.warehouse_id} onChange={v => setDraft(d => ({ ...d, warehouse_id: v }))} placeholder="Choose"
              options={warehouses.map(w => ({ value: String(w.id), label: w.name }))} /></Fld>
            <Fld label="Quantity *"><input type="number" value={draft.quantity} onChange={e => setDraft(d => ({ ...d, quantity: e.target.value }))} style={INP} /></Fld>
            <Fld label="Reserved for"><Select size="sm" value={draft.reserved_for} onChange={v => setDraft(d => ({ ...d, reserved_for: v }))} options={RESERVE_FOR} /></Fld>
            <Fld label="Reference"><input value={draft.reference_label} onChange={e => setDraft(d => ({ ...d, reference_label: e.target.value }))} placeholder="e.g. Acme Ltd — PO 4417" style={INP} /></Fld>
            <Fld label="Priority (1 = highest)"><input type="number" min={1} max={9} value={draft.priority} onChange={e => setDraft(d => ({ ...d, priority: e.target.value }))} style={INP} /></Fld>
            <Fld label="Hold until"><input type="date" value={draft.expires_at} onChange={e => setDraft(d => ({ ...d, expires_at: e.target.value }))} style={INP} /></Fld>
          </div>
          {err && <p className="text-[11px] mt-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
          <div className="flex gap-2 mt-3">
            <button disabled={!draft.product_id || !draft.warehouse_id || !draft.quantity || add.isPending} onClick={() => add.mutate()}
              className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40" style={{ background: INV_ACCENT, color: '#fff' }}>
              <Check size={13} /> {add.isPending ? 'Reserving…' : 'Reserve'}
            </button>
            <button onClick={() => { setAdding(false); setErr('') }} className="text-xs font-bold px-3 py-2 rounded-xl" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
          </div>
        </Panel>
      )}

      {err && !adding && <p className="text-[11px] mb-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      <Table
        head={['Item', 'Warehouse', 'Qty', 'For', 'Priority', 'By', '']}
        loading={isLoading}
        empty="Nothing is reserved — all stock is free to sell."
        rows={rows.map(r => [
          <span key="i"><span className="block font-semibold" style={{ color: 'var(--text-h)' }}>{r.product?.name}</span>
            <span className="block text-[9px]" style={{ color: 'var(--text-muted)' }}>{r.product?.sku}</span></span>,
          r.warehouse?.name || '—',
          <span key="q" className="tabular-nums font-bold" style={{ color: 'var(--text-h)' }}>{fmtQty(r.quantity)}</span>,
          <span key="f"><span className="block capitalize">{String(r.reserved_for).replace('_', ' ')}</span>
            {r.reference_label && <span className="block text-[9px]" style={{ color: 'var(--text-muted)' }}>{r.reference_label}</span>}</span>,
          r.priority,
          r.creator?.name || '—',
          r.can_release ? (
            <span key="a" className="flex gap-1.5 justify-end">
              <button onClick={() => close.mutate({ id: r.id, as: 'fulfilled' })} className="text-[10px] font-bold px-2 py-1 rounded-lg"
                style={{ border: `1px solid ${INV_ACCENT}`, color: INV_ACCENT }}>Fulfil</button>
              <button onClick={() => close.mutate({ id: r.id, as: 'released' })} className="text-[10px] font-bold px-2 py-1 rounded-lg"
                style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Release</button>
            </span>
          ) : <span key="a" className="text-[10px]" style={{ color: 'var(--text-muted)' }}>—</span>,
        ])}
      />
    </>
  )
}

/* ── Shared bits ────────────────────────────────────────────────── */

const INP = {
  width: '100%', padding: '7px 10px', borderRadius: 10, fontSize: 12.5,
  background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', outline: 'none',
}

const Fld = ({ label, children }) => (
  <label className="block">
    <span className="block text-[10px] font-bold uppercase tracking-wide mb-1" style={{ color: 'var(--text-muted)' }}>{label}</span>
    {children}
  </label>
)

const Panel = ({ children }) => (
  <div className="rounded-2xl p-3 mb-3" style={{ background: 'var(--bg-card)', border: '1px dashed var(--border)' }}>{children}</div>
)

function Toolbar({ search, setSearch, placeholder, action }) {
  return (
    <div className="flex flex-wrap items-center gap-2 mb-3">
      <div className="relative flex-1" style={{ minWidth: 200 }}>
        <Search size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
        <input value={search} onChange={e => setSearch(e.target.value)} placeholder={placeholder}
          className="w-full rounded-xl outline-none"
          style={{ padding: '8px 12px 8px 33px', fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
      </div>
      {action}
    </div>
  )
}

function Table({ head, rows, loading, empty }) {
  return (
    <div className="overflow-x-auto rounded-2xl" style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
      <table className="w-full text-xs" style={{ minWidth: 720 }}>
        <thead>
          <tr style={{ borderBottom: '1px solid var(--border)' }}>
            {head.map((h, i) => (
              <th key={i} className={`px-3 py-2.5 font-bold text-[10px] uppercase tracking-wide ${i === head.length - 1 ? 'text-right' : 'text-left'}`}
                style={{ color: 'var(--text-muted)' }}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {loading && [1, 2, 3].map(i => (
            <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
              {head.map((_, j) => <td key={j} className="px-3 py-2.5"><div className="h-3.5 rounded animate-pulse" style={{ background: 'var(--bg-input)' }} /></td>)}
            </tr>
          ))}
          {!loading && rows.length === 0 && (
            <tr><td colSpan={head.length} className="px-3 py-10 text-center" style={{ color: 'var(--text-muted)' }}>{empty}</td></tr>
          )}
          {!loading && rows.map((r, i) => (
            <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
              {r.map((c, j) => (
                <td key={j} className={`px-3 py-2.5 ${j === r.length - 1 ? 'text-right' : ''}`} style={{ color: 'var(--text-body)' }}>{c}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
