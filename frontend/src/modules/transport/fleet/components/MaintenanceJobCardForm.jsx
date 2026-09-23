import { useState, useEffect } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { X, Wrench, Plus, Trash2, Check, ShieldAlert } from 'lucide-react'
import { stosApi, STOS_ACCENT, fmtMoney, OPEN_JOB_STATUSES, QC_RESULTS } from '@/services/stosApi'
import Select from '@/components/ui/Select'

/**
 * Open a job card, or close one and try to release the vehicle (Feature 4).
 *
 * Parts and labour are entered as ITEMISED rows and POSTED as rows, because
 * "18,500" with no breakdown is unauditable the moment anyone queries the bill
 * and useless when a warranty claim comes back six months later. The server
 * stores each line and sums the totals from them, so the card and its total can
 * never disagree.
 *
 * Closes only via ✕ or Cancel — never a backdrop click.
 */
export default function MaintenanceJobCardForm({ open, onClose, vehicle, job = null, condemnation = null, onSaved }) {
  const qc = useQueryClient()
  const closing = Boolean(job)

  const [complaint, setComplaint] = useState('')
  const [diagnosis, setDiagnosis] = useState('')
  const [workshopName, setWorkshopName] = useState('')
  const [status, setStatus] = useState('OPEN')
  const [safety, setSafety] = useState(false)
  const [qcResult, setQcResult] = useState('PASS')
  const [roadTested, setRoadTested] = useState(false)
  const [clears, setClears] = useState(false)
  const [parts, setParts] = useState([blankPart()])
  const [labour, setLabour] = useState([blankLabour()])
  const [err, setErr] = useState('')

  useEffect(() => {
    if (!open) return
    setErr('')
    setComplaint(job?.complaint || '')
    setDiagnosis(job?.diagnosis || '')
    setWorkshopName(job?.workshop_name || '')
    setStatus(job?.status || 'OPEN')
    setSafety(Boolean(job?.is_safety_critical))
    setQcResult(job?.qc_result || 'PASS')
    setRoadTested(Boolean(job?.road_tested))
    setClears(false)

    // Prefill from what is stored, now that the lines survive. Re-opening a
    // card and seeing the parts you entered is the whole point of T-30.
    setParts(job?.parts?.length ? job.parts.map(fromPart) : [blankPart()])
    setLabour(job?.labour?.length ? job.labour.map(fromLabour) : [blankLabour()])
  }, [open, job])

  const partsCost = parts.reduce((sum, p) => sum + (Number(p.qty) || 0) * (Number(p.unit) || 0), 0)
  const labourCost = labour.reduce((sum, l) => sum + (Number(l.hours) || 0) * (Number(l.rate) || 0), 0)

  const save = useMutation({
    mutationFn: () => {
      const lines = { parts: parts.filter(hasPart).map(toPart), labour: labour.filter(hasLabour).map(toLabour) }

      if (closing) {
        return stosApi.maintenance.close(job.id, {
          diagnosis, workshop_name: workshopName || null,
          qc_result: qcResult, road_tested: roadTested,
          // Only meaningful on a PASS; the server drops it otherwise.
          clears_job_id: clears && condemnation ? condemnation.id : null,
          ...lines,
        })
      }

      return stosApi.maintenance.open({
        vehicle_id: vehicle.id, complaint, diagnosis, status,
        workshop_name: workshopName || null, is_safety_critical: safety, ...lines,
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

  const releases = !closing || qcResult === 'PASS'

  return (
    <div className="fixed inset-0 z-[60] flex items-start justify-center p-4 pt-[8vh] bg-black/50">
      <form
        onSubmit={submit}
        className="w-full max-w-3xl rounded-2xl overflow-hidden flex flex-col"
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

          <div className="grid grid-cols-2 gap-3">
            <Field label={closing ? 'Diagnosis *' : 'Diagnostic notes'} hint="What the workshop actually found">
              <textarea rows={2} value={diagnosis} onChange={(e) => setDiagnosis(e.target.value)}
                placeholder="Warped discs — replaced both sides" className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Yard / workshop" hint="Who did the work — the first question when it comes back">
              <input value={workshopName} onChange={(e) => setWorkshopName(e.target.value)}
                placeholder="Bhiwandi yard — Bay 3" className={inputClass} style={inputStyle} />
            </Field>
          </div>

          {/* ── Itemised parts ──────────────────────────────────── */}
          <ItemTable
            title="Replacement parts" total={partsCost}
            columns={['Part', 'Qty', 'Unit cost', 'Supplier', 'Warr. mo']}
            grid="1fr 56px 84px 120px 68px 28px"
            rows={parts} setRows={setParts} blank={blankPart()}
            render={(row, update) => (
              <>
                <input value={row.name} onChange={(e) => update({ name: e.target.value })}
                  placeholder="Brake disc" className={cellClass} style={inputStyle} />
                <input type="number" step="1" inputMode="numeric" value={row.qty}
                  onChange={(e) => update({ qty: e.target.value })} className={cellClass} style={inputStyle} />
                <input type="number" step="0.01" inputMode="decimal" value={row.unit}
                  onChange={(e) => update({ unit: e.target.value })} className={cellClass} style={inputStyle} />
                <input value={row.supplier} onChange={(e) => update({ supplier: e.target.value })}
                  placeholder="TVS Auto" className={cellClass} style={inputStyle} />
                <input type="number" step="1" inputMode="numeric" value={row.warranty}
                  onChange={(e) => update({ warranty: e.target.value })} className={cellClass} style={inputStyle} />
              </>
            )}
          />

          {/* ── Itemised labour ─────────────────────────────────── */}
          <ItemTable
            title="Labour" total={labourCost}
            columns={['Task', 'Hours', 'Rate/hr', 'Technician']}
            grid="1fr 64px 84px 140px 28px"
            rows={labour} setRows={setLabour} blank={blankLabour()}
            render={(row, update) => (
              <>
                <input value={row.name} onChange={(e) => update({ name: e.target.value })}
                  placeholder="Brake overhaul" className={cellClass} style={inputStyle} />
                <input type="number" step="0.5" inputMode="decimal" value={row.hours}
                  onChange={(e) => update({ hours: e.target.value })} className={cellClass} style={inputStyle} />
                <input type="number" step="0.01" inputMode="decimal" value={row.rate}
                  onChange={(e) => update({ rate: e.target.value })} className={cellClass} style={inputStyle} />
                <input value={row.tech} onChange={(e) => update({ tech: e.target.value })}
                  placeholder="R. Kadam" className={cellClass} style={inputStyle} />
              </>
            )}
          />

          <div className="flex items-center justify-between rounded-xl px-3 py-2.5"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>Total</span>
            <span className="text-sm font-black" style={{ color: STOS_ACCENT }}>{fmtMoney(partsCost + labourCost)}</span>
          </div>
          <p className="text-[10px] -mt-1" style={{ color: 'var(--text-muted)' }}>
            Every line is stored against this card. The totals are summed from them by the server, so the
            card and its total cannot drift apart.
          </p>

          {closing && (
            <>
              <Field label="QC result" hint="A critical failure keeps holding the vehicle after this card closes, until a later QC clears it">
                <Select size="sm" value={qcResult} onChange={setQcResult} options={QC_RESULTS} ariaLabel="QC result" />
              </Field>

              <label className="flex items-center gap-2 rounded-xl px-3 py-2.5 cursor-pointer"
                style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <input type="checkbox" checked={roadTested} onChange={(e) => setRoadTested(e.target.checked)} />
                <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>Road tested after repair</span>
              </label>

              {/* T-31 — clearing a condemnation is a deliberate act naming the
                  card it answers, never a side effect of unrelated work. */}
              {condemnation && qcResult === 'PASS' && (
                <label className="flex items-start gap-2 rounded-xl px-3 py-2.5 cursor-pointer"
                  style={{
                    background: clears ? 'color-mix(in srgb, var(--color-success-500, #10b981) 12%, transparent)' : 'var(--bg-input)',
                    border: `1px solid ${clears ? 'var(--color-success-500, #10b981)' : 'var(--border)'}`,
                  }}>
                  <input type="checkbox" checked={clears} onChange={(e) => setClears(e.target.checked)} className="mt-0.5" />
                  <span className="text-[11px]" style={{ color: 'var(--text-h)' }}>
                    <span className="font-bold">This re-test clears {condemnation.job_card_number}.</span>{' '}
                    <span style={{ color: 'var(--text-muted)' }}>
                      That card condemned this vehicle. Until something clears it the vehicle stays
                      in the workshop however many other cards are closed.
                    </span>
                  </span>
                </label>
              )}

              {condemnation && qcResult === 'PASS' && !clears && (
                <p className="text-[11px] -mt-1" style={{ color: 'var(--color-danger-500)' }}>
                  The vehicle will stay in the workshop — {condemnation.job_card_number} is still standing.
                </p>
              )}

              {!releases && (
                <p className="text-[11px] -mt-1" style={{ color: 'var(--color-danger-500)' }}>
                  {qcResult === 'CRITICAL_FAIL'
                    ? 'The card will close and the vehicle stays condemned until a later QC passes it.'
                    : 'The card will close but the vehicle stays in the workshop.'}
                </p>
              )}
            </>
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
            {save.isPending ? 'Saving…' : (closing ? (releases ? 'Close & release vehicle' : 'Close card') : 'Open job card')}
          </button>
        </div>
      </form>
    </div>
  )
}

/* ── row shapes ───────────────────────────────────────────────── */

const blankPart = () => ({ name: '', qty: 1, unit: '', supplier: '', warranty: '' })
const blankLabour = () => ({ name: '', hours: '', rate: '', tech: '' })

const hasPart = (p) => String(p.name || '').trim() !== ''
const hasLabour = (l) => String(l.name || '').trim() !== ''

const toPart = (p) => ({
  part_name: p.name.trim(),
  quantity: Number(p.qty) || 0,
  unit_cost: Number(p.unit) || 0,
  supplier: p.supplier?.trim() || null,
  warranty_months: p.warranty === '' || p.warranty == null ? null : Number(p.warranty),
})

const toLabour = (l) => ({
  labour_type: l.name.trim(),
  hours: Number(l.hours) || 0,
  hourly_rate: Number(l.rate) || 0,
  technician: l.tech?.trim() || null,
})

const fromPart = (p) => ({
  name: p.part_name || '', qty: p.quantity ?? 1, unit: p.unit_cost ?? '',
  supplier: p.supplier || '', warranty: p.warranty_months ?? '',
})

const fromLabour = (l) => ({
  name: l.labour_type || '', hours: l.hours ?? '', rate: l.hourly_rate ?? '', tech: l.technician || '',
})

function ItemTable({ title, columns, rows, setRows, blank, render, total, grid }) {
  const update = (i, patch) => setRows(rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r)))

  return (
    <div>
      <div className="flex items-center gap-2 mb-1.5">
        <span className="text-[11px] font-bold" style={{ color: 'var(--text-muted)' }}>{title}</span>
        <span className="text-[11px] font-bold ml-auto" style={{ color: STOS_ACCENT }}>{fmtMoney(total)}</span>
      </div>

      <div className="grid gap-1" style={{ gridTemplateColumns: grid }}>
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
