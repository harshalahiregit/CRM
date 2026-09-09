/**
 * The editable half of an employee's record.
 *
 * The Personal and Bank tabs read from `data.submission` — the onboarding form.
 * That means somebody hired through onboarding shows a bank account, somebody
 * added straight into the Employees screen shows nothing at all, and NEITHER can
 * be corrected: an IFSC typed wrongly during onboarding stayed wrong forever and
 * payroll worked from a spreadsheet instead.
 *
 * This renders the same fields from hr_employee_details, which is writable, and
 * falls back to the onboarding submission for anything not filled in yet — so an
 * existing profile looks exactly as it did until somebody edits it.
 *
 * Config-driven on purpose. Adding a field is one line here plus one line in the
 * request rules; nobody has to lay out another input by hand, which is how the
 * fifteenth field ends up in a different column width from the other fourteen.
 */

import { useState, useEffect, useMemo } from 'react'
import { GRAD } from '@/components/ui/brand'
import { Pencil, X, Save } from 'lucide-react'
import { hrApi } from '@/services/hrApi'

const TEXT = 'text', AREA = 'textarea', DATE = 'date', PICK = 'select', BOOL = 'bool'

const MARITAL   = ['Single', 'Married', 'Divorced', 'Widowed', 'Other']
const BLOOD     = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']
const ACCT_TYPE = ['Savings', 'Current']
const REGIME    = ['Old', 'New']

