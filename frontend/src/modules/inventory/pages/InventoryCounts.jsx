import { useState, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  ClipboardCheck, Search, User, Plus, X, MapPin, Camera, Crosshair,
  AlertTriangle, CheckCircle2, Ban, ArrowRight, RotateCcw, EyeOff, ImageIcon,
} from 'lucide-react'
import {
  inventoryApi, INV_ACCENT, COUNT_STATUS, COUNT_SCOPES, fmtQty, money,
} from '@/services/inventoryApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'
import ScanInput from '../components/ScanInput'
import { useDiscardGuard } from '@/lib/confirmClose'

/**
 * Cycle counting and physical verification.
 *
 * This is the only screen in the module where the app is corrected BY the
 * building. Nothing here moves stock: counting writes to the count sheet, and
 * APPROVAL is what posts the corrections through the ordinary ledger.
 */
export default function InventoryCounts() {
  const qc = useQueryClient()
  const { user } = useAuth()
  const [status, setStatus] = useState('')
  const [mine, setMine] = useState(false)
  const [search, setSearch] = useState('')
  const [openId, setOpenId] = useState(null)
  const [creating, setCreating] = useState(false)

  const params = {}
  if (status) params.status = status
  if (mine) params.mine = 1
  if (search.trim()) params.search = search.trim()

  const { data: rows = [], isLoading } = useQuery({
    queryKey: ['inv-counts', params],
    queryFn: () => inventoryApi.counts.list(params),
  })

  const bust = () => {
    qc.invalidateQueries({ queryKey: ['inv-counts'] })
    qc.invalidateQueries({ queryKey: ['inv-summary'] })
    qc.invalidateQueries({ queryKey: ['inv-products'] })
  }

  return (
    <div>
      <header className="flex flex-wrap items-center gap-2 mb-1">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)` }}>
          <ClipboardCheck size={17} style={{ color: INV_ACCENT }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Physical counts</h1>
        <span className="text-xs px-2 py-0.5 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>{rows.length}</span>
        <button onClick={() => setCreating(true)}
          className="ml-auto flex items-center gap-1.5 text-xs font-bold px-3.5 py-2 rounded-xl"
          style={{ background: INV_ACCENT, color: '#fff' }}>
          <Plus size={14} /> New count
        </button>
      </header>
      <p className="text-xs mb-4 ml-10" style={{ color: 'var(--text-muted)' }}>
        Counting never changes stock. The corrections reach the ledger only when somebody else signs the sheet off.
      </p>

      <div className="flex flex-wrap items-center gap-2 mb-4 p-2.5 rounded-2xl"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <div className="relative flex-1" style={{ minWidth: 180 }}>
          <Search size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search code or name…"
            className="w-full rounded-xl outline-none"
            style={{ padding: '8px 12px 8px 33px', fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
        </div>
        <div style={{ minWidth: 160 }}>
          <Select size="sm" value={status} onChange={setStatus} placeholder="Any stage"
            options={[{ value: '', label: 'Any stage' },
              ...Object.entries(COUNT_STATUS).map(([v, m]) => ({ value: v, label: m.label, dot: m.color }))]} />
        </div>
        {/* A counter works from their own queue, not the whole warehouse's. */}
        <button onClick={() => setMine(v => !v)}
          className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
          style={{
            background: mine ? `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)` : 'var(--bg-input)',
            border: `1px solid ${mine ? INV_ACCENT : 'var(--border)'}`,
            color: mine ? INV_ACCENT : 'var(--text-muted)',
          }}>
          <User size={13} /> Mine
        </button>
      </div>

      {isLoading && <div className="h-40 rounded-2xl animate-pulse" style={{ background: 'var(--bg-card)' }} />}

      {!isLoading && rows.length === 0 && (
        <div className="rounded-2xl p-10 text-center" style={{ background: 'var(--bg-card)', border: '1px dashed var(--border)' }}>
          <ClipboardCheck size={26} style={{ color: 'var(--text-muted)', margin: '0 auto 10px' }} />
          <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>No counts yet.</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            A small random sample every week finds more than a yearly stocktake nobody finishes.
          </p>
        </div>
      )}

      <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fill,minmax(300px,1fr))' }}>
        {rows.map(r => {
          const st = COUNT_STATUS[r.status] || { label: r.status, color: 'var(--text-muted)' }
          const scope = COUNT_SCOPES.find(s => s.value === r.scope)
          const done = r.counted_lines_count ?? 0
          const total = r.lines_count ?? 0
          return (
            <button key={r.id} onClick={() => setOpenId(r.id)}
              className="rounded-2xl p-3.5 text-left transition-all"
              style={{ background: 'var(--bg-card)', border: `1px solid ${st.color}` }}>
              <div className="flex items-center gap-2 mb-1.5">
                <span className="font-mono text-xs font-bold" style={{ color: INV_ACCENT }}>{r.code}</span>
                <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg"
                  style={{ background: `color-mix(in srgb, ${st.color} 15%, transparent)`, color: st.color }}>{st.label}</span>
                {r.blind && r.status !== 'approved' && (
                  <span title="Blind count — the counter is not shown the expected figure">
                    <EyeOff size={12} style={{ color: 'var(--text-muted)' }} />
                  </span>
                )}
              </div>
              <p className="text-xs font-semibold truncate" style={{ color: 'var(--text-h)' }}>
                {r.name || scope?.label || 'Count'}
              </p>
              <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
                {done} of {total} counted
                {r.warehouse ? ` · ${r.warehouse.name}` : ''}
                {r.counter ? ` · ${r.counter.name}` : ' · unassigned'}
              </p>
              {/* How much of the sheet has actually been walked. */}
              <div className="h-1 rounded-full mt-2 overflow-hidden" style={{ background: 'var(--bg-input)' }}>
                <div className="h-full rounded-full" style={{ width: `${total ? (done / total) * 100 : 0}%`, background: st.color }} />
              </div>
              {r.variance_lines_count > 0 && (
                <p className="flex items-center gap-1 text-[10px] mt-1.5 font-bold" style={{ color: '#f59e0b' }}>
                  <AlertTriangle size={10} /> {r.variance_lines_count} line{r.variance_lines_count === 1 ? '' : 's'} disagree
                </p>
              )}
              {r.status === 'approved' && r.variance_value != null && (
                <p className="text-[10px] mt-1.5" style={{ color: Number(r.variance_value) < 0 ? 'var(--color-danger-500)' : '#10B981' }}>
                  {Number(r.variance_value) < 0 ? 'Written off' : 'Found'} {money(Math.abs(Number(r.variance_value)))}
                </p>
              )}
            </button>
          )
        })}
      </div>

      {creating && <NewCountModal onClose={() => setCreating(false)} onDone={(id) => { bust(); setCreating(false); setOpenId(id) }} />}
      {openId && <CountWorkbench id={openId} onClose={() => setOpenId(null)} onChanged={bust} currentUser={user} />}
    </div>
  )
}

/* ── Raising a sheet ────────────────────────────────────────────── */

function NewCountModal({ onClose, onDone }) {
  const { guard, dialog } = useDiscardGuard()
  const [form, setForm] = useState({
    warehouse_id: '', name: '', scope: 'random', sample_size: 20,
    abc_class: 'A', blind: true, assigned_to: '',
  })
  const [picked, setPicked] = useState([])       // ids for the "chosen X" scopes
  const [err, setErr] = useState('')

  const isDirty = () => Boolean(form.warehouse_id || form.name.trim() || picked.length > 0)
  const handleClose = () => guard(onClose, isDirty())

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  const scopeMeta = COUNT_SCOPES.find(s => s.value === form.scope)
  const needsPick = ['location', 'category', 'product'].includes(form.scope)

  const { data: warehouses = [] } = useQuery({ queryKey: ['inv-warehouses'], queryFn: inventoryApi.warehouses.list })
  const { data: staff = [] } = useQuery({ queryKey: ['inv-staff'], queryFn: inventoryApi.staff })

  const create = useMutation({
    mutationFn: () => {
      const body = {
        warehouse_id: Number(form.warehouse_id),
        name: form.name || null,
        scope: form.scope,
        blind: form.blind,
        assigned_to: form.assigned_to ? Number(form.assigned_to) : null,
      }
      if (form.scope === 'random') body.sample_size = Number(form.sample_size)
      if (form.scope === 'abc') body.abc_class = form.abc_class
      if (form.scope === 'location') body.location_ids = picked
      if (form.scope === 'category') body.category_ids = picked
      if (form.scope === 'product') body.product_ids = picked
      return inventoryApi.counts.create(body)
    },
    onSuccess: (s) => onDone(s.id),
    onError: (e) => setErr(e?.message || 'That count could not be raised.'),
  })

  return (
    <>
      <div className="fixed inset-0 z-[70] flex items-start justify-center p-4 overflow-y-auto bg-black/50" onClick={handleClose}>
        <div className="w-full max-w-lg rounded-2xl mt-[6vh] mb-8" onClick={e => e.stopPropagation()}
          style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <div className="flex items-center gap-2 px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
            <ClipboardCheck size={16} style={{ color: INV_ACCENT }} />
            <h2 className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>New count</h2>
            <button onClick={handleClose} className="ml-auto hover:opacity-70" aria-label="Close">
              <X size={18} style={{ color: 'var(--text-muted)' }} />
            </button>
          </div>

        <div className="p-5 space-y-3">
          <Field label="Warehouse">
            <Select size="sm" value={form.warehouse_id} onChange={v => { set('warehouse_id', v); setPicked([]) }}
              placeholder="Which site is being counted"
              options={warehouses.map(w => ({ value: String(w.id), label: w.name }))} />
          </Field>

          <Field label="Name (optional)">
            <input value={form.name} onChange={e => set('name', e.target.value)}
              placeholder="e.g. July spot check"
              className="w-full rounded-lg outline-none"
              style={{ padding: '7px 10px', fontSize: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
          </Field>

          <Field label="What to count">
            <Select size="sm" value={form.scope} onChange={v => { set('scope', v); setPicked([]) }}
              options={COUNT_SCOPES.map(s => ({ value: s.value, label: s.label }))} />
            {scopeMeta && <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>{scopeMeta.hint}</p>}
          </Field>

          {form.scope === 'random' && (
            <Field label="How many places to check">
              <input type="number" min={1} max={200} value={form.sample_size}
                onChange={e => set('sample_size', e.target.value)}
                className="rounded-lg outline-none text-right" style={{ width: 90, padding: '7px 10px', fontSize: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
            </Field>
          )}

          {form.scope === 'abc' && (
            <Field label="Class">
              <Select size="sm" value={form.abc_class} onChange={v => set('abc_class', v)}
                options={[
                  { value: 'A', label: 'A — the first 80% of consumption value' },
                  { value: 'B', label: 'B — the next 15%' },
                  { value: 'C', label: 'C — the long tail' },
                ]} />
            </Field>
          )}

          {needsPick && <ScopePicker scope={form.scope} warehouseId={form.warehouse_id} picked={picked} setPicked={setPicked} />}

          <Field label="Who is walking it">
            <Select size="sm" value={form.assigned_to} onChange={v => set('assigned_to', v)}
              placeholder="Assign later"
              options={[{ value: '', label: 'Assign later' }, ...staff.map(s => ({ value: String(s.id), label: s.name }))]} />
          </Field>

          {/* The one control on this form worth explaining, because turning it
              off quietly destroys the point of counting at all. */}
          <label className="flex items-start gap-2 cursor-pointer p-2.5 rounded-xl" style={{ background: 'var(--bg-input)' }}>
            <input type="checkbox" checked={form.blind} onChange={e => set('blind', e.target.checked)} className="mt-0.5" />
            <span>
              <span className="block text-xs font-bold" style={{ color: 'var(--text-h)' }}>Blind count</span>
              <span className="block text-[11px]" style={{ color: 'var(--text-muted)' }}>
                The counter is not shown what the app expects. Shown it, a tired person confirms it — and a count
                that only ever agrees with the ledger has verified nothing.
              </span>
            </span>
          </label>

          {err && (
            <p className="text-xs px-3 py-2 rounded-lg"
              style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>
          )}

          <div className="flex items-center gap-2 pt-1">
            <button onClick={() => create.mutate()} disabled={!form.warehouse_id || create.isPending || (needsPick && !picked.length)}
              className="flex items-center gap-1.5 text-xs font-bold px-3.5 py-2 rounded-xl disabled:opacity-40"
              style={{ background: INV_ACCENT, color: '#fff' }}>
              {create.isPending ? 'Raising…' : <>Raise the sheet <ArrowRight size={12} /></>}
            </button>
            <button onClick={handleClose} className="text-xs font-bold px-3 py-2 rounded-xl"
              style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Cancel</button>
          </div>
        </div>
      </div>
    </div>
    {dialog}
    </>
  )
}

/** A searchable tick-list for the three "chosen X" scopes. */
function ScopePicker({ scope, warehouseId, picked, setPicked }) {
  const [q, setQ] = useState('')

  const { data = [] } = useQuery({
    queryKey: ['inv-scope-options', scope, warehouseId],
    // Bins belong to a warehouse, so that list waits until one is chosen.
    enabled: scope !== 'location' || !!warehouseId,
    queryFn: () => scope === 'location' ? inventoryApi.warehouses.locations(warehouseId)
      : scope === 'category' ? inventoryApi.categories.list()
      : inventoryApi.products.list({ per_page: 200 }),
  })

  const rows = useMemo(() => {
    const list = Array.isArray(data) ? data : (data?.data || [])
    const term = q.trim().toLowerCase()
    return list
      .map(r => ({ id: r.id, label: r.name || r.code, sub: r.code && r.name ? r.code : (r.sku || '') }))
      .filter(r => !term || `${r.label} ${r.sub}`.toLowerCase().includes(term))
      .slice(0, 200)
  }, [data, q])

  const toggle = (id) => setPicked(p => p.includes(id) ? p.filter(x => x !== id) : [...p, id])

  return (
    <Field label={`Chosen — ${picked.length} selected`}>
      {scope === 'location' && !warehouseId
        ? <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Choose a warehouse first.</p>
        : (
          <>
            <input value={q} onChange={e => setQ(e.target.value)} placeholder="Filter…"
              className="w-full rounded-lg outline-none mb-1.5"
              style={{ padding: '6px 10px', fontSize: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
            <div className="rounded-xl overflow-y-auto" style={{ maxHeight: 170, border: '1px solid var(--border)' }}>
              {rows.length === 0 && <p className="text-[11px] p-3" style={{ color: 'var(--text-muted)' }}>Nothing to choose from.</p>}
              {rows.map(r => (
                <label key={r.id} className="flex items-center gap-2 px-2.5 py-1.5 cursor-pointer"
                  style={{ borderBottom: '1px solid var(--border)' }}>
                  <input type="checkbox" checked={picked.includes(r.id)} onChange={() => toggle(r.id)} />
                  <span className="text-xs" style={{ color: 'var(--text-h)' }}>{r.label}</span>
                  {r.sub && <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{r.sub}</span>}
                </label>
              ))}
            </div>
          </>
        )}
    </Field>
  )
}

/* ── The workbench ──────────────────────────────────────────────── */

/**
 * One sheet, walked. On a blind sheet the counter sees no expected figure and
 * no variance — the server strips both, so there is nothing to agree with.
 */
function CountWorkbench({ id, onClose, onChanged }) {
  const qc = useQueryClient()
  const [draft, setDraft] = useState({})       // lineId -> typed/scanned count
  const [gps, setGps] = useState(null)
  const [reason, setReason] = useState('')
  const [rejecting, setRejecting] = useState(false)
  const [err, setErr] = useState('')
  const [flash, setFlash] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['inv-counts', id], queryFn: () => inventoryApi.counts.get(id),
  })

  const s = data?.count
  const variance = data?.variance
  const lines = s?.lines || []
  const canWork = data?.can_work
  const canApprove = data?.can_approve
  const blind = s?.blind && ['draft', 'counting'].includes(s?.status) && lines.some(l => l.system_qty == null)
  const open = s && ['draft', 'counting'].includes(s.status)
  // Once a line disagrees, the useful next action is a second look at it.
  const recounting = open && lines.some(l => l.status === 'variance')

  const refresh = () => { qc.invalidateQueries({ queryKey: ['inv-counts'] }); onChanged?.() }
  const run = (fn, after) => ({
    mutationFn: fn,
    onSuccess: () => { setErr(''); setDraft({}); refresh(); after?.() },
    onError: (e) => setErr(e?.message || 'That action failed.'),
  })

  const rowsFor = (key) => Object.entries(draft).map(([lineId, qty]) => ({
    id: Number(lineId), [key]: Number(qty),
    ...(gps ? { gps_lat: gps.lat, gps_lng: gps.lng, gps_accuracy: gps.acc, device: navigator.userAgent.slice(0, 110) } : {}),
  }))

  const saveCount = useMutation(run(() => inventoryApi.counts.count(id, rowsFor('counted_qty'))))
  const saveRecount = useMutation(run(() => inventoryApi.counts.recount(id, rowsFor('recount_qty'))))
  const submit = useMutation(run(() => inventoryApi.counts.submit(id)))
  const approve = useMutation(run(() => inventoryApi.counts.approve(id)))
  const reject = useMutation(run(() => inventoryApi.counts.reject(id, reason), () => { setRejecting(false); setReason('') }))
  const cancel = useMutation(run(() => inventoryApi.counts.cancel(id), onClose))
  const photo = useMutation({
    mutationFn: ({ lineId, file }) => inventoryApi.counts.photo(id, lineId, file),
    onSuccess: refresh,
    onError: (e) => setErr(e?.message || 'That photo could not be attached.'),
  })

  /**
   * GPS is asked for once, on a tap, not on page load — a permission prompt
   * nobody triggered gets denied, and then no count ever carries evidence.
   */
  const grabGps = () => {
    if (!navigator.geolocation) { setErr('This device cannot report its location.'); return }
    navigator.geolocation.getCurrentPosition(
      p => setGps({ lat: +p.coords.latitude.toFixed(7), lng: +p.coords.longitude.toFixed(7), acc: Math.round(p.coords.accuracy) }),
      () => setErr('Location was refused, so the count will be recorded without it.'),
      { enableHighAccuracy: true, timeout: 8000 },
    )
  }

  /** A scan means "I am standing at this one" — jump to it rather than count up. */
  const onScan = (code) => {
    const c = String(code).trim().toLowerCase()
    const line = lines.find(l =>
      String(l.product?.barcode || '').toLowerCase() === c ||
      String(l.product?.sku || '').toLowerCase() === c)

    if (!line) { setFlash(`Nothing on this sheet matches ${code}`); setTimeout(() => setFlash(''), 2500); return }

    setFlash(`${line.product.name} — type what is on the shelf`)
    setTimeout(() => setFlash(''), 2500)
    document.getElementById(`count-line-${line.id}`)?.focus()
  }

  if (isLoading || !s) {
    return (
      <div className="fixed inset-0 z-[70] flex items-center justify-center bg-black/50" onClick={onClose}>
        <div className="w-full max-w-3xl h-64 rounded-2xl animate-pulse" style={{ background: 'var(--bg-card)' }} />
      </div>
    )
  }

  const st = COUNT_STATUS[s.status] || { label: s.status, color: 'var(--text-muted)' }

  return (
    <div className="fixed inset-0 z-[70] flex items-start justify-center p-4 overflow-y-auto bg-black/50" onClick={onClose}>
      <div className="w-full max-w-3xl rounded-2xl mt-[4vh] mb-8" onClick={e => e.stopPropagation()}
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>

        <div className="flex items-center gap-2 px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <ClipboardCheck size={16} style={{ color: st.color }} />
          <div className="min-w-0">
            <h2 className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>
              {s.code}
              <span className="ml-2 text-[10px] font-bold px-2 py-0.5 rounded-lg"
                style={{ background: `color-mix(in srgb, ${st.color} 15%, transparent)`, color: st.color }}>{st.label}</span>
            </h2>
            <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
              {s.name || COUNT_SCOPES.find(x => x.value === s.scope)?.label} · {s.warehouse?.name}
              {s.counter ? ` · counted by ${s.counter.name}` : ''}
            </p>
          </div>
          <button onClick={onClose} className="ml-auto hover:opacity-70" aria-label="Close">
            <X size={18} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        <div className="p-5">
          {s.rejection_reason && s.status === 'counting' && (
            <div className="rounded-xl px-3 py-2.5 mb-4"
              style={{ background: 'color-mix(in srgb, var(--color-danger-500) 10%, transparent)', border: '1px solid var(--color-danger-500)' }}>
              <p className="text-[11px] font-bold" style={{ color: 'var(--color-danger-500)' }}>Sent back</p>
              <p className="text-[11px]" style={{ color: 'var(--text-body)' }}>{s.rejection_reason}</p>
            </div>
          )}

          {blind && (
            <p className="flex items-center gap-1.5 text-[11px] mb-3 px-3 py-2 rounded-xl"
              style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
              <EyeOff size={12} /> Blind count — you are not shown what the app expects. Write down what is actually there.
            </p>
          )}

          {open && canWork && (
            <div className="mb-4">
              <ScanInput onScan={onScan} placeholder="Scan an item to jump to its line…"
                hint="Scanning finds the line; the number you type is what is physically on the shelf." />
              {flash && (
                <p className="text-[11px] mt-1.5 font-semibold"
                  style={{ color: flash.startsWith('Nothing') ? 'var(--color-danger-500)' : INV_ACCENT }}>{flash}</p>
              )}
              <button onClick={grabGps}
                className="flex items-center gap-1.5 text-[11px] font-bold mt-2 px-2.5 py-1.5 rounded-lg"
                style={{ border: `1px solid ${gps ? INV_ACCENT : 'var(--border)'}`, color: gps ? INV_ACCENT : 'var(--text-muted)' }}>
                <Crosshair size={12} />
                {gps ? `Location captured (±${gps.acc}m)` : 'Stamp my location on this count'}
              </button>
            </div>
          )}

          {variance && (
            <div className="grid gap-2 mb-4" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(110px,1fr))' }}>
              <Stat label="Counted" value={`${variance.counted} / ${variance.lines}`} />
              <Stat label="Matched" value={variance.matched} color="#10B981" />
              <Stat label="Disagreed" value={variance.variances} color={variance.variances ? '#f59e0b' : 'var(--text-h)'} />
              <Stat label="Accuracy" value={variance.accuracy_pct == null ? '—' : `${variance.accuracy_pct}%`} />
              <Stat label="At cost" value={money(variance.variance_value)}
                color={Number(variance.variance_value) < 0 ? 'var(--color-danger-500)' : '#10B981'} />
            </div>
          )}

          <div className="overflow-x-auto rounded-xl mb-4" style={{ border: '1px solid var(--border)' }}>
            <table className="w-full text-xs" style={{ minWidth: 620 }}>
              <thead>
                <tr className="text-left text-[10px] uppercase tracking-wide"
                  style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                  <th className="px-3 py-2 font-bold">Item</th>
                  <th className="px-3 py-2 font-bold">Where</th>
                  {!blind && <th className="px-3 py-2 font-bold text-right">App says</th>}
                  <th className="px-3 py-2 font-bold text-right">Counted</th>
                  {recounting && <th className="px-3 py-2 font-bold text-right">Recount</th>}
                  {!blind && <th className="px-3 py-2 font-bold text-right">Diff</th>}
                  <th className="px-3 py-2 font-bold">Proof</th>
                </tr>
              </thead>
              <tbody>
                {lines.map(l => {
                  const off = !blind && l.variance != null && Math.abs(Number(l.variance)) > 0.0005
                  const editable = open && canWork
                  const key = recounting && l.status === 'variance' ? 'recount' : 'count'
                  const shown = draft[l.id] != null ? draft[l.id]
                    : (key === 'recount' ? (l.recount_qty ?? '') : (l.counted_qty ?? ''))
                  return (
                    <tr key={l.id} style={{ borderBottom: '1px solid var(--border)' }}>
                      <td className="px-3 py-2">
                        <span className="block font-semibold" style={{ color: 'var(--text-h)' }}>{l.product?.name}</span>
                        <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{l.product?.sku}</span>
                      </td>
                      <td className="px-3 py-2">
                        <span className="flex items-center gap-1 text-[11px]" style={{ color: 'var(--text-h)' }}>
                          <MapPin size={10} style={{ color: '#0ea5e9' }} />
                          {l.location?.code || l.location?.name || 'anywhere in the warehouse'}
                        </span>
                      </td>
                      {!blind && (
                        <td className="px-3 py-2 text-right tabular-nums" style={{ color: 'var(--text-muted)' }}>
                          {fmtQty(l.system_at_count ?? l.system_qty)}
                        </td>
                      )}
                      <td className="px-3 py-2 text-right">
                        {editable && key === 'count'
                          ? <CountBox id={`count-line-${l.id}`} value={shown} onChange={v => setDraft(d => ({ ...d, [l.id]: v }))} />
                          : <span className="tabular-nums font-semibold" style={{ color: 'var(--text-h)' }}>
                              {l.counted_qty == null ? '—' : fmtQty(l.counted_qty)}
                            </span>}
                      </td>
                      {recounting && (
                        <td className="px-3 py-2 text-right">
                          {editable && l.status === 'variance'
                            ? <CountBox id={`count-line-${l.id}`} value={shown} onChange={v => setDraft(d => ({ ...d, [l.id]: v }))} />
                            : <span className="tabular-nums" style={{ color: 'var(--text-muted)' }}>
                                {l.recount_qty == null ? '—' : fmtQty(l.recount_qty)}
                              </span>}
                        </td>
                      )}
                      {!blind && (
                        <td className="px-3 py-2 text-right tabular-nums font-bold"
                          style={{ color: !off ? 'var(--text-muted)' : (Number(l.variance) < 0 ? 'var(--color-danger-500)' : '#10B981') }}>
                          {l.counted_qty == null ? '—' : `${Number(l.variance) > 0 ? '+' : ''}${fmtQty(l.variance)}`}
                        </td>
                      )}
                      <td className="px-3 py-2">
                        <div className="flex items-center gap-1.5">
                          {l.gps_lat && <span title={`Counted at ${l.gps_lat}, ${l.gps_lng} (±${l.gps_accuracy}m)`}>
                            <Crosshair size={12} style={{ color: INV_ACCENT }} />
                          </span>}
                          {l.photo_path
                            ? <a href={inventoryApi.counts.photoUrl(id, l.id)} target="_blank" rel="noreferrer" title="Open the photo">
                                <ImageIcon size={12} style={{ color: INV_ACCENT }} />
                              </a>
                            : editable && (
                              <label className="cursor-pointer" title="Attach a photo of the shelf">
                                <Camera size={12} style={{ color: 'var(--text-muted)' }} />
                                <input type="file" accept="image/*" capture="environment" className="hidden"
                                  onChange={e => e.target.files?.[0] && photo.mutate({ lineId: l.id, file: e.target.files[0] })} />
                              </label>
                            )}
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          {/* What disagreed, biggest money first — the list a supervisor decides on. */}
          {variance?.rows?.length > 0 && s.status !== 'draft' && (
            <div className="rounded-xl px-3 py-2.5 mb-4"
              style={{ background: 'color-mix(in srgb, #f59e0b 10%, transparent)', border: '1px solid #f59e0b' }}>
              <p className="flex items-center gap-1.5 text-[11px] font-bold mb-1" style={{ color: '#b45309' }}>
                <AlertTriangle size={12} /> The shelf and the app disagree
              </p>
              {variance.rows.slice(0, 6).map(r => (
                <p key={r.line_id} className="text-[11px]" style={{ color: 'var(--text-body)' }}>
                  · {r.product} — app said {fmtQty(r.system)}, counted {fmtQty(r.counted)}
                  {' '}({r.variance > 0 ? '+' : ''}{fmtQty(r.variance)}, {money(r.value)})
                  {r.recounted ? ' · recounted' : ''}{r.evidence ? ' · evidence attached' : ''}
                </p>
              ))}
            </div>
          )}

          {err && (
            <p className="text-xs px-3 py-2 rounded-lg mb-3"
              style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>
          )}

          {rejecting && (
            <div className="rounded-xl p-3 mb-3" style={{ background: 'var(--bg-input)' }}>
              <input value={reason} onChange={e => setReason(e.target.value)} autoFocus
                placeholder="Why is it going back? e.g. zero on a fast mover — walk it again"
                className="w-full rounded-lg outline-none"
                style={{ padding: '7px 10px', fontSize: 12, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
              <p className="text-[10px] mt-1.5" style={{ color: 'var(--text-muted)' }}>
                "Recount it" on its own tells the counter nothing.
              </p>
            </div>
          )}

          {/* Only the next action. */}
          <div className="flex flex-wrap items-center gap-2">
            {open && canWork && (
              <>
                <Action onClick={() => (recounting ? saveRecount : saveCount).mutate()}
                  busy={saveCount.isPending || saveRecount.isPending} color={INV_ACCENT}
                  disabled={!Object.keys(draft).length}>
                  {recounting ? <>Save the recount <RotateCcw size={12} /></> : 'Save what I counted'}
                </Action>
                <Action onClick={() => submit.mutate()} busy={submit.isPending} color="#f59e0b">
                  Send for approval <ArrowRight size={12} />
                </Action>
              </>
            )}

            {s.status === 'pending_approval' && canApprove && (
              <>
                <Action onClick={() => approve.mutate()} busy={approve.isPending} color="#10B981">
                  <CheckCircle2 size={12} /> Approve — corrections go to the ledger
                </Action>
                <Action onClick={() => rejecting ? reject.mutate() : setRejecting(true)}
                  busy={reject.isPending} color="var(--color-danger-500)" ghost
                  disabled={rejecting && !reason.trim()}>
                  {rejecting ? 'Send it back' : 'Send back to be recounted'}
                </Action>
              </>
            )}

            {s.status === 'pending_approval' && !canApprove && (
              <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                Waiting on somebody else. You did the counting, so you cannot sign this off.
              </p>
            )}

            {s.status !== 'approved' && s.status !== 'cancelled' && (
              <button onClick={() => cancel.mutate()} disabled={cancel.isPending}
                className="ml-auto flex items-center gap-1 text-[11px] font-bold"
                style={{ color: 'var(--color-danger-500)' }}>
                <Ban size={12} /> Abandon this count
              </button>
            )}
          </div>

          {s.status === 'approved' && (
            <p className="text-[11px] mt-3" style={{ color: 'var(--text-muted)' }}>
              Approved by {s.approver?.name || 'a supervisor'}. The corrections are in the ledger as ordinary
              adjustments naming {s.code}, so any one of them can be traced back to this sheet.
            </p>
          )}
        </div>
      </div>
    </div>
  )
}

/* ── Small pieces ───────────────────────────────────────────────── */

function Field({ label, children }) {
  return (
    <div>
      <label className="block text-[11px] font-bold mb-1" style={{ color: 'var(--text-muted)' }}>{label}</label>
      {children}
    </div>
  )
}

function Stat({ label, value, color }) {
  return (
    <div className="rounded-xl px-3 py-2" style={{ background: 'var(--bg-input)' }}>
      <p className="text-[10px] uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-sm font-bold tabular-nums" style={{ color: color || 'var(--text-h)' }}>{value}</p>
    </div>
  )
}

function CountBox({ id, value, onChange }) {
  return (
    <input id={id} type="number" min={0} step="0.001" value={value}
      onChange={e => onChange(e.target.value)} placeholder="—"
      className="rounded-lg outline-none text-right tabular-nums"
      style={{
        width: 82, padding: '5px 8px', fontSize: 12,
        background: 'var(--bg-input)', color: 'var(--text-h)',
        border: `1px solid ${value === '' || value == null ? 'var(--border)' : INV_ACCENT}`,
      }} />
  )
}

function Action({ onClick, busy, color, ghost, disabled, children }) {
  return (
    <button onClick={onClick} disabled={busy || disabled}
      className="flex items-center gap-1.5 text-xs font-bold px-3.5 py-2 rounded-xl disabled:opacity-40"
      style={ghost ? { border: '1px solid var(--border)', color } : { background: color, color: '#fff' }}>
      {busy ? 'Working…' : children}
    </button>
  )
}
