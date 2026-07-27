import { useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { purchaseVendorAuthApi } from '@/services/purchaseVendorAuthApi'

/**
 * Purchase Vendor Portal login — authenticates ONLY a PurchaseVendor against
 * /api/purchase-vendor/login. Independent of the shared user/vendor/TPV login.
 */
export default function PurchaseVendorLogin() {
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true); setErr('')
    try {
      await purchaseVendorAuthApi.login(email, password)
      navigate('/purchase-portal/dashboard')
    } catch (e) { setErr(e?.response?.data?.message || 'Invalid credentials.') } finally { setBusy(false) }
  }

  return (
    <AuthShell title="Purchase Vendor Portal" subtitle="Sign in to your procurement account">
      <form onSubmit={submit} style={{ display: 'grid', gap: 12 }}>
        <div><label style={lbl}>Email</label><input type="email" value={email} onChange={(e) => setEmail(e.target.value)} style={inp} required /></div>
        <div><label style={lbl}>Password</label><input type="password" value={password} onChange={(e) => setPassword(e.target.value)} style={inp} required /></div>
        {err && <div style={errStyle}>{err}</div>}
        <button type="submit" disabled={busy} style={primaryBtn}>{busy ? 'Signing in…' : 'Sign In'}</button>
        <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12.5 }}>
          <Link to="/purchase-portal/forgot-password" style={linkStyle}>Forgot password?</Link>
          <Link to="/purchase-portal/register" style={linkStyle}>Create an account</Link>
        </div>
      </form>
    </AuthShell>
  )
}

export function AuthShell({ title, subtitle, children }) {
  return (
    <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'var(--bg-app, #0f1117)', padding: 20 }}>
      <div style={{ width: 400, maxWidth: '94vw', background: 'var(--bg-card, #171923)', border: '1px solid var(--border, #2a2f3a)', borderRadius: 14, padding: 28, boxShadow: '0 12px 40px rgba(0,0,0,0.35)' }}>
        <div style={{ textAlign: 'center', marginBottom: 20 }}>
          <div style={{ fontSize: 20, fontWeight: 800, color: 'var(--text-h, #fff)' }}>{title}</div>
          <div style={{ fontSize: 13, color: 'var(--text-muted, #9ca3af)', marginTop: 4 }}>{subtitle}</div>
        </div>
        {children}
      </div>
    </div>
  )
}

export const lbl = { display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--text-muted, #9ca3af)', marginBottom: 5 }
export const inp = { width: '100%', padding: '10px 12px', background: 'var(--bg-input, #0f1117)', border: '1px solid var(--border, #2a2f3a)', borderRadius: 8, color: 'var(--text-h, #fff)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }
export const primaryBtn = { width: '100%', padding: '11px', borderRadius: 8, background: '#7C3AED', color: '#fff', border: 'none', cursor: 'pointer', fontSize: 14, fontWeight: 700 }
export const linkStyle = { color: '#a78bfa', textDecoration: 'none', fontWeight: 600 }
export const errStyle = { color: '#ef4444', fontSize: 12.5, background: 'rgba(239,68,68,0.1)', padding: '8px 10px', borderRadius: 6 }