/** Groups keyed by which tab shows them. */
const DETAIL_GROUPS = {
  personal: [
    { title: 'Personal', fields: [
      { k: 'father_name',     label: "Father / Guardian" },
      { k: 'mother_name',     label: 'Mother' },
      { k: 'spouse_name',     label: 'Spouse' },
      { k: 'marital_status',  label: 'Marital Status', type: PICK, options: MARITAL },
      { k: 'blood_group',     label: 'Blood Group',    type: PICK, options: BLOOD },
      { k: 'nationality',     label: 'Nationality' },
      { k: 'religion',        label: 'Religion' },
      { k: 'personal_email',  label: 'Personal Email' },
      { k: 'alternate_phone', label: 'Alternate Phone' },
    ]},
    { title: 'Permanent Address', hint: 'As it appears on their documents — the address on the employee record is where they live now.', fields: [
      { k: 'permanent_address', label: 'Address', type: AREA, full: true },
      { k: 'permanent_city',    label: 'City' },
      { k: 'permanent_state',   label: 'State' },
      { k: 'permanent_pincode', label: 'Pincode' },
      { k: 'permanent_country', label: 'Country' },
    ]},
    { title: 'Education', fields: [
      { k: 'highest_qualification', label: 'Highest Qualification' },
      { k: 'specialization',        label: 'Specialization' },
      { k: 'institution',           label: 'Institution' },
      { k: 'year_of_passing',       label: 'Year of Passing' },
    ]},
    { title: 'Emergency Contact', fields: [
      { k: 'emergency_name',         label: 'Name' },
      { k: 'emergency_relationship', label: 'Relationship' },
      { k: 'emergency_phone',        label: 'Phone' },
      { k: 'emergency_alt_phone',    label: 'Alternate Phone' },
      { k: 'emergency_address',      label: 'Address', type: AREA, full: true },
    ]},
  ],
  bank: [
    { title: 'Bank', hint: 'Where the salary is paid. A wrong IFSC bounces the payment.', fields: [
      { k: 'bank_account_holder_name', label: 'Account Holder' },
      { k: 'bank_name',                label: 'Bank' },
      { k: 'bank_account_number',      label: 'Account Number', mono: true },
      { k: 'bank_ifsc',                label: 'IFSC',           mono: true },
      { k: 'bank_branch',              label: 'Branch' },
      { k: 'bank_account_type',        label: 'Account Type', type: PICK, options: ACCT_TYPE },
    ]},
    { title: 'Identity', fields: [
      { k: 'pan_number',             label: 'PAN',             mono: true },
      { k: 'aadhaar_number',         label: 'Aadhaar',         mono: true },
      { k: 'passport_number',        label: 'Passport No.',    mono: true },
      { k: 'passport_expiry',        label: 'Passport Expiry', type: DATE },
      { k: 'driving_licence_number', label: 'Driving Licence', mono: true },
    ]},
    { title: 'Applies to this person', hint: 'The rules decide what PF is; these decide whether this person has it. On by default — switch one off for a director who has opted out, or a consultant.', fields: [
      { k: 'pf_applicable',       label: 'PF',        type: BOOL },
      { k: 'eps_applicable',      label: 'EPS',       type: BOOL },
      { k: 'esic_applicable',     label: 'ESIC',      type: BOOL },
      { k: 'pt_applicable',       label: 'Prof. Tax', type: BOOL },
      { k: 'lwf_applicable',      label: 'LWF',       type: BOOL },
      { k: 'gratuity_applicable', label: 'Gratuity',  type: BOOL },
    ]},
    { title: 'Statutory', fields: [
      { k: 'uan_number',              label: 'UAN',             mono: true },
      { k: 'pf_number',               label: 'PF Number',       mono: true },
      // PF membership can start after employment — a probationer enrolled on
      // confirmation — so filing with the joining date would be wrong.
      { k: 'pf_joining_date',         label: 'PF Joining Date', type: DATE },
      // The PF register has a VPF column. One or the other, never both.
      { k: 'vpf_amount',              label: 'VPF Amount' },
      { k: 'vpf_percent',             label: 'VPF %' },
      { k: 'esic_number',             label: 'ESIC Number',     mono: true },
      { k: 'esic_ip_number',          label: 'ESIC IP Number',  mono: true },
      { k: 'esic_dispensary',         label: 'ESIC Dispensary' },
      // ESIC's ceiling is 25,000 for a person with disability, not 42,000.
      { k: 'is_disabled',             label: 'Person with Disability', type: BOOL },
      { k: 'pf_nominee_name',         label: 'PF Nominee' },
      { k: 'pf_nominee_relation',     label: 'Nominee Relation' },
      { k: 'tax_regime',              label: 'Tax Regime', type: PICK, options: REGIME },
      { k: 'is_international_worker', label: 'International Worker', type: BOOL },
      { k: 'has_previous_pf',         label: 'Has Previous PF',      type: BOOL },
    ]},
  ],
}

