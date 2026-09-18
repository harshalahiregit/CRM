import { useState, useEffect } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { X, Truck, Check, Info } from 'lucide-react'
import { stosApi, STOS_ACCENT, VEHICLE_TYPE_OPTIONS, EXPIRY_DOCUMENTS, FUEL_TYPES } from '@/services/stosApi'
import Select from '@/components/ui/Select'

/**
 * Step 1 — a truck joins the fleet.
 *
 * Static, physical facts only. There is no "status" field on purpose: a vehicle
 * goes into and out of the workshop through its job cards, and a dropdown that
 * could set it back to active would let someone put a truck on the road with
 * its brakes still in pieces. The server strips the field even if it is sent.
 *
 * Closes only via ✕ or Cancel — never a backdrop click.
 */

const OWNERSHIPS = [
  { value: 'owned',    label: 'Owned' },
  { value: 'leased',   label: 'Leased' },
  { value: 'attached', label: 'Attached' },
  { value: 'market',   label: 'Market hire' },
]

const EMPTY = {
  registration_number: '', vehicle_type: 'truck', ownership_type: 'owned',
  chassis_number: '', engine_number: '', gps_device_id: '',
  fleet_number: '', manufacturer: '', model: '', variant: '',
  manufacturing_year: '', purchase_date: '', fuel_type: '', branch: '',
  capacity_tonnes: '',
  registration_expiry: '', insurance_expiry: '', fitness_expiry: '', permit_expiry: '', puc_expiry: '',
  compliance_hold: false, compliance_hold_reason: '',
}

