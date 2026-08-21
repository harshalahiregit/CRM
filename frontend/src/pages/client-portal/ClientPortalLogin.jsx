import { useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { Building2 } from 'lucide-react'
import { clientPortalApi } from '@/lib/clientPortalApi'

/**
 * Customer portal sign-in.
 *
 * Shared shell and field styles are exported for the sibling screens, matching
 * how the purchase portal does it — one visual language, defined once.
 */
export function AuthShell({ title, subtitle, children }) {
  return (
    <div style={{ minHeight: '100vh', display: 'grid', placeItems: 'center', background: 'var(--bg-global, #0b0d12)', padding: 20 }}>
      <div style={{ width: '100%', maxWidth: 420 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 22 }}>
          <div style={{ width: 38, height: 38, borderRadius: 11, display: 'grid', placeItems: 'center', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
            <Building2 size={19} style={{ color: '#fff' }} />
          </div>
          <div>
            <div style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h,#fff)' }}>Customer Portal</div>
            <div style={{ fontSize: 11.5, color: 'var(--text-muted,#9ca3af)' }}>Your invoices, projects and support in one place</div>
          </div>
        </div>
        <div style={{ background: 'var(--bg-card,#12141b)', border: '1px solid var(--border,#2a2f3a)', borderRadius: 16, padding: 26 }}>
          <h1 style={{ fontSize: 19, fontWeight: 800, color: 'var(--text-h,#fff)', margin: '0 0 4px' }}>{title}</h1>
          {subtitle && <p style={{ fontSize: 12.5, color: 'var(--text-muted,#9ca3af)', margin: '0 0 18px' }}>{subtitle}</p>}
          {children}
        </div>
      </div>
    </div>
  )
}

export const lbl = { display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--text-muted,#9ca3af)', marginBottom: 5 }
export const inp = { width: '100%', padding: '10px 12px', background: 'var(--bg-input,#0f1117)', border: '1px solid var(--border,#2a2f3a)', borderRadius: 8, color: 'var(--text-h,#fff)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }
export const primaryBtn = { width: '100%', padding: '11px', borderRadius: 8, background: '#7C3AED', color: '#fff', border: 'none', cursor: 'pointer', fontSize: 14, fontWeight: 700 }
export const linkStyle = { color: '#a78bfa', textDecoration: 'none', fontWeight: 600 }
export const errStyle = { color: '#ef4444', fontSize: 12.5, background: 'rgba(239,68,68,0.1)', padding: '8px 10px', borderRadius: 6 }
export const okStyle = { color: '#10b981', fontSize: 12.5, background: 'rgba(16,185,129,0.1)', padding: '8px 10px', borderRadius: 6 }

export default function ClientPortalLogin() {
  const navigate = useNavigate()
  const [form, setForm] = useState({ email: '', password: '' })
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true); setErr('')
    try {
      await clientPortalApi.login(form.email, form.password)
      navigate('/portal/dashboard', { replace: true })
    } catch (e) {
      setErr(e?.response?.data?.message || 'Those details do not match our records.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthShell title="Sign in" subtitle="Use the email address we hold for you">
      <form onSubmit={submit} style={{ display: 'grid', gap: 12 }}>
        <div><label style={lbl}>Email</label>
          <input type="email" autoComplete="username" value={form.email} onChange={set('email')} style={inp} required /></div>
        <div><label style={lbl}>Password</label>
          <input type="password" autoComplete="current-password" value={form.password} onChange={set('password')} style={inp} required /></div>
        {err && <div style={errStyle}>{err}</div>}
        <button type="submit" disabled={busy} style={{ ...primaryBtn, opacity: busy ? 0.6 : 1 }}>
          {busy ? 'Signing in…' : 'Sign in'}
        </button>
        <div style={{ textAlign: 'center', fontSize: 12.5 }}>
          <Link to="/portal/forgot-password" style={linkStyle}>Forgotten your password?</Link>
        </div>
        <p style={{ fontSize: 11.5, color: 'var(--text-muted,#9ca3af)', textAlign: 'center', margin: '4px 0 0' }}>
          Portal access is set up by your account manager. If you do not have it yet, ask them to send you an invitation.
        </p>
      </form>
    </AuthShell>
  )
}
