import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Building2, CheckCircle } from 'lucide-react'
import { companyPortalApi } from '@/services/companyPortalApi'

const COMPANY_TYPES = ['Client', 'Vendor', 'Partner', 'Consultant', 'Sub Contractor']
const SIZES = ['1-10', '11-50', '51-200', '201-500', '500+']

const lbl = { display: 'block', fontSize: 11, fontWeight: 700, color: '#8b85a8', marginBottom: 6, textTransform: 'uppercase', letterSpacing: '0.05em' }
const box = { width: '100%', padding: '11px 14px', background: 'rgba(255,255,255,0.04)', border: '1px solid rgba(255,255,255,0.08)', borderRadius: 10, color: '#edeaf8', fontSize: 14, outline: 'none', boxSizing: 'border-box' }

export default function CompanyRegisterPage() {
  const [f, setF] = useState({
    company_name: '', company_type: 'Client', industry: '', company_size: '', website: '',
    gst_number: '', pan_number: '', admin_name: '', admin_email: '', admin_phone: '',
    password: '', password_confirmation: '',
  })
  const [terms, setTerms] = useState(false)
  const [errors, setErrors] = useState({})
  const [apiError, setApiError] = useState('')
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState(null)

  const set = k => e => setF(s => ({ ...s, [k]: e.target.value }))

  const submit = async (e) => {
    e.preventDefault()
    setErrors({}); setApiError('')
    if (f.password !== f.password_confirmation) { setErrors({ password_confirmation: ['Passwords do not match'] }); return }
    if (!terms) { setApiError('Please accept the terms to continue.'); return }
    setBusy(true)
    try {
      const res = await companyPortalApi.register({ ...f, terms: true })
      setDone(res?.data?.company_code || 'submitted')
    } catch (err) {
      if (err.response?.status === 422) setErrors(err.response.data.errors || {})
      else setApiError(err.response?.data?.message || 'Registration failed. Please try again.')
    } finally { setBusy(false) }
  }

  const wrap = { minHeight: '100vh', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: 24, background: '#0b0b16' }

  if (done) return (
    <div style={{ ...wrap, alignItems: 'center' }}>
      <div style={{ background: '#12121f', border: '1px solid rgba(124,58,237,0.25)', borderRadius: 18, padding: 40, textAlign: 'center', maxWidth: 440 }}>
        <div style={{ width: 56, height: 56, borderRadius: 16, background: 'rgba(16,185,129,0.12)', color: '#10b981', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 28, margin: '0 auto 16px' }}><CheckCircle size={30} /></div>
        <h2 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: '#edeaf8' }}>Registration Submitted</h2>
        <p style={{ marginTop: 8, color: '#8b85a8', fontSize: 14 }}>Your company account{done !== 'submitted' ? ` (${done})` : ''} is awaiting HR approval. You'll receive an email once it's activated, then you can log in.</p>
        <Link to="/auth/login" style={{ display: 'inline-block', marginTop: 18, padding: '10px 20px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 700, fontSize: 13, textDecoration: 'none' }}>Go to Login</Link>
      </div>
    </div>
  )

  return (
    <div style={wrap}>
      <div style={{ width: '100%', maxWidth: 640 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6 }}>
          <span style={{ width: 40, height: 40, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}><Building2 size={20} color="#fff" /></span>
          <h1 style={{ fontSize: 24, fontWeight: 900, color: '#edeaf8', margin: 0 }}>Register your Company</h1>
        </div>
        <p style={{ color: '#8b85a8', fontSize: 13.5, marginBottom: 20 }}>Create a company account to submit hiring requirements and track your recruitment.</p>

        {apiError && <div style={{ marginBottom: 16, padding: '12px 16px', borderRadius: 12, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.3)', color: '#f87171', fontSize: 13 }}>{apiError}</div>}

        <form onSubmit={submit}>
          <div style={{ background: '#12121f', border: '1px solid rgba(124,58,237,0.15)', borderRadius: 16, padding: 22, display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))', gap: 14 }}>
            <Field l="Company Name *" err={errors.company_name} full><input value={f.company_name} onChange={set('company_name')} style={box} placeholder="e.g. Northwind Traders" /></Field>
            <Field l="Company Type"><select value={f.company_type} onChange={set('company_type')} style={box}>{COMPANY_TYPES.map(t => <option key={t}>{t}</option>)}</select></Field>
            <Field l="Industry"><input value={f.industry} onChange={set('industry')} style={box} /></Field>
            <Field l="Company Size"><select value={f.company_size} onChange={set('company_size')} style={box}><option value="">—</option>{SIZES.map(s => <option key={s}>{s}</option>)}</select></Field>
            <Field l="Website"><input value={f.website} onChange={set('website')} style={box} placeholder="https://" /></Field>
            <Field l="GST Number" err={errors.gst_number}><input value={f.gst_number} onChange={set('gst_number')} style={box} placeholder="15-char GSTIN" /></Field>
            <Field l="PAN" err={errors.pan_number}><input value={f.pan_number} onChange={set('pan_number')} style={box} placeholder="ABCDE1234F" /></Field>
          </div>

          <p style={{ ...lbl, marginTop: 20, marginBottom: 10, color: '#a78bfa' }}>Company Admin (your login)</p>
          <div style={{ background: '#12121f', border: '1px solid rgba(124,58,237,0.15)', borderRadius: 16, padding: 22, display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))', gap: 14 }}>
            <Field l="Full Name *" err={errors.admin_name}><input value={f.admin_name} onChange={set('admin_name')} style={box} /></Field>
            <Field l="Email *" err={errors.admin_email}><input type="email" value={f.admin_email} onChange={set('admin_email')} style={box} /></Field>
            <Field l="Phone"><input value={f.admin_phone} onChange={set('admin_phone')} style={box} /></Field>
            <Field l="Password *" err={errors.password}><input type="password" value={f.password} onChange={set('password')} style={box} placeholder="min 8 chars" /></Field>
            <Field l="Confirm Password *" err={errors.password_confirmation}><input type="password" value={f.password_confirmation} onChange={set('password_confirmation')} style={box} /></Field>
          </div>

          <label style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 16, color: '#8b85a8', fontSize: 13, cursor: 'pointer' }}>
            <input type="checkbox" checked={terms} onChange={e => setTerms(e.target.checked)} style={{ accentColor: '#7C3AED', width: 16, height: 16 }} />
            I agree to the Terms of Service and Privacy Policy
          </label>

          <button type="submit" disabled={busy} style={{ width: '100%', marginTop: 18, padding: '13px 0', borderRadius: 12, border: 'none', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 800, fontSize: 15, cursor: 'pointer', opacity: busy ? 0.7 : 1 }}>
            {busy ? 'Submitting…' : 'Submit Registration'}
          </button>
          <p style={{ textAlign: 'center', marginTop: 14, color: '#8b85a8', fontSize: 13 }}>
            Already have an account? <Link to="/auth/login" style={{ color: '#a78bfa', fontWeight: 600 }}>Log in</Link>
          </p>
        </form>
      </div>
    </div>
  )
}

function Field({ l, children, err, full }) {
  return (
    <div style={{ gridColumn: full ? '1 / -1' : 'auto' }}>
      <label style={lbl}>{l}</label>
      {children}
      {err && <p style={{ color: '#f87171', fontSize: 11.5, marginTop: 4 }}>{Array.isArray(err) ? err[0] : err}</p>}
    </div>
  )
}
