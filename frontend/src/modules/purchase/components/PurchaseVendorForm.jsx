import { useState, useEffect } from 'react'
import { User, MapPin, Banknote, Undo2, Hash } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { PV_CATEGORIES, PV_CURRENCIES, PV_LANGUAGES, PV_COUNTRIES } from './purchaseVendorFormConstants'

/**
 * Purchase Vendor Master form — the full Create/Edit surface for the Purchase-owned
 * vendor entity (purchase_vendors). Feature-equivalent to a vendor master: Profile,
 * Billing & Shipping, Financial and Return Policies sections. 100% Purchase-owned —
 * no import from the shared Vendor / TPV / Customer modules; option lists come from
 * purchaseVendorFormConstants. Controlled: parent owns `value` and receives the next
 * object via `onChange`.
 */

const inputStyle = { width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }
const selectStyle = { ...inputStyle, cursor: 'pointer' }
const readonlyStyle = { ...inputStyle, background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'not-allowed' }

// Required-field + soft format validation. Returns the first error message or null.
export function validatePurchaseVendor(v) {
  if (!v.company_name?.trim()) return 'Company is required.'
  if (!v.category?.trim()) return 'Vendor Category is required.'
  if (!v.currency?.trim()) return 'Currency is required.'
  if (v.website && !/^(https?:\/\/)?([\w-]+\.)+[\w-]{2,}(\/\S*)?$/i.test(v.website)) return 'Website format looks invalid.'
  if (v.phone && !/^[0-9+\-()\s]{6,30}$/.test(v.phone)) return 'Phone format looks invalid.'
  if (v.gst_number && !/^[0-9A-Za-z]{1,20}$/.test(v.gst_number)) return 'GST number format looks invalid.'
  return null
}

function Section({ icon: Icon, title, children }) {
  return (
    <div style={{ marginBottom: 6 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, margin: '4px 0 12px' }}>
        <Icon size={15} style={{ color: '#7C3AED' }} />
        <h3 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: 0, textTransform: 'uppercase', letterSpacing: '.03em' }}>{title}</h3>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>{children}</div>
    </div>
  )
}

function Field({ label, required, children, full }) {
  return (
    <div style={full ? { gridColumn: '1 / -1' } : undefined}>
      <label className="label" style={{ display: 'block', marginBottom: 4 }}>{label}{required && <span style={{ color: '#ef4444' }}> *</span>}</label>
      {children}
    </div>
  )
}

export default function PurchaseVendorForm({ value, onChange, mode = 'create' }) {
  const v = value || {}
  const set = (field) => (e) => onChange({ ...v, [field]: e?.target ? e.target.value : e })

  // Categories come from the Settings master (Purchase → Settings → Vendor
  // category). Falls back to the built-in list when none are configured yet, so
  // the dropdown is never empty on a fresh tenant.
  const [categories, setCategories] = useState(PV_CATEGORIES)
  useEffect(() => {
    let alive = true
    purchaseApi.vendorCategories.list()
      .then((r) => {
        const names = (Array.isArray(r) ? r : (r?.data ?? [])).map((c) => c.name).filter(Boolean)
        if (alive && names.length) setCategories(names)
      })
      .catch(() => { /* keep the fallback list */ })
    return () => { alive = false }
  }, [])

  // Never drop the value a vendor already has, even if its category was removed.
  const categoryOptions = v.category && !categories.includes(v.category)
    ? [v.category, ...categories]
    : categories

  return (
    <div style={{ display: 'grid', gap: 18 }}>
      {/* 1 — Profile */}
      <Section icon={User} title="Profile">
        {mode === 'edit' && (
          <Field label="Vendor Code">
            <div style={{ position: 'relative' }}>
              <Hash size={13} style={{ position: 'absolute', left: 10, top: 11, color: 'var(--text-muted)' }} />
              <input value={v.purchase_vendor_code || 'Auto-generated'} readOnly tabIndex={-1} style={{ ...readonlyStyle, paddingLeft: 30 }} />
            </div>
          </Field>
        )}
        <Field label="Company" required>
          <input value={v.company_name || ''} onChange={set('company_name')} placeholder="Registered company name" style={inputStyle} />
        </Field>
        <Field label="Vendor Category" required>
          <select value={v.category || ''} onChange={set('category')} style={selectStyle}>
            <option value="">Select category…</option>
            {categoryOptions.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </Field>
        <Field label="Currency" required>
          <select value={v.currency || 'INR'} onChange={set('currency')} style={selectStyle}>
            {PV_CURRENCIES.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </Field>
        <Field label="Default Language">
          <select value={v.language || 'System Default'} onChange={set('language')} style={selectStyle}>
            {PV_LANGUAGES.map((l) => <option key={l} value={l}>{l}</option>)}
          </select>
        </Field>
        <Field label="Balance">
          <input type="number" step="0.01" value={v.balance ?? ''} onChange={set('balance')} placeholder="0.00" style={inputStyle} />
        </Field>
        <Field label="Balance As Of">
          <input type="date" value={(v.balance_as_of || '').slice(0, 10)} onChange={set('balance_as_of')} style={inputStyle} />
        </Field>
        <Field label="GST Number">
          <input value={v.gst_number || ''} onChange={set('gst_number')} placeholder="15-char GSTIN" style={inputStyle} />
        </Field>
        <Field label="Phone">
          <input value={v.phone || ''} onChange={set('phone')} placeholder="+91 …" style={inputStyle} />
        </Field>
        <Field label="Website">
          <input value={v.website || ''} onChange={set('website')} placeholder="https://…" style={inputStyle} />
        </Field>
        <Field label="Email">
          <input value={v.email || ''} onChange={set('email')} placeholder="vendor@company.com" style={inputStyle} />
        </Field>
      </Section>

      {/* 2 — Billing & Shipping */}
      <Section icon={MapPin} title="Billing & Shipping">
        <Field label="Address" full>
          <input value={v.address || ''} onChange={set('address')} placeholder="Street address" style={inputStyle} />
        </Field>
        <Field label="City"><input value={v.city || ''} onChange={set('city')} style={inputStyle} /></Field>
        <Field label="State"><input value={v.state || ''} onChange={set('state')} style={inputStyle} /></Field>
        <Field label="Zip Code"><input value={v.pincode || ''} onChange={set('pincode')} style={inputStyle} /></Field>
        <Field label="Country">
          <select value={v.country || ''} onChange={set('country')} style={selectStyle}>
            <option value="">Select country…</option>
            {PV_COUNTRIES.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </Field>
      </Section>

      {/* 3 — Financial */}
      <Section icon={Banknote} title="Financial">
        <Field label="Bank Details" full>
          <textarea value={v.bank_details || ''} onChange={set('bank_details')} rows={3} placeholder="Account holder, bank, account number, IFSC, branch…" style={{ ...inputStyle, resize: 'vertical' }} />
        </Field>
        <Field label="Payment Terms" full>
          <input value={v.payment_terms || ''} onChange={set('payment_terms')} placeholder="e.g. Net 30, 50% advance" style={inputStyle} />
        </Field>
      </Section>

      {/* 4 — Return Policies */}
      <Section icon={Undo2} title="Return Policies">
        <Field label="Return Policy" full>
          <textarea value={v.return_policy || ''} onChange={set('return_policy')} rows={3} placeholder="Return / replacement terms agreed with this vendor…" style={{ ...inputStyle, resize: 'vertical' }} />
        </Field>
      </Section>
    </div>
  )
}
