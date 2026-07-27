import { useState } from 'react'
import { useNavigate, useSearchParams, Link } from 'react-router-dom'
import { purchaseVendorAuthApi } from '@/services/purchaseVendorAuthApi'
import { AuthShell, lbl, inp, primaryBtn, linkStyle, errStyle } from './PurchaseVendorLogin'

/** Purchase Vendor reset-password — consumes the emailed token. */
export default function PurchaseVendorResetPassword() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [form, setForm] = useState({
    email: params.get('email') || '',
    token: params.get('token') || '',
    password: '',
    password_confirmation: '',
  })
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true); setErr('')
    try { await purchaseVendorAuthApi.resetPassword(form); navigate('/purchase-portal/login') }
    catch (e) { setErr(e?.response?.data?.message || 'Reset failed.') } finally { setBusy(false) }
  }

  return (
    <AuthShell title="Choose a new password" subtitle="Enter and confirm your new password">
      <form onSubmit={submit} style={{ display: 'grid', gap: 10 }}>
        <div><label style={lbl}>Email</label><input type="email" value={form.email} onChange={set('email')} style={inp} required /></div>
        <div><label style={lbl}>Reset Token</label><input value={form.token} onChange={set('token')} style={inp} required /></div>
        <div><label style={lbl}>New Password</label><input type="password" value={form.password} onChange={set('password')} style={inp} required /></div>
        <div><label style={lbl}>Confirm Password</label><input type="password" value={form.password_confirmation} onChange={set('password_confirmation')} style={inp} required /></div>
        {err && <div style={errStyle}>{err}</div>}
        <button type="submit" disabled={busy} style={primaryBtn}>{busy ? 'Updating…' : 'Update password'}</button>
        <div style={{ textAlign: 'center', fontSize: 12.5 }}><Link to="/purchase-portal/login" style={linkStyle}>Back to Sign In</Link></div>
      </form>
    </AuthShell>
  )
}
