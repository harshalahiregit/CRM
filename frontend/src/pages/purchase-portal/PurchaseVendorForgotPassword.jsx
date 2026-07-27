import { useState } from 'react'
import { Link } from 'react-router-dom'
import { purchaseVendorAuthApi } from '@/services/purchaseVendorAuthApi'
import { AuthShell, lbl, inp, primaryBtn, linkStyle } from './PurchaseVendorLogin'

/** Purchase Vendor forgot-password — Purchase-owned reset flow. */
export default function PurchaseVendorForgotPassword() {
  const [email, setEmail] = useState('')
  const [done, setDone] = useState(false)
  const [busy, setBusy] = useState(false)

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true)
    try { await purchaseVendorAuthApi.forgotPassword(email) } catch { /* never reveal existence */ } finally { setBusy(false); setDone(true) }
  }

  return (
    <AuthShell title="Reset your password" subtitle="We'll email you a reset link">
      {done ? (
        <>
          <p style={{ color: 'var(--text-muted, #9ca3af)', fontSize: 13, textAlign: 'center' }}>If that email is registered, a reset link has been sent.</p>
          <Link to="/purchase-portal/login" style={{ ...primaryBtn, display: 'block', textAlign: 'center', textDecoration: 'none', marginTop: 12 }}>Back to Sign In</Link>
        </>
      ) : (
        <form onSubmit={submit} style={{ display: 'grid', gap: 12 }}>
          <div><label style={lbl}>Email</label><input type="email" value={email} onChange={(e) => setEmail(e.target.value)} style={inp} required /></div>
          <button type="submit" disabled={busy} style={primaryBtn}>{busy ? 'Sending…' : 'Send reset link'}</button>
          <div style={{ textAlign: 'center', fontSize: 12.5 }}><Link to="/purchase-portal/login" style={linkStyle}>Back to Sign In</Link></div>
        </form>
      )}
    </AuthShell>
  )
}
