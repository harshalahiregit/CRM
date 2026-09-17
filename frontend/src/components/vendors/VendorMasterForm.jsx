import { INDIAN_STATES } from '@/lib/indianStates'

/**
 * Adding a vendor asks the same thirteen questions in both modules.
 *
 * It did not. TPV asked thirteen; Purchase asked twenty-eight — currency,
 * default language, opening balance and the date it was as of, contact person,
 * their designation, a second company phone, website, manpower, MSME, country,
 * bank details, payment terms, return policy. Same job, same button, two
 * completely different forms, and the longer one asked for a return policy
 * before anyone had agreed to buy anything.
 *
 * Thirteen is the set that has to be true at the moment a vendor is created: who
 * they are, how to reach them, how they log in, and where they are. Everything
 * else describes a trading relationship that does not exist yet, and all of it
 * is still editable afterwards on the vendor's Profile tab, which is where a
 * commercial term belongs.
 *
 * ── Nothing is lost on edit ─────────────────────────────────────────────
 * The fields this form does not render are still carried in the caller's state
 * and still posted, because the edit path seeds itself from the full record.
 * Dropping an input is not the same as clearing a column, and this form is
 * careful about the difference: a Purchase vendor with payment terms keeps them
 * after being edited here.
 *
 * Props:
 *   value / onChange   controlled; the caller owns the object
 *   mode               'create' | 'edit'
 *   moduleName         section heading — "Purchase Vendor" / "Third Party Vendor"
 *   code               the vendor's code, shown read-only when editing
 */
export default function VendorMasterForm({ value, onChange, mode = 'create', moduleName = 'Vendor', code = null }) {
  const v = value || {}
  const set = (field) => (e) => onChange({ ...v, [field]: e?.target ? e.target.value : e })
  const isNew = mode !== 'edit'

  return (
    <div>
      <Section title={`${moduleName} Information`} />
      <div style={grid2}>
        {!isNew && code && (
          <Field label="Vendor Code">
            {/* Assigned on creation and never changes, so it is shown rather
                than asked for — and never on the create form, where there is
                nothing to show. */}
            <input value={code} readOnly style={readonlyStyle} />
          </Field>
        )}
        <Field label="Vendor Name">
          <input value={v.name || ''} onChange={set('name')} placeholder="Contact / login name" style={inputStyle} />
        </Field>
        <Field label="Company" required>
          <input value={v.company_name || ''} onChange={set('company_name')} placeholder="Company name" style={inputStyle} />
        </Field>
        <Field label="Email">
          <input type="email" value={v.email || ''} onChange={set('email')} placeholder="login@vendor.com" style={inputStyle} />
        </Field>
        <Field label="Phone">
          <input value={v.phone || ''} onChange={set('phone')} placeholder="Phone" style={inputStyle} />
        </Field>
        <Field label="GST No">
          <input value={v.gst_number || ''} onChange={set('gst_number')} placeholder="GSTIN" style={inputStyle} />
        </Field>
        <Field label="Vendor Type" required>
          {/* The empty placeholder is load-bearing. vendor_type starts as '',
              and a <select> whose value matches no <option> renders the FIRST
              one — so without this the field showed "Permanent" while holding
              '', and the required check rejected a form that looked filled in. */}
          <select value={v.vendor_type || ''} onChange={set('vendor_type')} style={selectStyle}>
            <option value="">— Select vendor type —</option>
            <option value="standard">Permanent</option>
            <option value="temporary">Temporary</option>
          </select>
        </Field>
        <Field label="Status">
          <select value={v.status || 'Active'} onChange={set('status')} style={selectStyle}>
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
          </select>
        </Field>
      </div>

      <Section title="Login Credentials" />
      <div style={grid2}>
        <Field label="Password">
          <input type="password" value={v.password || ''} onChange={set('password')} placeholder="••••••" style={inputStyle} />
        </Field>
        <Field label="Confirm Password">
          <input type="password" value={v.password_confirmation || ''} onChange={set('password_confirmation')} placeholder="••••••" style={inputStyle} />
        </Field>
      </div>
      <p style={{ fontSize: 11, color: 'var(--text-muted)', margin: '2px 0 0' }}>
        {isNew
          ? 'Leave blank and one is generated — it is shown once, straight after saving.'
          : 'Leave password blank to keep the existing password.'}
      </p>

      <Section title="Address Information" />
      <Field label="Address" full>
        <input value={v.address || ''} onChange={set('address')} placeholder="Street address" style={inputStyle} />
      </Field>
      <div style={grid3}>
        <Field label="City">
          <input value={v.city || ''} onChange={set('city')} placeholder="City" style={inputStyle} />
        </Field>
        <Field label="State">
          <select value={v.state || ''} onChange={set('state')} style={selectStyle}>
            <option value="">Select State</option>
            {INDIAN_STATES.map(s => <option key={s} value={s}>{s}</option>)}
          </select>
        </Field>
        <Field label="Pincode">
          <input value={v.pincode || ''} onChange={set('pincode')} placeholder="Pincode" style={inputStyle} />
        </Field>
      </div>
    </div>
  )
}

