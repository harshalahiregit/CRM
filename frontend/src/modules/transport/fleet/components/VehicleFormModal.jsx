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

// T-03 — the six values of STOS-FLEET §10, UPPERCASE to match the stored enum.
// Financed and Contracted were missing; market is gone (it folded into Other).
const OWNERSHIPS = [
  { value: 'OWNED',      label: 'Owned' },
  { value: 'FINANCED',   label: 'Financed' },
  { value: 'LEASED',     label: 'Leased' },
  { value: 'CONTRACTED', label: 'Contracted' },
  { value: 'ATTACHED',   label: 'Attached' },
  { value: 'OTHER',      label: 'Other' },
]

const EMPTY = {
  registration_number: '', vehicle_type: 'truck', ownership_type: 'OWNED',
  chassis_number: '', engine_number: '', gps_device_id: '',
  fleet_number: '', manufacturer: '', model: '', variant: '',
  manufacturing_year: '', purchase_date: '', fuel_type: '', branch: '',
  capacity_tonnes: '', benchmark_kmpl: '', genset_serial: '',
  service_interval_km: '', service_interval_days: '', last_service_odometer: '', last_service_on: '',
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
          ownership_type: vehicle.ownership_type || 'OWNED',
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
          benchmark_kmpl: vehicle.benchmark_kmpl ?? '',
          service_interval_km: vehicle.service_interval_km ?? '',
          service_interval_days: vehicle.service_interval_days ?? '',
          last_service_odometer: vehicle.last_service_odometer ?? '',
          last_service_on: vehicle.last_service_on?.slice(0, 10) || '',
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
      ;['manufacturing_year', 'capacity_tonnes', 'benchmark_kmpl', 'service_interval_km', 'service_interval_days', 'last_service_odometer']
        .forEach((k) => { payload[k] = form[k] === '' || form[k] == null ? null : Number(form[k]) })
      payload.purchase_date = form.purchase_date || null
      payload.last_service_on = form.last_service_on || null
      // An empty date is "not recorded", not an empty string the API must parse.
      EXPIRY_DOCUMENTS.forEach(({ field }) => { payload[field] = form[field] || null })
      // Not a column on `vehicles` — a genset is its own asset. Stripped from
      // the vehicle payload and registered separately once the vehicle exists.
      const gensetSerial = String(payload.genset_serial || '').trim()
      delete payload.genset_serial

      if (editing) return stosApi.fleet.update(vehicle.id, payload)

      return stosApi.fleet.create(payload).then(async (created) => {
        if (!gensetSerial) return created

        // Deliberately not fatal: the vehicle is saved either way, and losing
        // the truck because a serial was a duplicate would be the wrong trade.
        try {
          await stosApi.gensets.create({ serial_number: gensetSerial, vehicle_id: created.id, status: 'active' })
        } catch (e) {
          created.genset_warning = e?.message || 'The vehicle was saved, but its genset could not be registered.'
        }

        return created
      })
    },
    onSuccess: (row) => {
      qc.invalidateQueries({ queryKey: ['stos-fleet'] })
      qc.invalidateQueries({ queryKey: ['stos-vehicle'] })
      qc.invalidateQueries({ queryKey: ['stos-eligible'] })
      qc.invalidateQueries({ queryKey: ['stos-gensets'] })

      // The vehicle saved but its genset did not. Held open and said out loud
      // rather than closing on a half-success — otherwise a reefer quietly ends
      // up with no power unit on record and nobody knows why.
      if (row?.genset_warning) {
        setErr(`${row.genset_warning} The vehicle is saved — register its genset from the passport.`)
        onSaved?.(row)

        return
      }

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

          <div className="grid grid-cols-2 gap-3">
            <Field label="Payload (tonnes)" error={fieldErrors.capacity_tonnes}
              hint="Matched against an order's required capacity">
              <input type="number" step="0.01" inputMode="decimal" value={form.capacity_tonnes}
                onChange={(e) => set('capacity_tonnes', e.target.value)}
                placeholder="25.00" className={inputClass} style={inputStyle} />
            </Field>
            {/* T-19 — blank means "nobody has measured this truck", which is a
                different fact from a bad figure and has to stay tellable apart. */}
            <Field label="Benchmark (km/l)" error={fieldErrors.benchmark_kmpl}
              hint="Leave blank to use the type default">
              <input type="number" step="0.01" inputMode="decimal" value={form.benchmark_kmpl}
                onChange={(e) => set('benchmark_kmpl', e.target.value)}
                placeholder="type default" className={inputClass} style={inputStyle} />
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

          {/* T-04 — the service schedule. Either clock, neither or both: trucks
              are serviced on distance, trailers often on time. Blank means no
              schedule, and the passport reports that as "unknown" rather than
              pretending the truck is freshly serviced. */}
          <div className="grid grid-cols-2 gap-3">
            <Field label="Service every (km)" error={fieldErrors.service_interval_km}>
              <input type="number" step="100" inputMode="numeric" value={form.service_interval_km}
                onChange={(e) => set('service_interval_km', e.target.value)}
                placeholder="10000" className={inputClass} style={inputStyle} />
            </Field>
            <Field label="or every (days)" error={fieldErrors.service_interval_days}>
              <input type="number" step="1" inputMode="numeric" value={form.service_interval_days}
                onChange={(e) => set('service_interval_days', e.target.value)}
                placeholder="180" className={inputClass} style={inputStyle} />
            </Field>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Odometer at last service" error={fieldErrors.last_service_odometer}
              hint="Without this there is nothing to measure the interval from">
              <input type="number" step="0.1" inputMode="decimal" value={form.last_service_odometer}
                onChange={(e) => set('last_service_odometer', e.target.value)}
                placeholder="100000" className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Last serviced on" error={fieldErrors.last_service_on}>
              <input type="date" value={form.last_service_on}
                onChange={(e) => set('last_service_on', e.target.value)}
                className={inputClass} style={inputStyle} />
            </Field>
          </div>

          <Field label="GPS device id" error={fieldErrors.gps_device_id}
            hint="How telemetry finds this vehicle. One device reports for one truck.">
            <input value={form.gps_device_id} onChange={(e) => set('gps_device_id', e.target.value)}
              placeholder="DEV-0001" className={inputClass} style={inputStyle} />
          </Field>

          {/* T-05 — this used to be a sentence telling people to fit the genset
              from the passport later. The field is here now, because the moment
              somebody is registering a reefer is the moment they have the
              serial in front of them. Still optional: a unit can be bolted on
              afterwards, and the passport does that. */}
          {form.vehicle_type === 'reefer' && !editing && (
            <>
              <Field label="Genset serial" error={fieldErrors.genset_serial}
                hint="The power unit fitted to this reefer. Leave blank and fit one from the passport later.">
                <input value={form.genset_serial} onChange={(e) => set('genset_serial', e.target.value)}
                  placeholder="GS-0014" className={inputClass} style={inputStyle} />
              </Field>

              <p className="flex items-start gap-1.5 text-[11px] -mt-1" style={{ color: 'var(--text-muted)' }}>
                <Info size={12} className="shrink-0 mt-0.5" />
                A reefer reports body temperature and genset state, and raises an excursion when the genset
                is off above the set point.
              </p>
            </>
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
