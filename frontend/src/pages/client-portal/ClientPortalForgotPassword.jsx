import { useState } from 'react'
import { Link } from 'react-router-dom'
import { clientPortalApi } from '@/lib/clientPortalApi'
import { AuthShell, lbl, inp, primaryBtn, linkStyle, okStyle } from './ClientPortalLogin'

/**
 * Forgotten password.
 *
 * The confirmation is deliberately the same whether or not the address exists —
 * the server answers identically, and saying "no such account" here would turn
 * a public page into a way to enumerate customers.
 */
export default function ClientPortalForgotPassword() {
  const [email, setEmail] = useState('')
  const [sent, setSent] = useState(false)
  const [busy, setBusy] = useState(false)

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true)
    try { await clientPortalApi.forgotPassword(email) } catch { /* answer is the same either way */ }
    setSent(true); setBusy(false)
  }

  return (
    <AuthShell title="Reset your password" subtitle="We will email you a link">
      {sent ? (
        <>
          <div style={okStyle}>If that email has portal access, a reset link is on its way.</div>
          <div style={{ textAlign: 'center', fontSize: 12.5, marginTop: 14 }}>
            <Link to="/portal/login" style={linkStyle}>Back to sign in</Link>
          </div>
        </>
      ) : (
        <form onSubmit={submit} style={{ display: 'grid', gap: 12 }}>
          <div><label style={lbl}>Email</label>
            <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} style={inp} required /></div>
          <button type="submit" disabled={busy} style={{ ...primaryBtn, opacity: busy ? 0.6 : 1 }}>
            {busy ? 'Sending…' : 'Send reset link'}
          </button>
          <div style={{ textAlign: 'center', fontSize: 12.5 }}>
            <Link to="/portal/login" style={linkStyle}>Back to sign in</Link>
          </div>
        </form>
      )}
    </AuthShell>
  )
}
