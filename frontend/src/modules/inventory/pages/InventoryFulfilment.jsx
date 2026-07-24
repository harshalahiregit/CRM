import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Truck, PackageCheck, Search, User, MapPin, Layers3, AlertTriangle,
  ArrowRight, X, Ban, ExternalLink, ClipboardList,
} from 'lucide-react'
import { inventoryApi, INV_ACCENT, PICK_STATUS, fmtQty } from '@/services/inventoryApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'
import ScanInput from '../components/ScanInput'

/**
 * Pick, pack, ship.
 *
 * The board is a queue, not a report: one row per parcel, ordered by what needs
 * doing next. Everything on this page is warehouse work — nothing here moves
 * stock. Shipping posts the delivery voucher behind the parcel, and THAT is
 * what writes the movement.
 */
export default function InventoryFulfilment() {
  const qc = useQueryClient()
  const { user } = useAuth()
  const [status, setStatus] = useState('')
  const [mine, setMine] = useState(false)
  const [search, setSearch] = useState('')
  const [openId, setOpenId] = useState(null)

  const params = {}
  if (status) params.status = status
  if (mine) params.mine = 1
  if (search.trim()) params.search = search.trim()

  const { data: rows = [], isLoading } = useQuery({
    queryKey: ['inv-fulfilment', params],
    queryFn: () => inventoryApi.fulfilment.list(params),
  })

  const bust = () => {
    qc.invalidateQueries({ queryKey: ['inv-fulfilment'] })
    qc.invalidateQueries({ queryKey: ['inv-summary'] })
    qc.invalidateQueries({ queryKey: ['inv-vouchers'] })
  }

  return (
    <div>
      <header className="flex flex-wrap items-center gap-2 mb-1">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)` }}>
          <Truck size={17} style={{ color: INV_ACCENT }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Pick, pack &amp; ship</h1>
        <span className="text-xs px-2 py-0.5 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>{rows.length}</span>
      </header>
      <p className="text-xs mb-4 ml-10" style={{ color: 'var(--text-muted)' }}>
        Raise a pick list from a delivery voucher. Stock leaves only when the parcel ships.
      </p>

      <div className="flex flex-wrap items-center gap-2 mb-4 p-2.5 rounded-2xl"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <div className="relative flex-1" style={{ minWidth: 180 }}>
          <Search size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search code or tracking number…"
            className="w-full rounded-xl outline-none"
            style={{ padding: '8px 12px 8px 33px', fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
        </div>
        <div style={{ minWidth: 150 }}>
          <Select size="sm" value={status} onChange={setStatus} placeholder="Any stage"
            options={[{ value: '', label: 'Any stage' },
              ...Object.entries(PICK_STATUS).map(([v, m]) => ({ value: v, label: m.label, dot: m.color }))]} />
        </div>
        {/* A picker works from their own queue, not the whole warehouse's. */}
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
          <ClipboardList size={26} style={{ color: 'var(--text-muted)', margin: '0 auto 10px' }} />
          <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>Nothing to fulfil.</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            Open a delivery voucher and raise a pick list to start one.
          </p>
        </div>
      )}

      <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fill,minmax(300px,1fr))' }}>
        {rows.map(r => {
          const st = PICK_STATUS[r.status] || { label: r.status, color: 'var(--text-muted)' }
          return (
            <button key={r.id} onClick={() => setOpenId(r.id)}
              className="rounded-2xl p-3.5 text-left transition-all"
              style={{ background: 'var(--bg-card)', border: `1px solid ${st.color}` }}>
              <div className="flex items-center gap-2 mb-1.5">
                <span className="font-mono text-xs font-bold" style={{ color: INV_ACCENT }}>{r.code}</span>
                <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg"
                  style={{ background: `color-mix(in srgb, ${st.color} 15%, transparent)`, color: st.color }}>{st.label}</span>
              </div>
              <p className="text-xs font-semibold truncate" style={{ color: 'var(--text-h)' }}>
                {r.voucher?.customer_name || r.voucher?.code || 'No customer named'}
              </p>
              <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
                {r.lines_count} line{r.lines_count === 1 ? '' : 's'}
                {r.warehouse ? ` · ${r.warehouse.name}` : ''}
                {r.picker ? ` · ${r.picker.name}` : ' · unassigned'}
              </p>
              {r.tracking_number && (
                <p className="text-[10px] mt-1 font-mono" style={{ color: 'var(--text-muted)' }}>
                  {r.carrier} {r.tracking_number}
                </p>
              )}
            </button>
          )
        })}
      </div>

      {openId && <FulfilmentWorkbench id={openId} onClose={() => setOpenId(null)} onChanged={bust} currentUser={user} />}
    </div>
  )
}

/* ── The workbench ──────────────────────────────────────────────── */

/**
 * One parcel, from shelf to doorstep. The panel only ever offers the NEXT
 * action, because a picker holding a box should not have to work out which of
 * six buttons applies to them.
 */
function FulfilmentWorkbench({ id, onClose, onChanged, currentUser }) {
  const qc = useQueryClient()
  const [draft, setDraft] = useState({})     // lineId -> qty typed or scanned
  const [ship, setShip] = useState({ carrier: '', tracking_number: '', parcel_count: 1, parcel_weight: '' })
  const [received, setReceived] = useState('')
  const [err, setErr] = useState('')
  const [flash, setFlash] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['inv-fulfilment', id], queryFn: () => inventoryApi.fulfilment.get(id),
  })
  const { data: carriers = [] } = useQuery({
    queryKey: ['inv-carriers'], queryFn: inventoryApi.fulfilment.carriers,
  })

  const list = data?.list
  const check = data?.check
  const lines = list?.lines || []
  const packing = list?.status === 'picked' || list?.status === 'packed'

  const refresh = () => { qc.invalidateQueries({ queryKey: ['inv-fulfilment'] }); onChanged?.() }
  const run = (fn, after) => ({
    mutationFn: fn,
    onSuccess: () => { setErr(''); refresh(); after?.() },
    onError: (e) => setErr(e?.message || 'That action failed.'),
  })

  const savePick = useMutation(run(() => inventoryApi.fulfilment.pick(id, rowsFor('picked_qty'))))
  const finishPick = useMutation(run(() => inventoryApi.fulfilment.picked(id)))
  const savePack = useMutation(run(() => inventoryApi.fulfilment.pack(id, rowsFor('packed_qty'))))
  const doShip = useMutation(run(() => inventoryApi.fulfilment.ship(id, {
    ...ship, parcel_weight: ship.parcel_weight === '' ? null : Number(ship.parcel_weight),
  })))
  const doDeliver = useMutation(run(() => inventoryApi.fulfilment.deliver(id, { received_by: received || null })))
  const doCancel = useMutation(run(() => inventoryApi.fulfilment.cancel(id), onClose))

  function rowsFor(key) {
    return lines.map(l => ({
      id: l.id,
      [key]: draft[l.id] != null ? Number(draft[l.id]) : Number(packing ? l.packed_qty : l.picked_qty),
    }))
  }

  const qtyOf = (l) => draft[l.id] != null ? Number(draft[l.id])
    : Number(packing ? l.packed_qty : l.picked_qty)

  const setQty = (lineId, v) => setDraft(d => ({ ...d, [lineId]: v }))

  /**
   * A scan means "one more of this into my hand / into the carton". Counting up
   * per scan is the only way a picker can work one-handed; typing a total means
   * putting the box down.
   */
  const onScan = (code) => {
    const c = String(code).trim().toLowerCase()
    const line = lines.find(l =>
      String(l.product?.barcode || '').toLowerCase() === c ||
      String(l.product?.sku || '').toLowerCase() === c)

    if (!line) { setFlash(`Nothing on this list matches ${code}`); setTimeout(() => setFlash(''), 2500); return }

    const ceiling = packing ? Number(line.picked_qty) : Number(line.required_qty)
    const next = Math.min(qtyOf(line) + 1, ceiling)
    setQty(line.id, next)
    setFlash(next >= ceiling
      ? `${line.product.name} — ${next} of ${ceiling}, that's all of them`
      : `${line.product.name} — ${next} of ${ceiling}`)
    setTimeout(() => setFlash(''), 2000)
  }

  if (isLoading || !list) {
    return (
      <div className="fixed inset-0 z-[70] flex items-center justify-center bg-black/50" onClick={onClose}>
        <div className="w-full max-w-2xl h-64 rounded-2xl animate-pulse" style={{ background: 'var(--bg-card)' }} />
      </div>
    )
  }

  const st = PICK_STATUS[list.status] || { label: list.status, color: 'var(--text-muted)' }
  const closed = ['shipped', 'delivered', 'cancelled'].includes(list.status)

  return (
    <div className="fixed inset-0 z-[70] flex items-start justify-center p-4 overflow-y-auto bg-black/50" onClick={onClose}>
      <div className="w-full max-w-2xl rounded-2xl mt-[4vh] mb-8" onClick={e => e.stopPropagation()}
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>

        <div className="flex items-center gap-2 px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <PackageCheck size={16} style={{ color: st.color }} />
          <div className="min-w-0">
            <h2 className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>
              {list.code}
              <span className="ml-2 text-[10px] font-bold px-2 py-0.5 rounded-lg"
                style={{ background: `color-mix(in srgb, ${st.color} 15%, transparent)`, color: st.color }}>{st.label}</span>
            </h2>
            <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
              {list.voucher?.customer_name || '—'} · {list.warehouse?.name}
              {list.picker ? ` · picked by ${list.picker.name}` : ''}
            </p>
          </div>
          <button onClick={onClose} className="ml-auto hover:opacity-70" aria-label="Close">
            <X size={18} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        <div className="p-5">
          {!closed && (
            <div className="mb-4">
              <ScanInput onScan={onScan}
                placeholder={packing ? 'Scan each item into the carton…' : 'Scan each item as you pick it…'}
                hint={packing
                  ? 'Every scan adds one to the carton. Packing is a second count — it is meant to be able to disagree with the pick.'
                  : 'Every scan adds one to your hand. Nothing moves in the ledger until the parcel ships.'} />
              {flash && (
                <p className="text-[11px] mt-1.5 font-semibold"
                  style={{ color: flash.startsWith('Nothing') ? 'var(--color-danger-500)' : INV_ACCENT }}>{flash}</p>
              )}
            </div>
          )}

          <div className="overflow-x-auto rounded-xl mb-4" style={{ border: '1px solid var(--border)' }}>
            <table className="w-full text-xs" style={{ minWidth: 560 }}>
              <thead>
                <tr className="text-left text-[10px] uppercase tracking-wide"
                  style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                  <th className="px-3 py-2 font-bold">Item</th>
                  <th className="px-3 py-2 font-bold">Where to go</th>
                  <th className="px-3 py-2 font-bold text-right">Needed</th>
                  <th className="px-3 py-2 font-bold text-right">Picked</th>
                  <th className="px-3 py-2 font-bold text-right">Packed</th>
                </tr>
              </thead>
              <tbody>
                {lines.map(l => {
                  const short = Number(l.picked_qty) < Number(l.required_qty)
                  return (
                    <tr key={l.id} style={{ borderBottom: '1px solid var(--border)' }}>
                      <td className="px-3 py-2">
                        <span className="block font-semibold" style={{ color: 'var(--text-h)' }}>{l.product?.name}</span>
                        <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{l.product?.sku}</span>
                      </td>
                      <td className="px-3 py-2">
                        {/* The picker's route: bin first, then the batch FEFO chose. */}
                        <span className="flex items-center gap-1 text-[11px]" style={{ color: 'var(--text-h)' }}>
                          <MapPin size={10} style={{ color: '#0ea5e9' }} />
                          {l.location?.code || l.location?.name || 'anywhere in the warehouse'}
                        </span>
                        {l.batch && (
                          <span className="flex items-center gap-1 text-[10px] mt-0.5" style={{ color: '#f59e0b' }}>
                            <Layers3 size={9} /> {l.batch.batch_no}
                            {l.batch.expiry_date ? ` · exp ${String(l.batch.expiry_date).slice(0, 10)}` : ''}
                          </span>
                        )}
                      </td>
                      <td className="px-3 py-2 text-right tabular-nums" style={{ color: 'var(--text-muted)' }}>{fmtQty(l.required_qty)}</td>
                      <td className="px-3 py-2 text-right">
                        {!closed && !packing
                          ? <QtyBox value={qtyOf(l)} max={Number(l.required_qty)} onChange={v => setQty(l.id, v)} />
                          : <span className="tabular-nums font-semibold" style={{ color: short ? '#f59e0b' : 'var(--text-h)' }}>{fmtQty(l.picked_qty)}</span>}
                      </td>
                      <td className="px-3 py-2 text-right">
                        {!closed && packing
                          ? <QtyBox value={qtyOf(l)} max={Number(l.picked_qty)} onChange={v => setQty(l.id, v)} />
                          : <span className="tabular-nums" style={{ color: 'var(--text-h)' }}>{fmtQty(l.packed_qty)}</span>}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          {/* Reported, not enforced — a short pack is sometimes the right answer
              and the dispatcher is the one who should decide. */}
          {check && !check.ok && (
            <div className="rounded-xl px-3 py-2.5 mb-4"
              style={{ background: 'color-mix(in srgb, #f59e0b 10%, transparent)', border: '1px solid #f59e0b' }}>
              <p className="flex items-center gap-1.5 text-[11px] font-bold mb-1" style={{ color: '#b45309' }}>
                <AlertTriangle size={12} /> This parcel does not match the order
              </p>
              {check.problems.map((p, i) => (
                <p key={i} className="text-[11px]" style={{ color: 'var(--text-body)' }}>· {p.product} — {p.detail}</p>
              ))}
              <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>
                Shipping anyway is allowed; the delivery document is corrected to what actually went.
              </p>
            </div>
          )}

          {list.status === 'packed' && (
            <div className="rounded-xl p-3 mb-4" style={{ background: 'var(--bg-input)' }}>
              <p className="text-[11px] font-bold mb-2" style={{ color: 'var(--text-h)' }}>Shipment</p>
              <div className="flex flex-wrap gap-2">
                <div style={{ minWidth: 170 }}>
                  <Select size="sm" value={ship.carrier} onChange={v => setShip(s => ({ ...s, carrier: v }))}
                    placeholder="Courier"
                    options={carriers.map(c => ({
                      value: c.key,
                      label: c.connected ? c.label : `${c.label} (type number)`,
                    }))} />
                </div>
                <input value={ship.tracking_number} onChange={e => setShip(s => ({ ...s, tracking_number: e.target.value }))}
                  placeholder="Tracking number from the slip"
                  className="rounded-lg outline-none font-mono flex-1"
                  style={{ minWidth: 180, padding: '7px 10px', fontSize: 12, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
                <input type="number" min={1} value={ship.parcel_count}
                  onChange={e => setShip(s => ({ ...s, parcel_count: Number(e.target.value) }))}
                  placeholder="Parcels" title="How many parcels"
                  className="rounded-lg outline-none text-right"
                  style={{ width: 80, padding: '7px 10px', fontSize: 12, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
                <input type="number" min={0} step="0.001" value={ship.parcel_weight}
                  onChange={e => setShip(s => ({ ...s, parcel_weight: e.target.value }))}
                  placeholder="kg" title="Total weight"
                  className="rounded-lg outline-none text-right"
                  style={{ width: 80, padding: '7px 10px', fontSize: 12, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
              </div>
              <p className="text-[10px] mt-2" style={{ color: 'var(--text-muted)' }}>
                No courier account is connected, so type the number off the slip — the tracking link still works.
              </p>
            </div>
          )}

          {list.status === 'shipped' && (
            <div className="rounded-xl p-3 mb-4" style={{ background: 'var(--bg-input)' }}>
              <p className="text-[11px] font-bold mb-2" style={{ color: 'var(--text-h)' }}>Proof of delivery</p>
              <input value={received} onChange={e => setReceived(e.target.value)}
                placeholder="Who signed for it? e.g. R. Mehta, security desk"
                className="w-full rounded-lg outline-none"
                style={{ padding: '7px 10px', fontSize: 12, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
            </div>
          )}

          {list.tracking_number && (
            <p className="flex items-center gap-1.5 text-[11px] mb-4" style={{ color: 'var(--text-muted)' }}>
              <Truck size={12} /> {list.carrier} · <span className="font-mono">{list.tracking_number}</span>
              {list.tracking_url && (
                <a href={list.tracking_url} target="_blank" rel="noreferrer"
                  className="flex items-center gap-0.5 font-bold" style={{ color: INV_ACCENT }}>
                  Track <ExternalLink size={10} />
                </a>
              )}
            </p>
          )}

          {err && (
            <p className="text-xs px-3 py-2 rounded-lg mb-3"
              style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>
          )}

          {/* Only the next action, never all of them. */}
          <div className="flex flex-wrap items-center gap-2">
            {(list.status === 'open' || list.status === 'picking') && (
              <>
                <Action onClick={() => savePick.mutate()} busy={savePick.isPending} color={INV_ACCENT}>Save picking</Action>
                <Action onClick={() => finishPick.mutate()} busy={finishPick.isPending} color="#8b5cf6">
                  Finished picking <ArrowRight size={12} />
                </Action>
              </>
            )}
            {list.status === 'picked' && (
              <Action onClick={() => savePack.mutate()} busy={savePack.isPending} color="#f59e0b">
                Packed into the carton <ArrowRight size={12} />
              </Action>
            )}
            {list.status === 'packed' && (
              <>
                <Action onClick={() => savePack.mutate()} busy={savePack.isPending} color="var(--text-muted)" ghost>Re-count carton</Action>
                <Action onClick={() => doShip.mutate()} busy={doShip.isPending} color="#0ea5e9">
                  Ship it — stock leaves now <ArrowRight size={12} />
                </Action>
              </>
            )}
            {list.status === 'shipped' && (
              <Action onClick={() => doDeliver.mutate()} busy={doDeliver.isPending} color="#10B981">Mark delivered</Action>
            )}

            {!closed && (
              <button onClick={() => doCancel.mutate()} disabled={doCancel.isPending}
                className="ml-auto flex items-center gap-1 text-[11px] font-bold"
                style={{ color: 'var(--color-danger-500)' }}>
                <Ban size={12} /> Cancel — frees the reserved stock
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}

function QtyBox({ value, max, onChange }) {
  const full = Number(value) >= Number(max)
  return (
    <input type="number" min={0} max={max} step="0.001" value={value}
      onChange={e => onChange(Math.min(Number(e.target.value) || 0, Number(max)))}
      className="rounded-lg outline-none text-right tabular-nums"
      style={{
        width: 74, padding: '5px 8px', fontSize: 12,
        background: 'var(--bg-input)', color: 'var(--text-h)',
        border: `1px solid ${full ? '#10B981' : 'var(--border)'}`,
      }} />
  )
}

function Action({ onClick, busy, color, ghost, children }) {
  return (
    <button onClick={onClick} disabled={busy}
      className="flex items-center gap-1.5 text-xs font-bold px-3.5 py-2 rounded-xl disabled:opacity-40"
      style={ghost
        ? { border: '1px solid var(--border)', color }
        : { background: color, color: '#fff' }}>
      {busy ? 'Working…' : children}
    </button>
  )
}
