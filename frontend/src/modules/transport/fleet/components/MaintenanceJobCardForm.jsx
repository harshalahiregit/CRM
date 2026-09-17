import { useState, useEffect } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { X, Wrench, Plus, Trash2, Check, ShieldAlert } from 'lucide-react'
import { stosApi, STOS_ACCENT, fmtMoney, OPEN_JOB_STATUSES } from '@/services/stosApi'
import Select from '@/components/ui/Select'

/**
 * Open a job card, or close one and try to release the vehicle (Feature 4).
 *
 * Parts and labour are entered as ITEMISED rows and summed, because "18,500"
 * with no breakdown is unauditable the moment anyone queries the bill. The
 * summed total is what gets posted; the rows themselves are a workshop detail
 * the API does not store yet, and the form says so rather than pretending.
 *
 * Closes only via ✕ or Cancel — never a backdrop click.
 */
export default function MaintenanceJobCardForm({ open, onClose, vehicle, job = null, onSaved }) {
  const qc = useQueryClient()
  const closing = Boolean(job)

  const [complaint, setComplaint] = useState('')
  const [diagnosis, setDiagnosis] = useState('')
  const [status, setStatus] = useState('open')
  const [safety, setSafety] = useState(false)
  const [qcPassed, setQcPassed] = useState(true)
  const [parts, setParts] = useState([{ name: '', qty: 1, unit: '' }])
  const [labour, setLabour] = useState([{ name: '', hours: '', rate: '' }])
  const [err, setErr] = useState('')

  useEffect(() => {
    if (!open) return
    setErr('')
    setComplaint(job?.complaint || '')
    setDiagnosis(job?.diagnosis || '')
    setStatus(job?.status || 'open')
    setSafety(Boolean(job?.is_safety_critical))
    setQcPassed(true)
    setParts([{ name: '', qty: 1, unit: '' }])
    setLabour([{ name: '', hours: '', rate: '' }])
  }, [open, job])

  const partsCost = parts.reduce((sum, p) => sum + (Number(p.qty) || 0) * (Number(p.unit) || 0), 0)
  const labourCost = labour.reduce((sum, l) => sum + (Number(l.hours) || 0) * (Number(l.rate) || 0), 0)

  const save = useMutation({
    mutationFn: () => {
      if (closing) {
        return stosApi.maintenance.close(job.id, {
          diagnosis, parts_cost: partsCost, labour_cost: labourCost, qc_passed: qcPassed,
        })
      }

      return stosApi.maintenance.open({
        vehicle_id: vehicle.id, complaint, diagnosis, status,
        parts_cost: partsCost, labour_cost: labourCost, is_safety_critical: safety,
      })
    },
    onSuccess: (result) => {
      qc.invalidateQueries({ queryKey: ['stos-workshop'] })
      qc.invalidateQueries({ queryKey: ['stos-fleet'] })
      qc.invalidateQueries({ queryKey: ['stos-vehicle'] })
      onSaved?.(result)
      onClose?.()
    },
    onError: (e) => setErr(e?.message || 'Could not save that job card.'),
  })

  if (!open) return null

  const submit = (e) => {
    e.preventDefault()
    setErr('')
    if (!closing && complaint.trim().length < 3) {
      return setErr('Say what the vehicle came in for.')
    }
    save.mutate()
  }

  return (
    <div className="fixed inset-0 z-[60] flex items-start justify-center p-4 pt-[8vh] bg-black/50">
      <form
        onSubmit={submit}
        className="w-full max-w-2xl rounded-2xl overflow-hidden flex flex-col"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '84vh' }}
        onKeyDown={(e) => { if (e.key === 'Escape') onClose?.() }}
      >
        <div className="flex items-start justify-between gap-3 px-5 pt-4 pb-3" style={{ borderBottom: '1px solid var(--border)' }}>
          <div className="flex items-center gap-2">
            <span className="w-8 h-8 rounded-xl flex items-center justify-center"
              style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 12%, transparent)` }}>
              <Wrench size={15} style={{ color: STOS_ACCENT }} />
            </span>
            <div>
              <h2 className="font-bold" style={{ color: 'var(--text-h)', fontSize: 15 }}>
                {closing ? `Close ${job.job_card_number}` : 'Open a job card'}
              </h2>
              <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                {closing
                  ? 'Costs are recorded and the vehicle is released if nothing else holds it.'
                  : `${vehicle?.registration_number} comes off the road when this is saved.`}
              </p>
            </div>
          </div>
          <button type="button" onClick={onClose} aria-label="Close" className="p-1.5 rounded-lg shrink-0" style={{ color: 'var(--text-muted)' }}>
            <X size={16} />
          </button>
        </div>

        <div className="px-5 py-4 overflow-y-auto flex-1 space-y-3">
          {!closing && (
            <>
              <Field label="Complaint *" hint="What the driver reported">
                <textarea rows={2} value={complaint} onChange={(e) => setComplaint(e.target.value)}
                  placeholder="Brake judder under load" className={inputClass} style={inputStyle} />
              </Field>

              <div className="grid grid-cols-2 gap-3">
                <Field label="Status">
                  <Select size="sm" value={status} onChange={setStatus} options={OPEN_JOB_STATUSES} ariaLabel="Job status" />
                </Field>
                <Field label="Severity">
                  <label className="flex items-center gap-2 rounded-xl px-3 py-2 cursor-pointer"
                    style={{
                      background: safety ? 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)' : 'var(--bg-input)',
                      border: `1px solid ${safety ? 'var(--color-danger-500)' : 'var(--border)'}`,
                    }}>
                    <input type="checkbox" checked={safety} onChange={(e) => setSafety(e.target.checked)} />
                    <ShieldAlert size={13} style={{ color: 'var(--color-danger-500)' }} />
                    <span className="text-[11px] font-bold" style={{ color: 'var(--text-h)' }}>Safety-critical</span>
                  </label>
                </Field>
              </div>
              {safety && (
                <p className="text-[11px] -mt-1" style={{ color: 'var(--color-danger-500)' }}>
                  A safety-critical card blocks this vehicle from being allocated until it is closed.
                </p>
              )}
            </>
          )}

          <Field label={closing ? 'Diagnosis *' : 'Diagnostic notes'} hint="What the workshop actually found">
            <textarea rows={2} value={diagnosis} onChange={(e) => setDiagnosis(e.target.value)}
              placeholder="Warped discs — replaced both sides" className={inputClass} style={inputStyle} />
          </Field>

          {/* ── Itemised parts ──────────────────────────────────── */}
          <ItemTable
            title="Replacement parts" total={partsCost}
            columns={['Part', 'Qty', 'Unit cost']}
            rows={parts} setRows={setParts}
            blank={{ name: '', qty: 1, unit: '' }}
            render={(row, update) => (
              <>
                <input value={row.name} onChange={(e) => update({ name: e.target.value })}
                  placeholder="Brake disc" className={cellClass} style={inputStyle} />
                <input type="number" step="1" inputMode="numeric" value={row.qty}
                  onChange={(e) => update({ qty: e.target.value })} className={cellClass} style={inputStyle} />
                <input type="number" step="0.01" inputMode="decimal" value={row.unit}
                  onChange={(e) => update({ unit: e.target.value })} className={cellClass} style={inputStyle} />
              </>
            )}
          />

          {/* ── Itemised labour ─────────────────────────────────── */}
          <ItemTable
            title="Labour" total={labourCost}
            columns={['Task', 'Hours', 'Rate/hr']}
            rows={labour} setRows={setLabour}
            blank={{ name: '', hours: '', rate: '' }}
            render={(row, update) => (
              <>
                <input value={row.name} onChange={(e) => update({ name: e.target.value })}
                  placeholder="Brake overhaul" className={cellClass} style={inputStyle} />
                <input type="number" step="0.5" inputMode="decimal" value={row.hours}
                  onChange={(e) => update({ hours: e.target.value })} className={cellClass} style={inputStyle} />
                <input type="number" step="0.01" inputMode="decimal" value={row.rate}
                  onChange={(e) => update({ rate: e.target.value })} className={cellClass} style={inputStyle} />
              </>
            )}
          />

          <div className="flex items-center justify-between rounded-xl px-3 py-2.5"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>Total</span>
            <span className="text-sm font-black" style={{ color: STOS_ACCENT }}>{fmtMoney(partsCost + labourCost)}</span>
          </div>
          <p className="text-[10px] -mt-1" style={{ color: 'var(--text-muted)' }}>
            Parts and labour totals are stored. The individual lines are not kept yet — they are a workshop
            detail the API has no table for, so record anything you need to keep in the diagnosis.
          </p>

          {closing && (
            <label className="flex items-center gap-2 rounded-xl px-3 py-2.5 cursor-pointer"
              style={{
                background: qcPassed ? 'var(--bg-input)' : 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)',
                border: `1px solid ${qcPassed ? 'var(--border)' : 'var(--color-danger-500)'}`,
              }}>
              <input type="checkbox" checked={qcPassed} onChange={(e) => setQcPassed(e.target.checked)} />
              <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>QC passed — safe to release</span>
            </label>
          )}
          {closing && !qcPassed && (
            <p className="text-[11px] -mt-1" style={{ color: 'var(--color-danger-500)' }}>
              The card will close but the vehicle stays in the workshop.
            </p>
          )}

          {err && (
            <p className="text-xs px-3 py-2 rounded-lg"
              style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>
              {err}
            </p>
          )}
        </div>

        <div className="flex items-center justify-end gap-2 px-5 py-3"
          style={{ background: 'var(--bg-input)', borderTop: '1px solid var(--border)' }}>
          <button type="button" onClick={onClose} className="text-xs font-semibold px-4 py-2 rounded-xl"
            style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
            Cancel
          </button>
          <button type="submit" disabled={save.isPending}
            className="flex items-center gap-1.5 text-xs font-bold px-4 py-2 rounded-xl disabled:opacity-60"
            style={{ background: closing ? 'var(--color-success-500, #10b981)' : STOS_ACCENT, color: '#fff' }}>
            <Check size={13} />
            {save.isPending ? 'Saving…' : (closing ? 'Close & release vehicle' : 'Open job card')}
          </button>
        </div>
      </form>
    </div>
  )
}

function ItemTable({ title, columns, rows, setRows, blank, render, total }) {
  const update = (i, patch) => setRows(rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r)))

  return (
    <div>
      <div className="flex items-center gap-2 mb-1.5">
        <span className="text-[11px] font-bold" style={{ color: 'var(--text-muted)' }}>{title}</span>
        <span className="text-[11px] font-bold ml-auto" style={{ color: STOS_ACCENT }}>{fmtMoney(total)}</span>
      </div>

      <div className="grid gap-1" style={{ gridTemplateColumns: '1fr 70px 90px 28px' }}>
        {columns.map((c) => (
          <span key={c} className="text-[10px] font-semibold" style={{ color: 'var(--text-muted)' }}>{c}</span>
        ))}
        <span />

        {rows.map((row, i) => (
          <Row key={i} onRemove={rows.length > 1 ? () => setRows(rows.filter((_, idx) => idx !== i)) : null}>
            {render(row, (patch) => update(i, patch))}
          </Row>
        ))}
      </div>

      <button type="button" onClick={() => setRows([...rows, { ...blank }])}
        className="flex items-center gap-1 text-[11px] font-semibold mt-1.5" style={{ color: STOS_ACCENT }}>
        <Plus size={11} /> Add row
      </button>
    </div>
  )
}

function Row({ children, onRemove }) {
  return (
    <>
      {children}
      {onRemove ? (
        <button type="button" onClick={onRemove} aria-label="Remove row"
          className="flex items-center justify-center" style={{ color: 'var(--color-danger-500)' }}>
          <Trash2 size={12} />
        </button>
      ) : <span />}
    </>
  )
}

const inputClass = 'w-full text-xs rounded-xl px-3 py-2'
const cellClass = 'w-full text-[11px] rounded-lg px-2 py-1.5'
const inputStyle = { background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text)' }

function Field({ label, hint, children }) {
  return (
    <div>
      <label className="text-[11px] font-bold block mb-1" style={{ color: 'var(--text-muted)' }}>{label}</label>
      {children}
      {hint && <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>{hint}</p>}
    </div>
  )
}
