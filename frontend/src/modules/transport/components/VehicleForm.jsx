import { Hash, Truck, Building2, Gauge } from 'lucide-react'
import { VEHICLE_OWNERSHIP } from '../constants'
import { Section, Field, inputStyle, selectStyle, readonlyStyle } from './MasterFormFields'

/**
 * Vehicle create/edit — SNG-TRN-003.
 *
 * Every field traces to STOS-FLEET §6 (recommended vehicle master fields), §9
 * (identifiers) or §10 (ownership). Nothing else is here: current driver,
 * current trip, current location and compliance status are all DERIVED and are
 * shown on the detail page rather than typed.
 *
 * `status` is absent by design. FLEET §8: "Vehicle status must be driven by
 * business events. Users should not freely type 'Available'." It moves through
 * the transition control on the detail page.
 */

export const emptyVehicle = () => ({
  registration_number: '', fleet_number: '', chassis_number: '', engine_number: '',
  gps_device_id: '', vehicle_type: '', manufacturer: '', model: '', variant: '',
  manufacturing_year: '', purchase_date: '', fuel_type: '', branch: '',
  capacity_tonnes: '', ownership_type: 'owned',
})

export function validateVehicle(v) {
  if (!v.registration_number?.trim()) return 'A registration number is required.'
  if (v.manufacturing_year && (v.manufacturing_year < 1950 || v.manufacturing_year > new Date().getFullYear() + 1)) {
    return 'That manufacturing year does not look right.'
  }
  if (v.capacity_tonnes !== '' && Number(v.capacity_tonnes) < 0) return 'Capacity cannot be negative.'
  return null
}

export default function VehicleForm({ value, onChange, mode = 'create' }) {
  const v = value || {}
  const set = (f) => (e) => onChange({ ...v, [f]: e.target.value })

  return (
    <div style={{ display: 'grid', gap: 18 }}>
      {/* 1 — Identity. FLEET §9: unique, searchable, normalized. */}
      <Section icon={Hash} title="Identity">
        <Field label="Registration number" required
          hint="Stored as typed and matched normalized — “MH 12 AB 4455” and “mh-12-ab-4455” are the same vehicle.">
          <input value={v.registration_number || ''} onChange={set('registration_number')} placeholder="MH 12 AB 4455" style={inputStyle} />
        </Field>
        <Field label="Fleet number">
          <input value={v.fleet_number || ''} onChange={set('fleet_number')} placeholder="Internal label" style={inputStyle} />
        </Field>
        <Field label="Chassis number">
          <input value={v.chassis_number || ''} onChange={set('chassis_number')} style={inputStyle} />
        </Field>
        <Field label="Engine number">
          <input value={v.engine_number || ''} onChange={set('engine_number')} style={inputStyle} />
        </Field>
        <Field label="GPS device ID" hint="Recorded now; telemetry arrives with SNG-TRN-020.">
          <input value={v.gps_device_id || ''} onChange={set('gps_device_id')} style={inputStyle} />
        </Field>
        {mode === 'edit' && (
          <Field label="Normalized (derived)">
            <input value={v.registration_normalized || ''} readOnly tabIndex={-1} style={readonlyStyle} />
          </Field>
        )}
      </Section>

      {/* 2 — Specification. FLEET §6. */}
      <Section icon={Truck} title="Specification">
        <Field label="Vehicle type"><input value={v.vehicle_type || ''} onChange={set('vehicle_type')} placeholder="Trailer 40ft" style={inputStyle} /></Field>
        <Field label="Manufacturer"><input value={v.manufacturer || ''} onChange={set('manufacturer')} placeholder="Tata" style={inputStyle} /></Field>
        <Field label="Model"><input value={v.model || ''} onChange={set('model')} style={inputStyle} /></Field>
        <Field label="Variant"><input value={v.variant || ''} onChange={set('variant')} style={inputStyle} /></Field>
        <Field label="Manufacturing year">
          <input type="number" value={v.manufacturing_year || ''} onChange={set('manufacturing_year')} placeholder="2021" style={inputStyle} />
        </Field>
        <Field label="Fuel type"><input value={v.fuel_type || ''} onChange={set('fuel_type')} placeholder="Diesel" style={inputStyle} /></Field>
      </Section>

      {/* 3 — Capacity and ownership. Capacity is one half of PLN-001. */}
      <Section icon={Gauge} title="Capacity & ownership">
        <Field label="Capacity (tonnes)" hint="Compared against the capacity an order requires when allocating.">
          <input type="number" step="0.001" value={v.capacity_tonnes ?? ''} onChange={set('capacity_tonnes')} placeholder="25" style={inputStyle} />
        </Field>
        <Field label="Ownership type" hint="Drives asset cost, EMI, profitability and utilisation.">
          <select value={v.ownership_type || 'owned'} onChange={set('ownership_type')} style={selectStyle}>
            {VEHICLE_OWNERSHIP.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
        </Field>
        <Field label="Purchase date">
          <input type="date" value={v.purchase_date ? String(v.purchase_date).slice(0, 10) : ''} onChange={set('purchase_date')} style={inputStyle} />
        </Field>
        <Field label="Branch">
          <input value={v.branch || ''} onChange={set('branch')} style={inputStyle} />
        </Field>
      </Section>
    </div>
  )
}
