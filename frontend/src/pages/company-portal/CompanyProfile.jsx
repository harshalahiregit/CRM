import { useState, useEffect, useRef } from 'react'
import { useOutletContext } from 'react-router-dom'
import { Building2, Upload } from 'lucide-react'
import { companyPortalApi } from '@/services/companyPortalApi'

const unwrap = r => r?.data ?? r
const COMPANY_TYPES = ['Client', 'Vendor', 'Partner', 'Consultant', 'Sub Contractor']
const SIZES = ['1-10', '11-50', '51-200', '201-500', '500+']
const lbl = { display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 6, textTransform: 'uppercase', letterSpacing: '0.05em' }
const inp = { width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }

export default function CompanyProfile() {
  const { perms } = useOutletContext() || {}
  const canEdit = perms?.settings?.manage
  const [f, setF] = useState(null)
  const [logoUrl, setLogoUrl] = useState(null)
  const [toast, setToast] = useState(null)
  const [busy, setBusy] = useState(false)
  const fileRef = useRef()
  const show = (m, t = 'success') => { setToast({ m, t }); setTimeout(() => setToast(null), 3000) }

  const load = () => companyPortalApi.adminProfile().then(r => {
    const d = unwrap(r); setF(d)
    if (d.logo_path) companyPortalApi.logoBlob().then(b => setLogoUrl(URL.createObjectURL(b))).catch(() => {})
  }).catch(() => {})
  useEffect(() => { load() }, [])
  useEffect(() => () => { if (logoUrl) URL.revokeObjectURL(logoUrl) }, [logoUrl])
  if (!f) return <div style={{ color: 'var(--text-muted)', padding: 40, textAlign: 'center' }}>Loading…</div>

  const set = k => e => setF(s => ({ ...s, [k]: e.target.value }))
  const save = async () => {
    setBusy(true)
    try {
      await companyPortalApi.updateProfile({
        name: f.name, company_type: f.company_type, industry: f.industry, company_size: f.company_size, website: f.website,
        gst_number: f.gst_number || null, pan_number: f.pan_number || null, about: f.about,
        contact_person: f.contact_person, contact_email: f.contact_email, contact_phone: f.contact_phone,
        secondary_contact_person: f.secondary_contact_person, secondary_contact_email: f.secondary_contact_email, secondary_contact_phone: f.secondary_contact_phone,
        billing_address: f.billing_address, office_address: f.office_address,
      })
      show('Profile saved')
    } catch (e) { show(e.response?.data?.message || 'Save failed', 'error') }
    finally { setBusy(false) }
  }
  const uploadLogo = async (e) => {
    const file = e.target.files?.[0]; if (!file) return
    try { await companyPortalApi.uploadLogo(file); show('Logo updated'); load() }
    catch (err) { show(err.response?.data?.message || 'Upload failed', 'error') }
  }

  // Invoked as a function (F({...})), NOT as <F/>, so inputs don't remount and
  // lose focus on each keystroke.
  const F = ({ l, k, full, type }) => (
    <div key={l} style={{ gridColumn: full ? '1 / -1' : 'auto' }}>
      <label style={lbl}>{l}</label>
      {type === 'select-type' ? <select value={f.company_type || ''} onChange={set('company_type')} disabled={!canEdit} style={inp}>{COMPANY_TYPES.map(t => <option key={t}>{t}</option>)}</select>
        : type === 'select-size' ? <select value={f.company_size || ''} onChange={set('company_size')} disabled={!canEdit} style={inp}><option value="">—</option>{SIZES.map(s => <option key={s}>{s}</option>)}</select>
        : type === 'area' ? <textarea rows={2} value={f[k] || ''} onChange={set(k)} disabled={!canEdit} style={{ ...inp, resize: 'vertical' }} />
        : <input value={f[k] || ''} onChange={set(k)} disabled={!canEdit} style={inp} />}
    </div>
  )

  return (
    <div>
      {toast && <div style={{ position: 'fixed', top: 20, right: 20, zIndex: 9999, padding: '12px 20px', borderRadius: 14, fontSize: 13, fontWeight: 700, color: '#fff', background: toast.t === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.m}</div>}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 18, flexWrap: 'wrap', gap: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
          <div style={{ width: 56, height: 56, borderRadius: 14, display: 'flex', alignItems: 'center', justifyContent: 'center', overflow: 'hidden', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
            {logoUrl ? <img src={logoUrl} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : <Building2 size={24} color="#fff" />}
          </div>
          <div>
            <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>{f.name}</h1>
            <p style={{ fontSize: 12.5, color: 'var(--text-muted)', marginTop: 2 }}>{f.company_code} · {f.company_type} · {f.status}</p>
          </div>
        </div>
        {canEdit && <div style={{ display: 'flex', gap: 8 }}>
          <input ref={fileRef} type="file" accept="image/*" onChange={uploadLogo} style={{ display: 'none' }} />
          <button onClick={() => fileRef.current?.click()} style={ghost}><Upload size={14} /> Logo</button>
          <button onClick={save} disabled={busy} style={primary}>{busy ? 'Saving…' : 'Save Profile'}</button>
        </div>}
      </div>

      {!canEdit && <p style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 12 }}>Read-only — only a Company Admin can edit the profile.</p>}

      <div className="card-3d" style={{ padding: 20, marginBottom: 16 }}>
        <p style={{ ...lbl, marginBottom: 12, color: '#a78bfa' }}>Organization</p>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 14 }}>
          {F({ l: 'Company Name', k: 'name' })}{F({ l: 'Company Type', type: 'select-type' })}{F({ l: 'Industry', k: 'industry' })}
          {F({ l: 'Company Size', type: 'select-size' })}{F({ l: 'Website', k: 'website' })}{F({ l: 'GST Number', k: 'gst_number' })}{F({ l: 'PAN', k: 'pan_number' })}
          {F({ l: 'About Company', k: 'about', type: 'area', full: true })}
        </div>
      </div>
      <div className="card-3d" style={{ padding: 20, marginBottom: 16 }}>
        <p style={{ ...lbl, marginBottom: 12, color: '#a78bfa' }}>Contacts</p>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 14 }}>
          {F({ l: 'Primary Contact', k: 'contact_person' })}{F({ l: 'Primary Email', k: 'contact_email' })}{F({ l: 'Primary Phone', k: 'contact_phone' })}
          {F({ l: 'Secondary Contact', k: 'secondary_contact_person' })}{F({ l: 'Secondary Email', k: 'secondary_contact_email' })}{F({ l: 'Secondary Phone', k: 'secondary_contact_phone' })}
        </div>
      </div>
      <div className="card-3d" style={{ padding: 20 }}>
        <p style={{ ...lbl, marginBottom: 12, color: '#a78bfa' }}>Addresses</p>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(240px,1fr))', gap: 14 }}>
          {F({ l: 'Billing Address', k: 'billing_address', type: 'area' })}{F({ l: 'Office Address', k: 'office_address', type: 'area' })}
        </div>
      </div>
    </div>
  )
}

const ghost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-muted)', fontWeight: 700, fontSize: 13, cursor: 'pointer' }
const primary = { padding: '9px 18px', borderRadius: 10, border: 'none', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 800, fontSize: 13, cursor: 'pointer' }