export default function EmployeeDetailPanel({ employeeId, group, fallback = {}, showToast, canEdit = true }) {
  const groups = DETAIL_GROUPS[group] || []

  const [detail, setDetail] = useState(null)
  const [editing, setEditing] = useState(false)
  const [form, setForm] = useState({})
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState({})

  useEffect(() => {
    let alive = true
    hrApi.employees.detail(employeeId)
      .then(d => { if (alive) setDetail(d || {}) })
      .catch(() => { if (alive) setDetail({}) })
    return () => { alive = false }
  }, [employeeId])

  // What to SHOW: the saved value, or what onboarding collected if nothing has
  // been saved. Without the fallback, opening this on an existing profile would
  // look like the data had been lost.
  const shown = useMemo(() => {
    const out = {}
    for (const g of groups) {
      for (const f of g.fields) {
        out[f.k] = detail?.[f.k] ?? fallback?.[f.k] ?? null
      }
    }
    return out
  }, [detail, fallback, groups])

  const startEdit = () => {
    // Seeded from what is displayed, so pressing Edit and Save adopts the
    // onboarding values rather than blanking them.
    setForm({ ...shown })
    setErrors({})
    setEditing(true)
  }

  const save = async () => {
    setSaving(true)
    setErrors({})
    try {
      const saved = await hrApi.employees.saveDetail(employeeId, form)
      setDetail(saved)
      setEditing(false)
      showToast?.('Details saved')
    } catch (err) {
      const bag = err.response?.data?.errors
      if (bag) {
        setErrors(bag)
        showToast?.('Some fields need correcting', 'error')
      } else {
        showToast?.(err.response?.data?.message || 'Could not save the details', 'error')
      }
    } finally {
      setSaving(false)
    }
  }

  if (detail === null) {
    return <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Loading details…</p>
  }

  return (
    <div>
      {canEdit && (
        <div className="flex justify-end gap-2 mb-3">
          {!editing ? (
            <button onClick={startEdit} className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold"
              style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
              <Pencil size={12}/> Edit
            </button>
          ) : (
            <>
              <button onClick={() => setEditing(false)} disabled={saving}
                className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold"
                style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                <X size={12}/> Cancel
              </button>
              <button onClick={save} disabled={saving}
                className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold text-white"
                style={{ background: GRAD, opacity: saving ? 0.6 : 1 }}>
                <Save size={12}/> {saving ? 'Saving…' : 'Save'}
              </button>
            </>
          )}
        </div>
      )}

      {groups.map(g => (
        <div key={g.title} className="mb-5">
          <p className="text-[11px] font-bold uppercase mb-1" style={{ color: 'var(--text-muted)', letterSpacing: '0.04em' }}>
            {g.title}
          </p>
          {g.hint && <p className="text-[10px] mb-2" style={{ color: 'var(--text-muted)' }}>{g.hint}</p>}

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            {g.fields.map(f => (
              <Cell key={f.k} field={f} editing={editing}
                value={editing ? form[f.k] : shown[f.k]}
                error={errors[f.k]?.[0]}
                onChange={v => setForm(prev => ({ ...prev, [f.k]: v }))}/>
            ))}
          </div>
        </div>
      ))}
    </div>
  )
}

function Cell({ field, editing, value, error, onChange }) {
  const span = field.full ? 'md:col-span-2 lg:col-span-3' : ''

  return (
    <div className={span} style={{ background: 'var(--bg-input)', borderRadius: 12, padding: '12px 14px' }}>
      <p className="text-[10px] font-bold uppercase" style={{ color: 'var(--text-muted)', letterSpacing: '0.04em' }}>
        {field.label}
      </p>

      {!editing ? (
        <p className={`text-sm font-semibold mt-1 ${field.mono ? 'font-mono' : ''}`}
          style={{ color: 'var(--text-h)', wordBreak: 'break-word' }}>
          {field.type === BOOL ? (value ? 'Yes' : 'No') : (value || '—')}
        </p>
      ) : (
        <div className="mt-1">
          <Input field={field} value={value} onChange={onChange}/>
          {error && <p className="text-[10px] mt-1" style={{ color: '#ef4444' }}>{error}</p>}
        </div>
      )}
    </div>
  )
}

function Input({ field, value, onChange }) {
  const cls = `input-3d text-sm ${field.mono ? 'font-mono' : ''}`

  if (field.type === BOOL) {
    return (
      <label className="flex items-center gap-2 cursor-pointer text-sm" style={{ color: 'var(--text-h)' }}>
        <input type="checkbox" checked={!!value} onChange={e => onChange(e.target.checked)}/>
        {value ? 'Yes' : 'No'}
      </label>
    )
  }

  if (field.type === PICK) {
    return (
      <select className={cls} value={value || ''} onChange={e => onChange(e.target.value)}>
        <option value="">Select…</option>
        {field.options.map(o => <option key={o} value={o}>{o}</option>)}
      </select>
    )
  }

  if (field.type === AREA) {
    return <textarea rows={2} className={`${cls} resize-none`} value={value || ''} onChange={e => onChange(e.target.value)}/>
  }

  return (
    <input type={field.type === DATE ? 'date' : 'text'} className={cls}
      value={value || ''} onChange={e => onChange(e.target.value)}/>
  )
}