/**
 * The same checks both modules ran, in one place.
 *
 * Returns the first problem as a sentence, or null. Deliberately mirrors what
 * the servers enforce — a rule here that the backend does not have would block
 * a save the API would have accepted, and the reverse submits into a 422 the
 * form could have explained itself.
 */
export function validateVendorMaster(v, { isNew = true } = {}) {
  if (!v?.company_name?.trim()) return 'Company is required.'
  if (!v?.vendor_type?.trim()) return 'Vendor Type is required.'
  if (isNew && !v?.email?.trim()) return 'Email is required to create the login.'
  if (v?.email && !/^\S+@\S+\.\S+$/.test(v.email)) return 'That email address does not look valid.'
  if (v?.phone && !/^[0-9+\-()\s]{6,30}$/.test(v.phone)) return 'Phone format looks invalid.'
  if (v?.gst_number && !/^[0-9A-Za-z]{1,20}$/.test(v.gst_number)) return 'GST number format looks invalid.'
  if (v?.password || v?.password_confirmation) {
    if ((v.password || '').length < 6) return 'Password must be at least 6 characters.'
    if (v.password !== v.password_confirmation) return 'Passwords do not match.'
  }

  return null
}

/**
 * The fields this form collects, for a caller building a create payload.
 *
 * An explicit list rather than the whole state object: the edit path seeds
 * itself from the full record, so posting everything would send back columns
 * the form never showed — including a few the API does not accept, which fails
 * the save for a field nobody touched.
 */
export const VENDOR_MASTER_FIELDS = [
  'name', 'company_name', 'email', 'phone', 'gst_number', 'vendor_type', 'status',
  'address', 'city', 'state', 'pincode',
]

function Section({ title }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8, margin: '14px 0 8px' }}>
      <span style={{ width: 6, height: 6, borderRadius: '50%', background: '#a78bfa' }} />
      <span style={{ fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.05em', color: '#a78bfa' }}>{title}</span>
      <div style={{ flex: 1, height: 1, background: 'var(--border)' }} />
    </div>
  )
}

function Field({ label, required, children, full }) {
  return (
    <div style={full ? { gridColumn: '1 / -1' } : undefined}>
      <label className="label" style={{ display: 'block', marginBottom: 4, fontSize: 12, color: 'var(--text-muted)' }}>
        {label}{required && <span style={{ color: '#ef4444' }}> *</span>}
      </label>
      {children}
    </div>
  )
}

// A phone is 390px wide; two fixed columns there are two unusable ones.
const grid2 = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 12 }
const grid3 = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 12 }
const inputStyle = { width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }
const selectStyle = { ...inputStyle, cursor: 'pointer' }
const readonlyStyle = { ...inputStyle, background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'not-allowed' }
