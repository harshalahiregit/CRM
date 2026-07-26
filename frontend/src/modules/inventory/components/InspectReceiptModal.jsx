import { useState, useEffect, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { X, ClipboardCheck, Paperclip, Trash2, Download, Undo2, AlertTriangle } from 'lucide-react'
import { inventoryApi, INV_ACCENT, fmtQty } from '@/services/inventoryApi'

/**
 * Goods-in inspection.
 *
 * Three numbers that routinely disagree — ordered, received, accepted — and the
 * gap between the last two is the point of the screen. Damaged goods are in the
 * building but must not become sellable stock, so what you accept here is
 * exactly what posting puts on the shelf.
 *
 * Defaults are set so a clean delivery is one click: received and accepted both
 * start at the ordered quantity, and the reason box only appears once you
 * actually reject something.
 */

const QC = {
  passed:  { label: 'All good',  color: '#10B981' },
  partial: { label: 'Part bad',  color: '#f59e0b' },
  failed:  { label: 'Rejected',  color: '#ef4444' },
  pending: { label: 'Not yet',   color: 'var(--text-muted)' },
}

export default function InspectReceiptModal({ voucher, onClose }) {
  const qc = useQueryClient()
  const [rows, setRows] = useState([])
  const [err, setErr] = useState('')
  const fileRef = useRef(null)

  const { data: full, isLoading } = useQuery({
    queryKey: ['inv-voucher', voucher?.id],
    queryFn: () => inventoryApi.vouchers.get('receipt', voucher.id),
    enabled: Boolean(voucher?.id),
  })

  const { data: files = [] } = useQuery({
    queryKey: ['inv-voucher-files', voucher?.id],
    queryFn: () => inventoryApi.vouchers.files('receipt', voucher.id),
    enabled: Boolean(voucher?.id),
  })

  useEffect(() => {
    if (!full?.items) return
    setRows(full.items.map(i => ({
      id: i.id,
      name: i.product?.name || 'Item',
      sku: i.product?.sku,
      ordered: Number(i.quantity),
      // A clean delivery should be one click, so everything defaults to "it all
      // arrived and it's all fine" until someone says otherwise.
      received: i.received_qty != null ? Number(i.received_qty) : Number(i.quantity),
      accepted: i.accepted_qty != null ? Number(i.accepted_qty) : Number(i.quantity),
      reason: i.rejection_reason || '',
    })))
  }, [full])

  const bust = () => {
    qc.invalidateQueries({ queryKey: ['inv-voucher', voucher.id] })
    qc.invalidateQueries({ queryKey: ['inv-vouchers'] })
    qc.invalidateQueries({ queryKey: ['inv-summary'] })
  }

  const save = useMutation({
    mutationFn: () => inventoryApi.vouchers.inspect('receipt', voucher.id, rows.map(r => ({
      id: r.id, received_qty: r.received, accepted_qty: r.accepted, rejection_reason: r.reason || null,
    }))),
    onSuccess: () => { setErr(''); bust(); onClose?.() },
    onError: (e) => setErr(e?.message || 'That inspection could not be saved.'),
  })

  const upload = useMutation({
    mutationFn: (fl) => inventoryApi.vouchers.uploadFiles('receipt', voucher.id, fl, 'inspection'),
    onSuccess: () => { setErr(''); qc.invalidateQueries({ queryKey: ['inv-voucher-files', voucher.id] }) },
    onError: (e) => setErr(e?.message || 'That file could not be attached.'),
  })

  const removeFile = useMutation({
    mutationFn: (fid) => inventoryApi.vouchers.deleteFile('receipt', voucher.id, fid),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['inv-voucher-files', voucher.id] }),
  })

  const returnToVendor = useMutation({
    mutationFn: () => inventoryApi.vouchers.vendorReturn('receipt', voucher.id),
    onSuccess: () => { setErr(''); bust() },
    onError: (e) => setErr(e?.message || 'That return could not be raised.'),
  })

  if (!voucher) return null

  const set = (id, k, v) => setRows(rs => rs.map(r => {
    if (r.id !== id) return r
    const next = { ...r, [k]: v }
    // Accepting can never exceed what arrived — clamped here as well as on the
    // server, so the number in front of you is always one you could save.
    if (k === 'received') next.accepted = Math.min(Number(next.accepted) || 0, Number(v) || 0)
    if (k === 'accepted') next.accepted = Math.min(Number(v) || 0, Number(next.received) || 0)
    return next
  }))

  const totals = rows.reduce((a, r) => ({
    ordered: a.ordered + Number(r.ordered || 0),
    received: a.received + Number(r.received || 0),
    accepted: a.accepted + Number(r.accepted || 0),
  }), { ordered: 0, received: 0, accepted: 0 })
  const rejected = Math.max(0, totals.received - totals.accepted)
  const short = Math.max(0, totals.ordered - totals.received)
  const summary = full?.receiving
  const locked = ['posted', 'cancelled'].includes(full?.status)

  return (
    <div className="fixed inset-0 z-[70] flex items-start justify-center p-4 overflow-y-auto bg-black/50" onClick={onClose}>
      <div className="w-full max-w-3xl rounded-2xl mt-[5vh] mb-8" onClick={e => e.stopPropagation()}
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>

        <div className="flex items-center gap-2 px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <ClipboardCheck size={16} style={{ color: INV_ACCENT }} />
          <div className="min-w-0">
            <h2 className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>
              Inspect delivery · {voucher.code}
            </h2>
            <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
              {voucher.supplier_name || 'No supplier named'} — only what you accept goes on the shelf.
            </p>
          </div>
          <button onClick={onClose} className="ml-auto hover:opacity-70" aria-label="Close">
            <X size={18} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        {isLoading ? (
          <div className="h-40 m-5 rounded-xl animate-pulse" style={{ background: 'var(--bg-input)' }} />
        ) : (
          <div className="p-5">
            {locked && (
              <p className="text-xs px-3 py-2 rounded-lg mb-3"
                style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
                This receipt is {full.status}. Inspection happens before the goods go on the shelf, so it is read-only now.
              </p>
            )}

            <div className="overflow-x-auto rounded-xl mb-4" style={{ border: '1px solid var(--border)' }}>
              <table className="w-full text-xs" style={{ minWidth: 620 }}>
                <thead>
                  <tr className="text-left text-[10px] uppercase tracking-wide"
                    style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                    <th className="px-3 py-2 font-bold">Item</th>
                    <th className="px-3 py-2 font-bold text-right">Ordered</th>
                    <th className="px-3 py-2 font-bold text-right">Arrived</th>
                    <th className="px-3 py-2 font-bold text-right">Usable</th>
                    <th className="px-3 py-2 font-bold">If rejected, why</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map(r => {
                    const rej = Math.max(0, Number(r.received || 0) - Number(r.accepted || 0))
                    return (
                      <tr key={r.id} style={{ borderBottom: '1px solid var(--border)' }}>
                        <td className="px-3 py-2">
                          <span className="block font-semibold" style={{ color: 'var(--text-h)' }}>{r.name}</span>
                          <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{r.sku}</span>
                        </td>
                        <td className="px-3 py-2 text-right tabular-nums" style={{ color: 'var(--text-muted)' }}>{fmtQty(r.ordered)}</td>
                        <td className="px-3 py-2 text-right">
                          <NumBox value={r.received} disabled={locked} onChange={v => set(r.id, 'received', v)} />
                        </td>
                        <td className="px-3 py-2 text-right">
                          <NumBox value={r.accepted} disabled={locked} accent={rej > 0 ? '#f59e0b' : undefined}
                            onChange={v => set(r.id, 'accepted', v)} />
                        </td>
                        <td className="px-3 py-2">
                          {rej > 0 ? (
                            <input value={r.reason} disabled={locked}
                              onChange={e => set(r.id, 'reason', e.target.value)}
                              placeholder="e.g. Two cartons crushed in transit"
                              className="w-full rounded-lg outline-none"
                              style={{ padding: '5px 8px', fontSize: 11, background: 'var(--bg-input)', border: '1px solid #f59e0b', color: 'var(--text-h)' }} />
                          ) : (
                            <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>—</span>
                          )}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>

            {/* Running totals, because the decision people actually make is
                "how much of this delivery can I sell", not per-line arithmetic. */}
            <div className="flex flex-wrap gap-2 mb-4">
              <Tally label="Ordered" value={totals.ordered} />
              <Tally label="Arrived" value={totals.received} />
              <Tally label="Usable" value={totals.accepted} color="#10B981" />
              {rejected > 0 && <Tally label="Rejected" value={rejected} color="#ef4444" />}
              {short > 0 && <Tally label="Short-delivered" value={short} color="#f59e0b" />}
              {summary?.qc_status && (
                <span className="text-[10px] font-bold px-2 py-1.5 rounded-lg self-center"
                  style={{
                    background: `color-mix(in srgb, ${QC[summary.qc_status]?.color} 15%, transparent)`,
                    color: QC[summary.qc_status]?.color,
                  }}>
                  {QC[summary.qc_status]?.label}
                </span>
              )}
            </div>

            {summary?.return_due && (
              <div className="flex flex-wrap items-center gap-2 rounded-xl px-3 py-2.5 mb-4"
                style={{ background: 'color-mix(in srgb, #f59e0b 10%, transparent)', border: '1px solid #f59e0b' }}>
                <AlertTriangle size={14} style={{ color: '#b45309' }} />
                <span className="text-xs" style={{ color: 'var(--text-h)' }}>
                  {fmtQty(summary.rejected)} rejected and still with you — the supplier has to take it back.
                </span>
                <button onClick={() => returnToVendor.mutate()} disabled={returnToVendor.isPending}
                  className="ml-auto flex items-center gap-1 text-[11px] font-bold px-2.5 py-1.5 rounded-lg disabled:opacity-40"
                  style={{ background: '#b45309', color: '#fff' }}>
                  <Undo2 size={12} /> Raise vendor return
                </button>
              </div>
            )}

            {/* Attachments — the challan and the QC photos are what an auditor
                asks for, so they live on the document, not in someone's email. */}
            <div className="rounded-xl p-3 mb-4" style={{ background: 'var(--bg-input)' }}>
              <div className="flex items-center gap-2 mb-2">
                <Paperclip size={12} style={{ color: 'var(--text-muted)' }} />
                <span className="text-[11px] font-bold" style={{ color: 'var(--text-h)' }}>
                  Paperwork {files.length > 0 && `· ${files.length}`}
                </span>
                <button onClick={() => fileRef.current?.click()} disabled={upload.isPending}
                  className="ml-auto text-[11px] font-bold" style={{ color: INV_ACCENT }}>
                  {upload.isPending ? 'Uploading…' : 'Attach'}
                </button>
                <input ref={fileRef} type="file" multiple hidden
                  onChange={e => { if (e.target.files?.length) upload.mutate(e.target.files); e.target.value = '' }} />
              </div>
              {files.length === 0 && (
                <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                  Delivery challan, test certificate, photos of the damage.
                </p>
              )}
              <ul className="flex flex-col gap-1">
                {files.map(f => (
                  <li key={f.id} className="flex items-center gap-2 text-[11px]">
                    <span className="truncate" style={{ color: 'var(--text-h)' }}>{f.file_name}</span>
                    <span className="text-[9px] px-1 rounded" style={{ background: 'var(--bg-card)', color: 'var(--text-muted)' }}>{f.kind}</span>
                    <button onClick={() => inventoryApi.vouchers.downloadFile('receipt', voucher.id, f.id, f.file_name)}
                      className="ml-auto hover:opacity-70" aria-label={`Download ${f.file_name}`}>
                      <Download size={12} style={{ color: 'var(--text-muted)' }} />
                    </button>
                    <button onClick={() => removeFile.mutate(f.id)} aria-label={`Remove ${f.file_name}`} className="hover:opacity-70">
                      <Trash2 size={12} style={{ color: 'var(--color-danger-500)' }} />
                    </button>
                  </li>
                ))}
              </ul>
            </div>

            {err && (
              <p className="text-xs px-3 py-2 rounded-lg mb-3"
                style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>
            )}

            {!locked && (
              <div className="flex items-center gap-2">
                <button onClick={() => save.mutate()} disabled={save.isPending}
                  className="text-xs font-bold px-4 py-2 rounded-xl disabled:opacity-40"
                  style={{ background: INV_ACCENT, color: '#fff' }}>
                  {save.isPending ? 'Saving…' : 'Save inspection'}
                </button>
                <button onClick={onClose} className="text-xs font-bold" style={{ color: 'var(--text-muted)' }}>Cancel</button>
                <span className="ml-auto text-[10px]" style={{ color: 'var(--text-muted)' }}>
                  Posting will move {fmtQty(totals.accepted)} onto the shelf.
                </span>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

function NumBox({ value, onChange, disabled, accent }) {
  return (
    <input type="number" min={0} step="0.001" value={value} disabled={disabled}
      onChange={e => onChange(e.target.value === '' ? 0 : Number(e.target.value))}
      className="rounded-lg outline-none text-right tabular-nums"
      style={{
        width: 78, padding: '5px 8px', fontSize: 12,
        background: 'var(--bg-input)', border: `1px solid ${accent || 'var(--border)'}`, color: 'var(--text-h)',
      }} />
  )
}

function Tally({ label, value, color }) {
  return (
    <span className="px-2.5 py-1.5 rounded-lg" style={{ background: 'var(--bg-input)' }}>
      <span className="text-[9px] uppercase tracking-wide block" style={{ color: 'var(--text-muted)' }}>{label}</span>
      <strong className="text-xs tabular-nums" style={{ color: color || 'var(--text-h)' }}>{fmtQty(value)}</strong>
    </span>
  )
}
