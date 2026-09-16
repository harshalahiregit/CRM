import { useState, useRef, useEffect } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { X, Fuel, Camera, AlertTriangle, Check, Trash2 } from 'lucide-react'
import { stosApi, STOS_ACCENT, fmtMoney } from '@/services/stosApi'

/**
 * Record a fill (Feature 3).
 *
 * Minimal on purpose — this gets filled in at a pump, often on a phone. Amount
 * auto-computes from litres × rate but stays editable, because the printed bill
 * is the record and may differ by a rounding paisa.
 *
 * "Customer recoverable" has THREE states, not a checkbox: yes, no, and not yet
 * decided. Defaulting an undecided emergency to "no" quietly writes off money
 * the customer owes, which is exactly the leak this screen exists to stop.
 *
 * Closes only via ✕ or Cancel — never a backdrop click.
 */

const EMPTY = {
  odometer: '', litres: '', rate_per_litre: '', amount: '', station_vendor: '',
  is_emergency: false, emergency_reason: '', customer_recoverable: '',
}

export default function FuelExpenseModal({ open, onClose, vehicle, onSaved }) {
  const qc = useQueryClient()
  const [form, setForm] = useState(EMPTY)
  const [receipt, setReceipt] = useState(null)
  const [err, setErr] = useState('')
  const fileInput = useRef(null)

  useEffect(() => {
    if (open) { setForm(EMPTY); setReceipt(null); setErr('') }
  }, [open])

  const set = (key, value) => setForm((f) => {
    const next = { ...f, [key]: value }

    // Keep the total in step while the user types, without locking it: the
    // bill wins if they overwrite it.
    if (key === 'litres' || key === 'rate_per_litre') {
      const l = Number(key === 'litres' ? value : next.litres)
      const r = Number(key === 'rate_per_litre' ? value : next.rate_per_litre)
      if (l > 0 && r > 0) next.amount = (l * r).toFixed(2)
    }

    return next
  })

  const save = useMutation({
    mutationFn: () => {
      const fd = new FormData()
      fd.append('litres', form.litres)
      fd.append('rate_per_litre', form.rate_per_litre)
      fd.append('amount', form.amount)
      if (form.odometer) fd.append('odometer', form.odometer)
      if (form.station_vendor) fd.append('station_vendor', form.station_vendor)
      fd.append('is_emergency', form.is_emergency ? '1' : '0')
      if (form.is_emergency) {
        fd.append('emergency_reason', form.emergency_reason)
        // Undecided sends nothing at all — the server keeps it null rather than
        // assuming an answer.
        if (form.customer_recoverable !== '') {
          fd.append('customer_recoverable', form.customer_recoverable === 'yes' ? '1' : '0')
        }
      }
      if (receipt) fd.append('receipt', receipt)

      return stosApi.fuel.record(vehicle.id, fd)
    },
    onSuccess: (row) => {
      qc.invalidateQueries({ queryKey: ['stos-vehicle'] })
      qc.invalidateQueries({ queryKey: ['stos-fleet'] })
      onSaved?.(row)
      onClose?.()
    },
    onError: (e) => setErr(e?.message || 'Could not save that fill.'),
  })

  if (!open) return null

  const submit = (e) => {
    e.preventDefault()
    setErr('')

    if (!form.litres || !form.rate_per_litre || !form.amount) {
      return setErr('Litres, rate and amount are all needed.')
    }
    if (form.is_emergency && form.emergency_reason.trim().length < 5) {
      return setErr('An emergency fill needs a reason — somebody has to answer for it.')
    }

    save.mutate()
  }

  return (
    <div className="fixed inset-0 z-[60] flex items-start justify-center p-4 pt-[8vh] bg-black/50">
      <form
        onSubmit={submit}
        className="w-full max-w-lg rounded-2xl overflow-hidden flex flex-col"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '84vh' }}
        onKeyDown={(e) => { if (e.key === 'Escape') onClose?.() }}
      >
        <div className="flex items-start justify-between gap-3 px-5 pt-4 pb-3" style={{ borderBottom: '1px solid var(--border)' }}>
          <div className="flex items-center gap-2">
            <span className="w-8 h-8 rounded-xl flex items-center justify-center"
              style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 12%, transparent)` }}>
              <Fuel size={15} style={{ color: STOS_ACCENT }} />
            </span>
            <div>
              <h2 className="font-bold" style={{ color: 'var(--text-h)', fontSize: 15 }}>Record a fill</h2>
              <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{vehicle?.registration_number}</p>
            </div>
          </div>
          <button type="button" onClick={onClose} aria-label="Close" className="p-1.5 rounded-lg shrink-0" style={{ color: 'var(--text-muted)' }}>
            <X size={16} />
          </button>
        </div>

        <div className="px-5 py-4 overflow-y-auto flex-1 space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <Field label="Odometer (km)" hint="Must be higher than the last fill">
              <input type="number" step="0.1" inputMode="decimal" value={form.odometer}
                onChange={(e) => set('odometer', e.target.value)} style={inputStyle} className={inputClass} />
            </Field>
            <Field label="Fuel station / vendor">
              <input value={form.station_vendor} onChange={(e) => set('station_vendor', e.target.value)}
                style={inputStyle} className={inputClass} />
            </Field>
            <Field label="Litres *">
              <input type="number" step="0.001" inputMode="decimal" value={form.litres}
                onChange={(e) => set('litres', e.target.value)} style={inputStyle} className={inputClass} />
            </Field>
            <Field label="Price per litre *">
              <input type="number" step="0.01" inputMode="decimal" value={form.rate_per_litre}
                onChange={(e) => set('rate_per_litre', e.target.value)} style={inputStyle} className={inputClass} />
            </Field>
          </div>

          <Field label="Total amount *" hint="Auto-calculated — edit it if the bill says otherwise">
            <input type="number" step="0.01" inputMode="decimal" value={form.amount}
              onChange={(e) => set('amount', e.target.value)} style={inputStyle} className={inputClass} />
            {form.amount > 0 && (
              <p className="text-[11px] mt-1 font-semibold" style={{ color: STOS_ACCENT }}>{fmtMoney(form.amount)}</p>
            )}
          </Field>

          {/* ── Emergency: conditional fields, hidden until relevant ── */}
          <label className="flex items-center gap-2 rounded-xl px-3 py-2.5 cursor-pointer"
            style={{
              background: form.is_emergency ? 'color-mix(in srgb, var(--color-warning-500, #f59e0b) 12%, transparent)' : 'var(--bg-input)',
              border: `1px solid ${form.is_emergency ? 'var(--color-warning-500, #f59e0b)' : 'var(--border)'}`,
            }}>
            <input type="checkbox" checked={form.is_emergency}
              onChange={(e) => set('is_emergency', e.target.checked)} />
            <AlertTriangle size={13} style={{ color: 'var(--color-warning-500, #f59e0b)' }} />
            <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>Emergency purchase</span>
          </label>

          {form.is_emergency && (
            <div className="space-y-3 pl-1">
              <Field label="Reason *">
                <input value={form.emergency_reason} onChange={(e) => set('emergency_reason', e.target.value)}
                  placeholder="Ran dry on NH-48, no card accepted" style={inputStyle} className={inputClass} />
              </Field>

              <Field label="Customer recoverable?" hint="Leave undecided if nobody has ruled on it yet">
                <div className="flex gap-1.5">
                  {[['yes', 'Yes — bill it'], ['no', 'No — we carry it'], ['', 'Not decided']].map(([value, label]) => (
                    <button key={label} type="button" onClick={() => set('customer_recoverable', value)}
                      className="text-[11px] font-semibold px-2.5 py-1.5 rounded-xl"
                      style={{
                        background: form.customer_recoverable === value ? `color-mix(in srgb, ${STOS_ACCENT} 16%, transparent)` : 'var(--bg-input)',
                        border: `1px solid ${form.customer_recoverable === value ? STOS_ACCENT : 'var(--border)'}`,
                        color: form.customer_recoverable === value ? STOS_ACCENT : 'var(--text-muted)',
                      }}>
                      {label}
                    </button>
                  ))}
                </div>
              </Field>
            </div>
          )}

          {/* ── Receipt ─────────────────────────────────────────── */}
          <Field label="Receipt photo">
            <input ref={fileInput} type="file" accept="image/*,application/pdf" capture="environment" hidden
              onChange={(e) => setReceipt(e.target.files?.[0] || null)} />

            {receipt ? (
              <div className="flex items-center gap-2 rounded-xl px-3 py-2" style={{ background: 'var(--bg-input)' }}>
                <Camera size={13} style={{ color: STOS_ACCENT }} />
                <span className="text-[11px] flex-1 truncate" style={{ color: 'var(--text-h)' }}>{receipt.name}</span>
                <button type="button" onClick={() => setReceipt(null)} aria-label="Remove receipt"
                  style={{ color: 'var(--color-danger-500)' }}>
                  <Trash2 size={13} />
                </button>
              </div>
            ) : (
              <button type="button" onClick={() => fileInput.current?.click()}
                className="flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl w-full justify-center"
                style={{ background: 'var(--bg-input)', border: '1px dashed var(--border)', color: 'var(--text-muted)' }}>
                <Camera size={13} /> Take or attach the receipt
              </button>
            )}
          </Field>

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
            style={{ background: STOS_ACCENT, color: '#fff' }}>
            <Check size={13} /> {save.isPending ? 'Saving…' : 'Save fill'}
          </button>
        </div>
      </form>
    </div>
  )
}

const inputClass = 'w-full text-xs rounded-xl px-3 py-2'
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
