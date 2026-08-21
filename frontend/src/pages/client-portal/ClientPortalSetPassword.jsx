import { useState } from 'react'
import { useNavigate, useSearchParams, Link } from 'react-router-dom'
import { clientPortalApi } from '@/lib/clientPortalApi'
import { AuthShell, lbl, inp, primaryBtn, linkStyle, errStyle } from './ClientPortalLogin'

/**
 * Where a set-password or reset invitation lands.
 *
 * One screen for both, because from the contact's side they are the same act:
 * prove you own the mailbox, then choose a password. The backend shares a token
 * for the same reason.
 */
export default function ClientPortalSetPassword() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const token = params.get('token') || ''
  const [form, setForm] = useState({ password: '', password_confirmation: '' })
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  const tooShort = form.password.length > 0 && form.password.length < 8
  const mismatch = form.password_confirmation.length > 0 && form.password !== form.password_confirmation

  const submit = async (e) => {
    e.preventDefault()
    if (tooShort || mismatch) return
    setBusy(true); setErr('')
    try {
      await clientPortalApi.setPassword({ token, ...form })
      navigate('/portal/login', { replace: true })
    } catch (e) {
      setErr(e?.response?.data?.message || 'This link is not valid or has expired.')
    } finally {
      setBusy(false)
    }
  }

  if (!token) {
    return (
      <AuthShell title="Something is missing" subtitle="This link has no token">
        <div style={errStyle}>Open the link exactly as it appears in your email.</div>
        <div style={{ textAlign: 'center', fontSize: 12.5, marginTop: 14 }}>
          <Link to="/portal/login" style={linkStyle}>Back to sign in</Link>
        </div>
      </AuthShell>
    )
  }

  return (
    <AuthShell title="Choose a password" subtitle="At least 8 characters">
      <form onSubmit={submit} style={{ display: 'grid', gap: 12 }}>
        <div><label style={lbl}>New password</label>
          <input type="password" autoComplete="new-password" value={form.password} onChange={set('password')} style={inp} required />
          {tooShort && <p style={{ fontSize: 11.5, color: '#f59e0b', margin: '5px 0 0' }}>At least 8 characters.</p>}</div>
        <div><label style={lbl}>Confirm password</label>
          <input type="password" autoComplete="new-password" value={form.password_confirmation} onChange={set('password_confirmation')} style={inp} required />
          {mismatch && <p style={{ fontSize: 11.5, color: '#f59e0b', margin: '5px 0 0' }}>These do not match.</p>}</div>
        {err && <div style={errStyle}>{err}</div>}
        <button type="submit" disabled={busy || tooShort || mismatch} style={{ ...primaryBtn, opacity: (busy || tooShort || mismatch) ? 0.6 : 1 }}>
          {busy ? 'Saving…' : 'Set password'}
        </button>
      </form>
    </AuthShell>
  )
}
