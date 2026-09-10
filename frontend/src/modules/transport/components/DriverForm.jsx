import { User, Phone, IdCard, Link2 } from 'lucide-react'
import { Section, Field, inputStyle, readonlyStyle } from './MasterFormFields'

/**
 * Driver create/edit — SNG-TRN-004.
 *
 * Fields trace to STOS-DB §42/§43/§152, CMP §22 (licence, class, expiry),
 * CTD §21 (name, code, contact), INT §76 (one profile per person) and Step 2
 * BO-009 (a driver belongs to the company OR a supplier).
 *
 * Neither `status` nor `availability` is here: both are business events and move
 * through the controls on the detail page. Compliance status is derived and
 * never typed.
 */

export const emptyDriver = () => ({
  name: '', driver_code: '', mobile: '', alternate_mobile: '',
  hr_employee_id: '', supplier_id: '',
  licence_number: '', licence_class: '', licence_valid_from: '', licence_valid_until: '',
})

export function validateDriver(d) {
  if (!d.name?.trim()) return 'A driver name is required.'
  if (d.licence_valid_from && d.licence_valid_until && d.licence_valid_until < d.licence_valid_from) {
    return 'The licence cannot expire before it becomes valid.'
  }
  return null
}

export default function DriverForm({ value, onChange, mode = 'create' }) {
  const d = value || {}
  const set = (f) => (e) => onChange({ ...d, [f]: e.target.value })

  return (
    <div style={{ display: 'grid', gap: 18 }}>
      {/* 1 — Identity. INT §76: one unique internal identity per person. */}
      <Section icon={User} title="Identity">
        <Field label="Full name" required>
          <input value={d.name || ''} onChange={set('name')} placeholder="Ramesh Kumar" style={inputStyle} />
        </Field>
        <Field label="Driver code" hint="Your internal reference. Unique within the workspace.">
          <input value={d.driver_code || ''} onChange={set('driver_code')} placeholder="DRV-001" style={inputStyle} />
        </Field>
      </Section>

      {/* 2 — Contact. CTD §21 "contact where authorized". */}
      <Section icon={Phone} title="Contact">
        <Field label="Mobile"><input value={d.mobile || ''} onChange={set('mobile')} placeholder="9876543210" style={inputStyle} /></Field>
        <Field label="Alternate mobile"><input value={d.alternate_mobile || ''} onChange={set('alternate_mobile')} style={inputStyle} /></Field>
      </Section>

      {/* 3 — Licence. CMP §22, and the field the allocation gate reads. */}
      <Section icon={IdCard} title="Driving licence">
        <Field label="Licence number" hint="Matched normalized, so spacing and hyphens do not create a duplicate.">
          <input value={d.licence_number || ''} onChange={set('licence_number')} placeholder="RJ14 20110012345" style={inputStyle} />
        </Field>
        <Field label="Licence class"><input value={d.licence_class || ''} onChange={set('licence_class')} placeholder="HMV" style={inputStyle} /></Field>
        <Field label="Valid from">
          <input type="date" value={d.licence_valid_from ? String(d.licence_valid_from).slice(0, 10) : ''} onChange={set('licence_valid_from')} style={inputStyle} />
        </Field>
        <Field label="Valid until" hint="An expired licence blocks allocation.">
          <input type="date" value={d.licence_valid_until ? String(d.licence_valid_until).slice(0, 10) : ''} onChange={set('licence_valid_until')} style={inputStyle} />
        </Field>
        {mode === 'edit' && (
          <Field label="Normalized (derived)">
            <input value={d.licence_normalized || ''} readOnly tabIndex={-1} style={readonlyStyle} />
          </Field>
        )}
      </Section>

      {/* 4 — Employment. BO-009: company OR supplier. */}
      <Section icon={Link2} title="Employment">
        <Field label="HR employee ID" hint="Link an employed driver to their HR record. One profile per employee.">
          <input type="number" value={d.hr_employee_id || ''} onChange={set('hr_employee_id')} style={inputStyle} />
        </Field>
        <Field label="Supplier ID" hint="For a supplier-provided driver. The supplier master arrives with a later ticket.">
          <input type="number" value={d.supplier_id || ''} onChange={set('supplier_id')} style={inputStyle} />
        </Field>
      </Section>
    </div>
  )
}
