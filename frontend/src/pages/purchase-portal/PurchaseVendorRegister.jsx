import { useState } from 'react'
import { Link } from 'react-router-dom'
import { purchaseVendorAuthApi } from '@/services/purchaseVendorAuthApi'
import { AuthShell, lbl, inp, primaryBtn, linkStyle, errStyle } from './PurchaseVendorLogin'

/** Purchase Vendor self-registration — creates ONLY a PurchaseVendor. */
export default function PurchaseVendorRegister() {
  const [form, setForm] = useState({ tenant_id: '', company_name: '', email: '', phone: '', password: '', password_confirmation: '' })
  const [err, setErr] = useState('')
  const [done, setDone] = useState(false)
  const [busy, setBusy] = useState(false)
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true); setErr('')
    try { await purchaseVendorAuthApi.register(form); setDone(true) }
    catch (e) { setErr(e?.response?.data?.message || 'Registration failed.') } finally { setBusy(false) }
  }

  if (done) return (
    <AuthShell title="Check your email" subtitle="Verify your email to activate your account">
      <p style={{ color: 'var(--text-muted, #9ca3af)', fontSize: 13, textAlign: 'center' }}>Registration received. Once your email is verified you can sign in.</p>
      <Link to="/purchase-portal/login" style={{ ...primaryBtn, display: 'block', textAlign: 'center', textDecoration: 'none', marginTop: 12 }}>Back to Sign In</Link>
    </AuthShell>
  )

  return (
    <AuthShell title="Register as a Vendor" subtitle="Create your procurement portal account">
      <form onSubmit={submit} style={{ display: 'grid', gap: 10 }}>
        <div><label style={lbl}>Tenant ID</label><input value={form.tenant_id} onChange={set('tenant_id')} style={inp} required /></div>
        <div><label style={lbl}>Company Name</label><input value={form.company_name} onChange={set('company_name')} style={inp} required /></div>
        <div><label style={lbl}>Email</label><input type="email" value={form.email} onChange={set('email')} style={inp} required /></div>
        <div><label style={lbl}>Phone</label><input value={form.phone} onChange={set('phone')} style={inp} /></div>
        <div><label style={lbl}>Password</label><input type="password" value={form.password} onChange={set('password')} style={inp} required /></div>
        <div><label style={lbl}>Confirm Password</label><input type="password" value={form.password_confirmation} onChange={set('password_confirmation')} style={inp} required /></div>
        {err && <div style={errStyle}>{err}</div>}
        <button type="submit" disabled={busy} style={primaryBtn}>{busy ? 'Registering…' : 'Register'}</button>
        <div style={{ textAlign: 'center', fontSize: 12.5 }}><Link to="/purchase-portal/login" style={linkStyle}>Already have an account? Sign in</Link></div>
      </form>
    </AuthShell>
  )
}
