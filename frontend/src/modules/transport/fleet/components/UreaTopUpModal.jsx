import { useState, useEffect } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { X, Droplets, Check, AlertTriangle } from 'lucide-react'
import { stosApi, STOS_ACCENT, fmtMoney } from '@/services/stosApi'

/**
 * Record an AdBlue / urea top-up (T-23).
 *
 * The endpoint has existed since M2 and nothing could reach it, so every top-up
 * had to be entered by somebody with a database client — which in practice
 * means they were not entered at all, and the consumption figures the allocation
 * ranking reads were built on a partial record.
 *
 * Urea is deliberately its own entry rather than a checkbox on the fuel modal:
 * it is a separate tank at a separate price, and mixing it into diesel corrupts
 * every L/KM figure downstream.
 *
 * Closes only via ✕ or Cancel — never a backdrop click.
 */
export default function UreaTopUpModal({ open, onClose, vehicle, band, onSaved }) {
  const qc = useQueryClient()

  const [litres, setLitres] = useState('')
  const [rate, setRate] = useState('')
  const [amount, setAmount] = useState('')
  const [odometer, setOdometer] = useState('')
  const [vendor, setVendor] = useState('')
  const [err, setErr] = useState('')

  useEffect(() => {
    if (!open) return
    setErr('')
    setLitres(''); setRate(''); setAmount(''); setOdometer(''); setVendor('')
  }, [open])

  // Typing a rate fills the amount, because that is the order the pump receipt
  // is read in. The amount stays editable: the receipt is what was paid, and a
  // rounded settlement is not a mistake to be corrected by arithmetic.
  useEffect(() => {
    const l = Number(litres) || 0
    const r = Number(rate) || 0
    if (l > 0 && r > 0) setAmount((l * r).toFixed(2))
  }, [litres, rate])

  const save = useMutation({
    mutationFn: () => stosApi.urea.record(vehicle.id, {
      litres: Number(litres),
      rate_per_litre: rate === '' ? null : Number(rate),
      amount: Number(amount),
      odometer: odometer === '' ? null : Number(odometer),
      station_vendor: vendor.trim() || null,
    }),
    onSuccess: (row) => {
      qc.invalidateQueries({ queryKey: ['stos-vehicle'] })
      qc.invalidateQueries({ queryKey: ['stos-fleet'] })
      onSaved?.(row)
      onClose?.()
    },
    onError: (e) => setErr(e?.message || 'Could not record that top-up.'),
  })

  if (!open) return null

  const submit = (e) => {
    e.preventDefault()
    setErr('')

    if (!(Number(litres) > 0)) return setErr('How many litres went in?')
    if (!(Number(amount) > 0)) return setErr('What did it cost?')

    save.mutate()
  }

  return (
    <div className="fixed inset-0 z-[60] flex items-start justify-center p-4 pt-[10vh] bg-black/50">
      <form
        onSubmit={submit}
        className="w-full max-w-md rounded-2xl overflow-hidden flex flex-col"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '80vh' }}
        onKeyDown={(e) => { if (e.key === 'Escape') onClose?.() }}
      >
        <div className="flex items-start justify-between gap-3 px-5 pt-4 pb-3" style={{ borderBottom: '1px solid var(--border)' }}>
          <div className="flex items-center gap-2">
            <span className="w-8 h-8 rounded-xl flex items-center justify-center"
              style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 12%, transparent)` }}>
              <Droplets size={15} style={{ color: STOS_ACCENT }} />
            </span>
            <div>
              <h2 className="font-bold" style={{ color: 'var(--text-h)', fontSize: 15 }}>Record a urea top-up</h2>
              <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                {vehicle?.registration_number} · AdBlue is costed separately from diesel
              </p>
            </div>
          </div>
          <button type="button" onClick={onClose} aria-label="Close" className="p-1.5 rounded-lg shrink-0" style={{ color: 'var(--text-muted)' }}>
            <X size={16} />
          </button>
        </div>

        <div className="px-5 py-4 overflow-y-auto flex-1 space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <Field label="Litres *">
              <input type="number" step="0.001" inputMode="decimal" value={litres}
                onChange={(e) => setLitres(e.target.value)} placeholder="20" className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Rate / litre">
              <input type="number" step="0.01" inputMode="decimal" value={rate}
                onChange={(e) => setRate(e.target.value)} placeholder="90.00" className={inputClass} style={inputStyle} />
            </Field>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Amount *" hint="The receipt total, not the arithmetic">
              <input type="number" step="0.01" inputMode="decimal" value={amount}
                onChange={(e) => setAmount(e.target.value)} placeholder="1800.00" className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Odometer" hint="Needed to measure consumption">
              <input type="number" step="0.1" inputMode="decimal" value={odometer}
                onChange={(e) => setOdometer(e.target.value)} placeholder="184320" className={inputClass} style={inputStyle} />
            </Field>
          </div>

          <Field label="Supplier">
            <input value={vendor} onChange={(e) => setVendor(e.target.value)}
              placeholder="IOC Thane" className={inputClass} style={inputStyle} />
          </Field>

          {!odometer && (
            <p className="text-[10px] flex items-start gap-1.5" style={{ color: 'var(--text-muted)' }}>
              <AlertTriangle size={11} className="mt-0.5 shrink-0" />
              Without an odometer reading this top-up is recorded but not measured — it will not
              produce a L/100km figure or count toward the consumption check.
            </p>
          )}

          {band && (
            <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
              Expected consumption is {band.min}–{band.max} {band.unit}. Anything outside that is
              flagged on the passport for somebody to look at.
            </p>
          )}

          <div className="flex items-center justify-between rounded-xl px-3 py-2.5"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>Cost</span>
            <span className="text-sm font-black" style={{ color: STOS_ACCENT }}>{fmtMoney(Number(amount) || 0)}</span>
          </div>

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
            <Check size={13} />
            {save.isPending ? 'Saving…' : 'Record top-up'}
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
