import { useState, useEffect } from 'react'
import { Building2, Save, Loader2, CheckCircle, Landmark } from 'lucide-react'
import { purchasePortalApi } from '@/services/purchasePortalApi'

/**
 * Purchase Vendor Portal — Company Profile & Commercial.
 *
 * The self-service surface for a Purchase vendor to view and maintain their own
 * business + commercial details (bank, payment terms, return policy). Backed by
 * PUT /portal/purchase/profile, which accepts business fields only — code,
 * category, status and auth are never editable here.
 */
const FIELDS = [
  { key: 'company_name', label: 'Company Name', required: true },
  { key: 'legal_name',   label: 'Legal Name' },
  { key: 'gst_number',   label: 'GST Number' },
  { key: 'pan_number',   label: 'PAN Number' },
  { key: 'phone',        label: 'Phone' },
  { key: 'website',      label: 'Website' },
  { key: 'address',      label: 'Address', full: true },
  { key: 'city',         label: 'City' },
  { key: 'state',        label: 'State' },
  { key: 'country',      label: 'Country' },
  { key: 'pincode',      label: 'Pincode' },
]
const COMMERCIAL = [
  { key: 'bank_details',  label: 'Bank Details', area: true },
  { key: 'payment_terms', label: 'Payment Terms', area: true },
  { key: 'return_policy', label: 'Return Policy', area: true },
]

export default function PurchasePortalProfile() {
  const [vendor, setVendor] = useState(null)
  const [form, setForm] = useState({})
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    purchasePortalApi.me()
      .then(d => {
        const v = d?.vendor ?? null
        setVendor(v)
        if (v) {
          const init = {}
          ;[...FIELDS, ...COMMERCIAL].forEach(f => { init[f.key] = v[f.key] ?? '' })
          setForm(init)
        }
      })
      .catch(() => setError('Could not load your profile.'))
      .finally(() => setLoading(false))
  }, [])

  const set = (k) => (e) => { setForm(p => ({ ...p, [k]: e.target.value })); setSaved(false) }

  const save = async () => {
    setSaving(true); setError('')
    try {
      const payload = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]))
      const res = await purchasePortalApi.updateProfile(payload)
      if (res?.vendor) setVendor(res.vendor)
      setSaved(true)
    } catch (e) {
      const errs = e?.response?.data?.errors
      setError(errs ? Object.values(errs).flat().join('\n') : (e?.response?.data?.message || 'Failed to save.'))
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return (
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, color: 'var(--text-muted)', padding: '40px 0', justifyContent: 'center' }}>
        <Loader2 size={18} className="rfq-spin" /> Loading profile…
      </div>
    )
  }

  const input = { width: '100%', padding: '9px 11px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 13 }

  return (
    <div style={{ maxWidth: 900, margin: '0 auto' }}>
      {/* Company Profile */}
      <div className="portal-card portal-card-padded" style={{ marginBottom: 18 }}>
        <SectionHead icon={Building2} title="Company Profile" sub="Your registered business details" />
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 14 }}>
          {FIELDS.map(f => (
            <label key={f.key} style={{ display: 'block', gridColumn: f.full ? '1 / -1' : undefined }}>
              <span style={labelStyle}>{f.label}{f.required && <span style={{ color: '#ef4444' }}> *</span>}</span>
              <input value={form[f.key] ?? ''} onChange={set(f.key)} style={input} />
            </label>
          ))}
        </div>
      </div>

      {/* Commercial */}
      <div className="portal-card portal-card-padded" style={{ marginBottom: 18 }}>
        <SectionHead icon={Landmark} title="Commercial" sub="Bank, payment terms and return policy" />
        <div style={{ display: 'grid', gap: 14 }}>
          {COMMERCIAL.map(f => (
            <label key={f.key} style={{ display: 'block' }}>
              <span style={labelStyle}>{f.label}</span>
              <textarea value={form[f.key] ?? ''} onChange={set(f.key)} rows={3} style={{ ...input, resize: 'vertical' }} />
            </label>
          ))}
        </div>
      </div>

      {error && <p style={{ color: '#ef4444', fontSize: 12.5, whiteSpace: 'pre-wrap', margin: '0 0 12px' }}>{error}</p>}

      <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
        <button onClick={save} disabled={saving}
          style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '11px 22px', borderRadius: 10, border: 'none', background: 'linear-gradient(135deg,#6366f1,#4f46e5)', color: '#fff', fontWeight: 800, fontSize: 13.5, cursor: saving ? 'default' : 'pointer', opacity: saving ? 0.7 : 1 }}>
          {saving ? <Loader2 size={16} className="rfq-spin" /> : <Save size={16} />} Save Changes
        </button>
        {saved && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, color: '#10b981', fontSize: 13, fontWeight: 700 }}><CheckCircle size={16} /> Saved</span>}
        {vendor?.purchase_vendor_code && <span style={{ marginLeft: 'auto', fontSize: 12, color: 'var(--text-muted)' }}>Vendor Code: <strong style={{ color: 'var(--text-h)' }}>{vendor.purchase_vendor_code}</strong></span>}
      </div>
    </div>
  )
}

const labelStyle = { display: 'block', fontSize: 11.5, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 5 }

function SectionHead({ icon: Icon, title, sub }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16 }}>
      <div style={{ width: 40, height: 40, borderRadius: 12, background: 'rgba(99,102,241,0.12)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
        <Icon size={20} style={{ color: '#6366f1' }} />
      </div>
      <div>
        <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>{title}</h2>
        <p style={{ margin: '2px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>{sub}</p>
      </div>
    </div>
  )
}
