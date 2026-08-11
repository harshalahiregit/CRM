import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeftRight, Search, X, Truck, AlertTriangle, Ban, ArrowRight,
  Clock, ShieldCheck, Inbox, MapPin, FileDown,
} from 'lucide-react'
import { inventoryApi, INV_ACCENT, TRANSFER_STATUS, fmtQty, money } from '@/services/inventoryApi'
import { useAuth } from '@/context/AuthContext'
import Select from '@/components/ui/Select'

/**
 * Consignments — stock between two warehouses.
 *
 * The screen exists to make one thing visible that used to be invisible: goods
 * that have left one shelf and not yet reached another. While they are on the
 * road they belong to the transit warehouse, which is a real place with a real
 * balance, so "where is it?" always has an answer.
 */
export default function InventoryTransfers() {
  const qc = useQueryClient()
  const { user } = useAuth()
  const [status, setStatus] = useState('')
  const [overdue, setOverdue] = useState(false)
  const [search, setSearch] = useState('')
  const [openId, setOpenId] = useState(null)
  const [selected, setSelected] = useState(() => new Set())

  const isAdmin = user?.role === 'admin'

  const params = {}
  if (status) params.status = status
  if (overdue) params.overdue = 1
  if (search.trim()) params.search = search.trim()

  const { data: rows = [], isLoading } = useQuery({
    queryKey: ['inv-transfers', params],
    queryFn: () => inventoryApi.transfers.list(params),
  })

  // The audit of the feature itself. Only shown when it has something to say —
  // a permanent green tick teaches people to stop reading it.
  const { data: recon } = useQuery({
    queryKey: ['inv-transit-reconcile'],
    queryFn: inventoryApi.transfers.reconcile,
    enabled: isAdmin,
  })

  const bust = () => {
    qc.invalidateQueries({ queryKey: ['inv-transfers'] })
    qc.invalidateQueries({ queryKey: ['inv-transit-reconcile'] })
    qc.invalidateQueries({ queryKey: ['inv-summary'] })
    qc.invalidateQueries({ queryKey: ['inv-vouchers'] })
  }

  /* Selection helpers */
  const allChecked = rows.length > 0 && rows.every(r => selected.has(r.id))
  const toggleAll  = () => setSelected(allChecked ? new Set() : new Set(rows.map(r => r.id)))
  const toggleOne  = (id, e) => { e.stopPropagation(); setSelected(s => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n }) }

  const exportSelected = () => {
    const sel = rows.filter(r => selected.has(r.id))
    const cols = [
      ['Code', r => r.code], ['From', r => r.from_warehouse?.name || ''], ['To', r => r.to_warehouse?.name || ''],
      ['Status', r => r.status], ['Lines', r => r.lines_count ?? 0], ['Expected', r => r.expected_at ? String(r.expected_at).slice(0,10) : ''],
      ['Vehicle / Carrier', r => r.vehicle_no || r.tracking_number || ''],
    ]
    const esc = v => `"${String(v ?? '').replace(/"/g, '""')}"`
    const csv = [cols.map(c => esc(c[0])).join(','), ...sel.map(r => cols.map(c => esc(c[1](r))).join(','))].join('\n')
    const url = URL.createObjectURL(new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' }))
    const a = document.createElement('a'); a.href = url; a.download = `consignments-${new Date().toISOString().slice(0,10)}.csv`; a.click()
    URL.revokeObjectURL(url)
  }

  return (
    <div>
      <header className="flex flex-wrap items-center gap-2 mb-1">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${INV_ACCENT} 14%, transparent)` }}>
          <ArrowLeftRight size={17} style={{ color: INV_ACCENT }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Consignments</h1>
        <span className="text-xs px-2 py-0.5 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>{rows.length}</span>
      </header>
      <p className="text-xs mb-4 ml-10" style={{ color: 'var(--text-muted)' }}>
        Raise one from an internal delivery note. Between dispatch and receipt the stock sits in transit — on nobody's shelf.
      </p>

      {recon && !recon.ok && (
        <div className="rounded-2xl px-4 py-3 mb-4"
          style={{ background: 'color-mix(in srgb, var(--color-danger-500) 10%, transparent)', border: '1px solid var(--color-danger-500)' }}>
          <p className="flex items-center gap-1.5 text-xs font-bold mb-1" style={{ color: 'var(--color-danger-500)' }}>
            <AlertTriangle size={13} /> Stock in transit does not match the open consignments
          </p>
          {recon.problems.map(p => (
            <p key={p.product_id} className="text-[11px]" style={{ color: 'var(--text-body)' }}>
              · {p.product} — in transit {fmtQty(p.in_transit_balance)}, consignments say {fmtQty(p.consignments_say)}
              {' '}({p.difference > 0 ? '+' : ''}{fmtQty(p.difference)})
            </p>
          ))}
          <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>
            Something moved stock in or out of transit without going through a consignment.
          </p>
        </div>
      )}

      <div className="flex flex-wrap items-center gap-2 mb-4 p-2.5 rounded-2xl"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <div className="relative flex-1" style={{ minWidth: 180 }}>
          <Search size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search code, vehicle or tracking…"
            className="w-full rounded-xl outline-none"
            style={{ padding: '8px 12px 8px 33px', fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
        </div>
        <div style={{ minWidth: 160 }}>
          <Select size="sm" value={status} onChange={setStatus} placeholder="Any stage"
            options={[{ value: '', label: 'Any stage' },
              ...Object.entries(TRANSFER_STATUS).map(([v, m]) => ({ value: v, label: m.label, dot: m.color }))]} />
        </div>
        {/* Late AND still on the road — a consignment that arrived late is no
            longer anybody's problem. */}
        <button onClick={() => setOverdue(v => !v)}
          className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
          style={{
            background: overdue ? 'color-mix(in srgb, var(--color-danger-500) 14%, transparent)' : 'var(--bg-input)',
            border: `1px solid ${overdue ? 'var(--color-danger-500)' : 'var(--border)'}`,
            color: overdue ? 'var(--color-danger-500)' : 'var(--text-muted)',
          }}>
          <Clock size={13} /> Overdue
        </button>
      </div>

      {isLoading && <div className="h-40 rounded-2xl animate-pulse" style={{ background: 'var(--bg-card)' }} />}

      {!isLoading && rows.length === 0 && (
        <div className="rounded-2xl p-10 text-center" style={{ background: 'var(--bg-card)', border: '1px dashed var(--border)' }}>
          <Inbox size={26} style={{ color: 'var(--text-muted)', margin: '0 auto 10px' }} />
          <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>Nothing on the road.</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            Open an internal delivery note and send it as a consignment.
          </p>
        </div>
      )}

      {/* Selection bar + select-all */}
      {rows.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 mb-2">
          <label className="flex items-center gap-1.5 text-xs cursor-pointer" style={{ color: 'var(--text-muted)' }}>
            <input type="checkbox" checked={allChecked} onChange={toggleAll}
              style={{ accentColor: INV_ACCENT, width: 13, height: 13 }} />
            Select all
          </label>
          {selected.size > 0 && (
            <>
              <span className="text-xs font-bold" style={{ color: INV_ACCENT }}>{selected.size} selected</span>
              <button onClick={exportSelected}
                className="flex items-center gap-1 text-xs font-bold px-3 py-1.5 rounded-lg"
                style={{ background: `color-mix(in srgb, ${INV_ACCENT} 10%, transparent)`, border: `1px solid ${INV_ACCENT}`, color: INV_ACCENT }}>
                <FileDown size={13} /> Export selected
              </button>
              <button onClick={() => setSelected(new Set())} className="text-xs" style={{ color: 'var(--text-muted)' }}><X size={13} /></button>
            </>
          )}
        </div>
      )}

      <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fill,minmax(300px,1fr))' }}>
        {rows.map(r => {
          const st = TRANSFER_STATUS[r.status] || { label: r.status, color: 'var(--text-muted)' }
          const late = r.status === 'in_transit' && r.expected_at && new Date(r.expected_at) < new Date()
          return (
            <div key={r.id} className="relative">
              {/* Checkbox overlay — top-left, stops propagation so clicking it
                  doesn't also open the workbench. */}
              <label className="absolute top-2.5 left-2.5 z-10 cursor-pointer"
                onClick={e => e.stopPropagation()}>
                <input type="checkbox" checked={selected.has(r.id)} onChange={e => toggleOne(r.id, e)}
                  style={{ accentColor: INV_ACCENT, width: 14, height: 14 }} />
              </label>
              <button onClick={() => setOpenId(r.id)}
                className="w-full rounded-2xl p-3.5 pl-8 text-left transition-all"
                style={{ background: selected.has(r.id) ? `color-mix(in srgb, ${INV_ACCENT} 8%, var(--bg-card))` : 'var(--bg-card)', border: `1px solid ${late ? 'var(--color-danger-500)' : st.color}` }}>
                <div className="flex items-center gap-2 mb-1.5">
                  <span className="font-mono text-xs font-bold" style={{ color: INV_ACCENT }}>{r.code}</span>
                  <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg"
                    style={{ background: `color-mix(in srgb, ${st.color} 15%, transparent)`, color: st.color }}>{st.label}</span>
                  {late && (
                    <span className="flex items-center gap-0.5 text-[10px] font-bold" style={{ color: 'var(--color-danger-500)' }}>
                      <Clock size={10} /> late
                    </span>
                  )}
                </div>
                <p className="flex items-center gap-1 text-xs font-semibold" style={{ color: 'var(--text-h)' }}>
                  {r.from_warehouse?.name} <ArrowRight size={11} style={{ color: 'var(--text-muted)' }} /> {r.to_warehouse?.name}
                </p>
                <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
                  {r.lines_count} line{r.lines_count === 1 ? '' : 's'}
                  {r.voucher ? ` · ${r.voucher.code}` : ''}
                  {r.expected_at ? ` · due ${String(r.expected_at).slice(0, 10)}` : ''}
                </p>
                {(r.vehicle_no || r.tracking_number) && (
                  <p className="flex items-center gap-1 text-[10px] mt-1 font-mono" style={{ color: 'var(--text-muted)' }}>
                    <Truck size={10} /> {r.vehicle_no || `${r.carrier || ''} ${r.tracking_number}`}
                  </p>
                )}
              </button>
            </div>
          )
        })}
      </div>

      {openId && <TransferWorkbench id={openId} onClose={() => setOpenId(null)} onChanged={bust} />}
    </div>
  )
}

/* ── The workbench ──────────────────────────────────────────────── */

/**
 * One consignment. The panel offers only the action this viewer can actually
 * take at this stage — dispatching belongs to the source, receiving to the
 * destination, and the server decides which of those this person is.
 */
function TransferWorkbench({ id, onClose, onChanged }) {
  const qc = useQueryClient()
  const [draft, setDraft] = useState({})        // lineId -> received qty typed
  const [lost, setLost] = useState({})          // lineId -> qty being written off
  const [reason, setReason] = useState('')
  const [writingOff, setWritingOff] = useState(false)
  const [ship, setShip] = useState({ carrier: '', vehicle_no: '', driver_name: '', driver_phone: '', expected_at: '' })
  const [err, setErr] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['inv-transfers', id], queryFn: () => inventoryApi.transfers.get(id),
  })

  const t = data?.transfer
  const summary = data?.summary
  const lines = t?.lines || []

  const refresh = () => { qc.invalidateQueries({ queryKey: ['inv-transfers'] }); onChanged?.() }
  const run = (fn, after) => ({
    mutationFn: fn,
    onSuccess: () => { setErr(''); setDraft({}); setLost({}); refresh(); after?.() },
    onError: (e) => setErr(e?.message || 'That action failed.'),
  })

  const doDispatch = useMutation(run(() => inventoryApi.transfers.dispatch(id, {
    ...ship, expected_at: ship.expected_at || null,
  })))
  const doReceive = useMutation(run(() => inventoryApi.transfers.receive(id,
    Object.entries(draft).map(([lineId, qty]) => ({ id: Number(lineId), received_qty: Number(qty) })))))
  const doWriteOff = useMutation(run(() => inventoryApi.transfers.writeOff(id,
    Object.entries(lost).map(([lineId, qty]) => ({ id: Number(lineId), lost_qty: Number(qty) })), reason),
    () => { setWritingOff(false); setReason('') }))
  const doCancel = useMutation(run(() => inventoryApi.transfers.cancel(id), onClose))

  if (isLoading || !t) {
    return (
      <div className="fixed inset-0 z-[70] flex items-center justify-center bg-black/50" onClick={onClose}>
        <div className="w-full max-w-3xl h-64 rounded-2xl animate-pulse" style={{ background: 'var(--bg-card)' }} />
      </div>
    )
  }

  const st = TRANSFER_STATUS[t.status] || { label: t.status, color: 'var(--text-muted)' }
  const receiving = t.status === 'in_transit' && data.can_receive

  return (
    <div className="fixed inset-0 z-[70] flex items-start justify-center p-4 overflow-y-auto bg-black/50" onClick={onClose}>
      <div className="w-full max-w-3xl rounded-2xl mt-[4vh] mb-8" onClick={e => e.stopPropagation()}
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>

        <div className="flex items-center gap-2 px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <Truck size={16} style={{ color: st.color }} />
          <div className="min-w-0">
            <h2 className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>
              {t.code}
              <span className="ml-2 text-[10px] font-bold px-2 py-0.5 rounded-lg"
                style={{ background: `color-mix(in srgb, ${st.color} 15%, transparent)`, color: st.color }}>{st.label}</span>
              {summary?.overdue && (
                <span className="ml-1.5 text-[10px] font-bold px-2 py-0.5 rounded-lg"
                  style={{ background: 'color-mix(in srgb, var(--color-danger-500) 15%, transparent)', color: 'var(--color-danger-500)' }}>overdue</span>
              )}
            </h2>
            <p className="flex items-center gap-1 text-[11px]" style={{ color: 'var(--text-muted)' }}>
              {t.from_warehouse?.name} <ArrowRight size={10} /> {t.to_warehouse?.name}
              {t.voucher ? ` · ${t.voucher.code}` : ''}
            </p>
          </div>
          <button onClick={onClose} className="ml-auto hover:opacity-70" aria-label="Close">
            <X size={18} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        <div className="p-5">
          {t.status === 'in_transit' && (
            <p className="flex items-center gap-1.5 text-[11px] mb-3 px-3 py-2 rounded-xl"
              style={{ background: 'color-mix(in srgb, #0ea5e9 10%, transparent)', color: '#0369a1' }}>
              <MapPin size={12} />
              {fmtQty(summary?.outstanding_qty)} unit(s) are held in transit right now — they are off the source's shelf and not yet on the destination's.
            </p>
          )}

          {/* Dispatch details, filled in as the lorry leaves. */}
          {t.status === 'draft' && data.can_dispatch && (
            <div className="rounded-xl p-3 mb-4" style={{ background: 'var(--bg-input)' }}>
              <p className="text-[11px] font-bold mb-2" style={{ color: 'var(--text-h)' }}>Who is carrying it</p>
              <div className="flex flex-wrap gap-2">
                {[
                  ['vehicle_no', 'Vehicle number', 150],
                  ['driver_name', 'Driver', 140],
                  ['driver_phone', 'Driver phone', 130],
                  ['carrier', 'Carrier (optional)', 140],
                ].map(([key, ph, w]) => (
                  <input key={key} value={ship[key]} onChange={e => setShip(s => ({ ...s, [key]: e.target.value }))}
                    placeholder={ph} className="rounded-lg outline-none"
                    style={{ minWidth: w, flex: 1, padding: '7px 10px', fontSize: 12, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
                ))}
                <input type="date" value={ship.expected_at} onChange={e => setShip(s => ({ ...s, expected_at: e.target.value }))}
                  title="Expected arrival" className="rounded-lg outline-none"
                  style={{ padding: '7px 10px', fontSize: 12, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
              </div>
              <p className="text-[10px] mt-2" style={{ color: 'var(--text-muted)' }}>
                An expected date is what makes a consignment show up as overdue when it does not turn up.
              </p>
            </div>
          )}

          {(t.vehicle_no || t.driver_name || t.tracking_number) && t.status !== 'draft' && (
            <p className="flex items-center gap-1.5 text-[11px] mb-4" style={{ color: 'var(--text-muted)' }}>
              <Truck size={12} />
              <span className="font-mono">{t.vehicle_no}</span>
              {t.driver_name ? ` · ${t.driver_name}` : ''}{t.driver_phone ? ` · ${t.driver_phone}` : ''}
              {t.tracking_number ? ` · ${t.carrier} ${t.tracking_number}` : ''}
            </p>
          )}

          <div className="overflow-x-auto rounded-xl mb-4" style={{ border: '1px solid var(--border)' }}>
            <table className="w-full text-xs" style={{ minWidth: 600 }}>
              <thead>
                <tr className="text-left text-[10px] uppercase tracking-wide"
                  style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                  <th className="px-3 py-2 font-bold">Item</th>
                  <th className="px-3 py-2 font-bold text-right">Sent</th>
                  <th className="px-3 py-2 font-bold text-right">Arrived</th>
                  <th className="px-3 py-2 font-bold text-right">Written off</th>
                  <th className="px-3 py-2 font-bold text-right">Still on the road</th>
                </tr>
              </thead>
              <tbody>
                {lines.map(l => {
                  const out = Number(l.dispatched_qty) - Number(l.received_qty || 0) - Number(l.lost_qty || 0)
                  return (
                    <tr key={l.id} style={{ borderBottom: '1px solid var(--border)' }}>
                      <td className="px-3 py-2">
                        <span className="block font-semibold" style={{ color: 'var(--text-h)' }}>{l.product?.name}</span>
                        <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{l.product?.sku}</span>
                      </td>
                      <td className="px-3 py-2 text-right tabular-nums" style={{ color: 'var(--text-muted)' }}>{fmtQty(l.dispatched_qty)}</td>
                      <td className="px-3 py-2 text-right">
                        {receiving && !writingOff
                          ? <QtyBox value={draft[l.id] ?? ''} max={out} onChange={v => setDraft(d => ({ ...d, [l.id]: v }))} />
                          : <span className="tabular-nums font-semibold" style={{ color: 'var(--text-h)' }}>
                              {l.received_qty == null ? '—' : fmtQty(l.received_qty)}
                            </span>}
                      </td>
                      <td className="px-3 py-2 text-right">
                        {writingOff && out > 0
                          ? <QtyBox value={lost[l.id] ?? ''} max={out} onChange={v => setLost(d => ({ ...d, [l.id]: v }))} />
                          : <span className="tabular-nums" style={{ color: Number(l.lost_qty) > 0 ? 'var(--color-danger-500)' : 'var(--text-muted)' }}>
                              {fmtQty(l.lost_qty)}
                            </span>}
                      </td>
                      <td className="px-3 py-2 text-right tabular-nums font-bold"
                        style={{ color: out > 0 ? '#0ea5e9' : 'var(--text-muted)' }}>{fmtQty(out)}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          {/* A shortfall is stated plainly and left open. It is not written off
              until somebody decides to, because that decision is the point. */}
          {summary?.problems?.length > 0 && (
            <div className="rounded-xl px-3 py-2.5 mb-4"
              style={{ background: 'color-mix(in srgb, #f59e0b 10%, transparent)', border: '1px solid #f59e0b' }}>
              <p className="flex items-center gap-1.5 text-[11px] font-bold mb-1" style={{ color: '#b45309' }}>
                <AlertTriangle size={12} /> Some of this did not arrive — {money(summary.short_value)} at cost
              </p>
              {summary.problems.map(p => (
                <p key={p.line_id} className="text-[11px]" style={{ color: 'var(--text-body)' }}>
                  · {p.product} — sent {fmtQty(p.dispatched)}, arrived {fmtQty(p.received)}, missing {fmtQty(p.missing)}
                </p>
              ))}
              <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>
                Still held in transit. Receive it late if it turns up, or write it off once you know it will not.
              </p>
            </div>
          )}

          {writingOff && (
            <div className="rounded-xl p-3 mb-3" style={{ background: 'var(--bg-input)' }}>
              <input value={reason} onChange={e => setReason(e.target.value)} autoFocus
                placeholder="What happened to it? e.g. carton fell off the tailgate near Bhiwandi"
                className="w-full rounded-lg outline-none"
                style={{ padding: '7px 10px', fontSize: 12, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
              <p className="text-[10px] mt-1.5" style={{ color: 'var(--text-muted)' }}>
                This goes in the ledger as an ordinary loss against {t.code}, and both warehouses are told.
              </p>
            </div>
          )}

          {err && (
            <p className="text-xs px-3 py-2 rounded-lg mb-3"
              style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>
          )}

          <div className="flex flex-wrap items-center gap-2">
            {t.status === 'draft' && data.can_dispatch && (
              <Action onClick={() => doDispatch.mutate()} busy={doDispatch.isPending} color="#0ea5e9">
                Dispatch — stock leaves the source now <ArrowRight size={12} />
              </Action>
            )}

            {receiving && !writingOff && (
              <Action onClick={() => doReceive.mutate()} busy={doReceive.isPending} color={INV_ACCENT}
                disabled={!Object.keys(draft).length}>
                Receive what arrived
              </Action>
            )}

            {t.status === 'in_transit' && data.can_write_off && summary?.outstanding_qty > 0 && (
              <Action onClick={() => writingOff ? doWriteOff.mutate() : setWritingOff(true)}
                busy={doWriteOff.isPending} color="var(--color-danger-500)" ghost
                disabled={writingOff && (!reason.trim() || !Object.keys(lost).length)}>
                {writingOff ? 'Write it off' : 'Write off what never arrived'}
              </Action>
            )}

            {t.status === 'in_transit' && !data.can_receive && !data.can_write_off && (
              <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                Waiting on {t.to_warehouse?.name} to receive it. A transfer takes two people by design.
              </p>
            )}

            {t.status === 'closed' && (
              <p className="flex items-center gap-1.5 text-[11px]" style={{ color: '#10B981' }}>
                <ShieldCheck size={12} /> Every unit accounted for — arrived, or signed off as lost.
              </p>
            )}

            {!['closed', 'cancelled'].includes(t.status) && data.can_dispatch !== false && (
              <button onClick={() => doCancel.mutate()} disabled={doCancel.isPending}
                className="ml-auto flex items-center gap-1 text-[11px] font-bold"
                style={{ color: 'var(--color-danger-500)' }}>
                <Ban size={12} />
                {t.status === 'in_transit' ? 'Turn it round — stock goes back to source' : 'Cancel'}
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}

function QtyBox({ value, max, onChange }) {
  return (
    <input type="number" min={0} max={max} step="0.001" value={value} placeholder="—"
      onChange={e => onChange(e.target.value === '' ? '' : Math.min(Number(e.target.value) || 0, Number(max)))}
      className="rounded-lg outline-none text-right tabular-nums"
      style={{
        width: 80, padding: '5px 8px', fontSize: 12,
        background: 'var(--bg-input)', color: 'var(--text-h)',
        border: `1px solid ${value === '' ? 'var(--border)' : INV_ACCENT}`,
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