export default function VehicleFormModal({ open, onClose, vehicle = null, onSaved }) {
  const qc = useQueryClient()
  const editing = Boolean(vehicle)
  const [form, setForm] = useState(EMPTY)
  const [err, setErr] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})

  useEffect(() => {
    if (!open) return
    setErr(''); setFieldErrors({})
    setForm(editing
      ? {
          registration_number: vehicle.registration_number || '',
          vehicle_type: vehicle.vehicle_type || 'truck',
          ownership_type: vehicle.ownership_type || 'owned',
          chassis_number: vehicle.chassis_number || '',
          engine_number: vehicle.engine_number || '',
          gps_device_id: vehicle.gps_device_id || '',
          fleet_number: vehicle.fleet_number || '',
          manufacturer: vehicle.manufacturer || '',
          model: vehicle.model || '',
          variant: vehicle.variant || '',
          manufacturing_year: vehicle.manufacturing_year ?? '',
          purchase_date: vehicle.purchase_date?.slice(0, 10) || '',
          fuel_type: vehicle.fuel_type || '',
          branch: vehicle.branch || '',
          capacity_tonnes: vehicle.capacity_tonnes ?? '',
          registration_expiry: vehicle.registration_expiry?.slice(0, 10) || '',
          insurance_expiry: vehicle.insurance_expiry?.slice(0, 10) || '',
          fitness_expiry: vehicle.fitness_expiry?.slice(0, 10) || '',
          permit_expiry: vehicle.permit_expiry?.slice(0, 10) || '',
          puc_expiry: vehicle.puc_expiry?.slice(0, 10) || '',
          compliance_hold: Boolean(vehicle.compliance_hold),
          compliance_hold_reason: vehicle.compliance_hold_reason || '',
        }
      : EMPTY)
  }, [open, vehicle, editing])

  const set = (key, value) => {
    setForm((f) => ({ ...f, [key]: value }))
    setFieldErrors((e) => ({ ...e, [key]: undefined }))
  }

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        ...form,
        chassis_number: form.chassis_number.trim() || null,
        engine_number: form.engine_number.trim() || null,
        gps_device_id: form.gps_device_id.trim() || null,
        compliance_hold_reason: form.compliance_hold ? form.compliance_hold_reason : null,
      }

      // Blank is "not recorded", never an empty string the API has to coerce.
      // A blank number sent as '' becomes 0, and a 0-tonne truck is one the
      // eligibility engine will never match to an order.
      ;['fleet_number', 'manufacturer', 'model', 'variant', 'fuel_type', 'branch']
        .forEach((k) => { payload[k] = form[k]?.trim() || null })
      ;['manufacturing_year', 'capacity_tonnes']
        .forEach((k) => { payload[k] = form[k] === '' || form[k] == null ? null : Number(form[k]) })
      payload.purchase_date = form.purchase_date || null
      // An empty date is "not recorded", not an empty string the API must parse.
      EXPIRY_DOCUMENTS.forEach(({ field }) => { payload[field] = form[field] || null })
      return editing
        ? stosApi.fleet.update(vehicle.id, payload)
        : stosApi.fleet.create(payload)
    },
    onSuccess: (row) => {
      qc.invalidateQueries({ queryKey: ['stos-fleet'] })
      qc.invalidateQueries({ queryKey: ['stos-vehicle'] })
      qc.invalidateQueries({ queryKey: ['stos-eligible'] })
      onSaved?.(row)
      onClose?.()
    },
    onError: (e) => {
      setErr(e?.message || 'Could not save that vehicle.')
      setFieldErrors(e?.fieldErrors || {})
    },
  })

  if (!open) return null

  const submit = (e) => {
    e.preventDefault()
    setErr('')

    if (form.registration_number.replace(/[^A-Za-z0-9]/g, '').length < 4) {
      return setErr('A number plate is how everyone refers to this vehicle — it is required.')
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
              <Truck size={15} style={{ color: STOS_ACCENT }} />
            </span>
            <div>
              <h2 className="font-bold" style={{ color: 'var(--text-h)', fontSize: 15 }}>
                {editing ? 'Edit vehicle' : 'Add a vehicle'}
              </h2>
              <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                {editing ? vehicle.registration_number : 'The static facts. Everything else attaches to this record.'}
              </p>
            </div>
          </div>
          <button type="button" onClick={onClose} aria-label="Close" className="p-1.5 rounded-lg shrink-0" style={{ color: 'var(--text-muted)' }}>
            <X size={16} />
          </button>
        </div>

        <div className="px-5 py-4 overflow-y-auto flex-1 space-y-3">
          <Field label="Registration number *" error={fieldErrors.registration_number}
            hint="Spaces and dashes are ignored — MH 12 AB 1234 and MH12AB1234 are the same truck">
            <input value={form.registration_number} onChange={(e) => set('registration_number', e.target.value)}
              placeholder="MH12AB1234" autoFocus className={inputClass} style={inputStyle} />
          </Field>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Vehicle type *">
              <Select size="sm" value={form.vehicle_type} onChange={(v) => set('vehicle_type', v)}
                options={VEHICLE_TYPE_OPTIONS} ariaLabel="Vehicle type" />
            </Field>
            <Field label="Ownership *">
              <Select size="sm" value={form.ownership_type} onChange={(v) => set('ownership_type', v)}
                options={OWNERSHIPS} ariaLabel="Ownership type" />
            </Field>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Chassis number" error={fieldErrors.chassis_number}>
              <input value={form.chassis_number} onChange={(e) => set('chassis_number', e.target.value)}
                className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Engine number">
              <input value={form.engine_number} onChange={(e) => set('engine_number', e.target.value)}
                className={inputClass} style={inputStyle} />
            </Field>
          </div>

          {/* T-01 — identity and payload. These columns arrived with the D-62
              union and nothing could set them, so every vehicle onboarded here
              came out blank. `capacity_tonnes` is the one that reaches beyond
              this screen: Operations matches it against an order's required
              payload, so a blank one is invisible to allocation. */}
          <div className="grid grid-cols-3 gap-3">
            <Field label="Make">
              <input value={form.manufacturer} onChange={(e) => set('manufacturer', e.target.value)}
                placeholder="Tata" className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Model">
              <input value={form.model} onChange={(e) => set('model', e.target.value)}
                placeholder="Signa 4825" className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Variant">
              <input value={form.variant} onChange={(e) => set('variant', e.target.value)}
                className={inputClass} style={inputStyle} />
            </Field>
          </div>

          <div className="grid grid-cols-3 gap-3">
            <Field label="Payload (tonnes)" error={fieldErrors.capacity_tonnes}
              hint="Matched against an order's required capacity">
              <input type="number" step="0.01" inputMode="decimal" value={form.capacity_tonnes}
                onChange={(e) => set('capacity_tonnes', e.target.value)}
                placeholder="25.00" className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Fuel" error={fieldErrors.fuel_type}>
              <Select size="sm" value={form.fuel_type} onChange={(v) => set('fuel_type', v)}
                options={FUEL_TYPES} ariaLabel="Fuel type" />
            </Field>
            <Field label="Year" error={fieldErrors.manufacturing_year}>
              <input type="number" step="1" inputMode="numeric" value={form.manufacturing_year}
                onChange={(e) => set('manufacturing_year', e.target.value)}
                placeholder="2021" className={inputClass} style={inputStyle} />
            </Field>
          </div>

          <div className="grid grid-cols-3 gap-3">
            <Field label="Fleet number" hint="Your own internal number">
              <input value={form.fleet_number} onChange={(e) => set('fleet_number', e.target.value)}
                placeholder="TRK-014" className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Branch">
              <input value={form.branch} onChange={(e) => set('branch', e.target.value)}
                placeholder="Bhiwandi" className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Purchased" error={fieldErrors.purchase_date}>
              <input type="date" value={form.purchase_date}
                onChange={(e) => set('purchase_date', e.target.value)}
                className={inputClass} style={inputStyle} />
            </Field>
          </div>

          <Field label="GPS device id" error={fieldErrors.gps_device_id}
            hint="How telemetry finds this vehicle. One device reports for one truck.">
            <input value={form.gps_device_id} onChange={(e) => set('gps_device_id', e.target.value)}
              placeholder="DEV-0001" className={inputClass} style={inputStyle} />
          </Field>

          {form.vehicle_type === 'reefer' && (
            <p className="flex items-start gap-1.5 text-[11px]" style={{ color: 'var(--text-muted)' }}>
              <Info size={12} className="shrink-0 mt-0.5" />
              A reefer reports body temperature and genset state, and raises an excursion when the genset is off
              above the set point. Fit its genset from the vehicle&apos;s passport once it is saved.
            </p>
          )}

          {/* The five statutory papers. The VERDICT is derived from these dates
              by the server and is deliberately not typeable — see the note. */}
          <div>
            <label className="text-[11px] font-bold block mb-1" style={{ color: 'var(--text-muted)' }}>
              Document expiry dates
            </label>
            <div className="grid grid-cols-2 gap-2">
              {EXPIRY_DOCUMENTS.map(({ field, label }) => (
                <div key={field}>
                  <label htmlFor={`stos-${field}`} className="text-[10px] block mb-0.5" style={{ color: 'var(--text-muted)' }}>
                    {label}
                  </label>
                  <input id={`stos-${field}`} type="date" value={form[field]}
                    onChange={(e) => set(field, e.target.value)} className={inputClass} style={inputStyle} />
                </div>
              ))}
            </div>
            <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>
              Compliance status is calculated from these — a lapsed document blocks dispatch automatically, and is
              rechecked nightly. A blank date reads as &ldquo;not recorded&rdquo;, which does not block on its own.
            </p>
          </div>

          <label className="flex items-center gap-2 rounded-xl px-3 py-2.5 cursor-pointer"
            style={{
              background: form.compliance_hold ? 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)' : 'var(--bg-input)',
              border: `1px solid ${form.compliance_hold ? 'var(--color-danger-500)' : 'var(--border)'}`,
            }}>
            <input type="checkbox" checked={form.compliance_hold}
              onChange={(e) => set('compliance_hold', e.target.checked)} />
            <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>Compliance hold</span>
          </label>

          {form.compliance_hold && (
            <Field label="Reason for the hold *" hint="Overrides every date above until a person lifts it">
              <input value={form.compliance_hold_reason} onChange={(e) => set('compliance_hold_reason', e.target.value)}
                placeholder="Under accident investigation" className={inputClass} style={inputStyle} />
            </Field>
          )}

          {/* Say why a field they might expect is missing, rather than leaving
              them hunting for it. */}
          <p className="flex items-start gap-1.5 text-[11px]" style={{ color: 'var(--text-muted)' }}>
            <Info size={12} className="shrink-0 mt-0.5" />
            Operational status is not set here — a vehicle comes off the road when a job card is opened and goes
            back on when it is closed and released.
          </p>

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
            <Check size={13} /> {save.isPending ? 'Saving…' : (editing ? 'Save changes' : 'Add to fleet')}
          </button>
        </div>
      </form>
    </div>
  )
}

const inputClass = 'w-full text-xs rounded-xl px-3 py-2'
const inputStyle = { background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text)' }

function Field({ label, hint, error, children }) {
  return (
    <div>
      <label className="text-[11px] font-bold block mb-1" style={{ color: 'var(--text-muted)' }}>{label}</label>
      {children}
      {error && <p className="text-[10px] mt-1 font-semibold" style={{ color: 'var(--color-danger-500)' }}>{error}</p>}
      {hint && !error && <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>{hint}</p>}
    </div>
  )
}
