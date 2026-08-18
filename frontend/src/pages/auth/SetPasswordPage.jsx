import { useState } from 'react'
import { useSearchParams, useNavigate, Link } from 'react-router-dom'
import { KeyRound, Loader2, CheckCircle2, AlertTriangle, Eye, EyeOff } from 'lucide-react'
import api from '@/lib/api'

/**
 * Where a vendor login-link email lands.
 *
 * The token and email arrive in the query string; the visitor has no session, so
 * this route is public. Nothing here decides whether the link is good — the
 * server does, and it answers with one message for every failure so this page
 * cannot be used to probe which addresses have a pending invitation.
 */
export default function SetPasswordPage() {
  const [params] = useSearchParams()
  const navigate = useNavigate()

  const token = params.get('token') || ''
  const email = params.get('email') || ''

  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [show, setShow] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)
  const [done, setDone] = useState(false)

  const submit = async (e) => {
    e.preventDefault()
    if (password.length < 8) return setErr('Password must be at least 8 characters.')
    if (password !== confirm) return setErr('The two passwords do not match.')

    setBusy(true); setErr(null)
    try {
      await api.post('/auth/set-password', {
        email, token, password, password_confirmation: confirm,
      })
      setDone(true)
      // Straight to login rather than signing them in here: the token is spent,
      // and logging in proves the password they just chose actually works.
      setTimeout(() => navigate('/auth/login', { replace: true }), 1800)
    } catch (e2) {
      setErr(e2?.response?.data?.message || 'Could not set the password. The link may have expired.')
      setBusy(false)
    }
  }

  const card = {
    width: 'min(420px, 94vw)', background: 'var(--bg-card)', border: '1px solid var(--border)',
    borderRadius: 18, padding: '28px 26px', boxShadow: '0 20px 60px rgba(0,0,0,0.18)',
  }
  const input = {
    width: '100%', padding: '10px 38px 10px 12px', borderRadius: 12, fontSize: 13.5,
    background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', outline: 'none',
  }

  // A link with no token is not a form to fill in — say so instead of showing
  // fields that cannot possibly submit.
  if (!token || !email) {
    return (
      <div style={{ minHeight: '100vh', display: 'grid', placeItems: 'center', background: 'var(--bg-global)' }}>
        <div style={card}>
          <AlertTriangle size={26} style={{ color: '#f59e0b' }} />
          <h1 style={{ fontSize: 18, fontWeight: 900, color: 'var(--text-h)', margin: '12px 0 6px' }}>Link incomplete</h1>
          <p style={{ fontSize: 13, color: 'var(--text-muted)', margin: 0 }}>
            This page needs the full link from your email. Open it directly from the message, or ask for a new one.
          </p>
          <Link to="/auth/login" style={{ display: 'inline-block', marginTop: 16, fontSize: 12.5, fontWeight: 700, color: '#a78bfa' }}>
            Back to sign in
          </Link>
        </div>
      </div>
    )
  }

  return (
    <div style={{ minHeight: '100vh', display: 'grid', placeItems: 'center', background: 'var(--bg-global)' }}>
      <div style={card}>
        {done ? (
          <>
            <CheckCircle2 size={26} style={{ color: '#10b981' }} />
            <h1 style={{ fontSize: 18, fontWeight: 900, color: 'var(--text-h)', margin: '12px 0 6px' }}>Password set</h1>
            <p style={{ fontSize: 13, color: 'var(--text-muted)', margin: 0 }}>Taking you to sign in…</p>
          </>
        ) : (
          <form onSubmit={submit}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 4 }}>
              <div style={{ width: 38, height: 38, borderRadius: 12, display: 'grid', placeItems: 'center', background: 'rgba(124,58,237,0.12)' }}>
                <KeyRound size={18} style={{ color: '#a78bfa' }} />
              </div>
              <h1 style={{ fontSize: 18, fontWeight: 900, color: 'var(--text-h)', margin: 0 }}>Set your password</h1>
            </div>
            <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '4px 0 18px' }}>
              for <b style={{ color: 'var(--text-h)' }}>{email}</b>
            </p>

            <label style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>New password</label>
            <div style={{ position: 'relative', margin: '6px 0 14px' }}>
              <input type={show ? 'text' : 'password'} value={password} autoFocus
                onChange={e => setPassword(e.target.value)} style={input} placeholder="At least 8 characters" />
              <button type="button" onClick={() => setShow(s => !s)} aria-label={show ? 'Hide password' : 'Show password'}
                style={{ position: 'absolute', right: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)', background: 'none', border: 'none', cursor: 'pointer' }}>
                {show ? <EyeOff size={15} /> : <Eye size={15} />}
              </button>
            </div>

            <label style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>Confirm password</label>
            <div style={{ margin: '6px 0 4px' }}>
              <input type={show ? 'text' : 'password'} value={confirm}
                onChange={e => setConfirm(e.target.value)} style={input} placeholder="Repeat it" />
            </div>

            {err && <p style={{ fontSize: 12.5, color: '#ef4444', margin: '10px 0 0' }}>{err}</p>}

            <button type="submit" disabled={busy}
              style={{ width: '100%', marginTop: 18, padding: '11px 0', borderRadius: 12, border: 'none', cursor: busy ? 'default' : 'pointer',
                background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 800, fontSize: 13.5,
                display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 8, opacity: busy ? 0.7 : 1 }}>
              {busy && <Loader2 size={15} className="animate-spin" />} Set password
            </button>

            <p style={{ fontSize: 11, color: 'var(--text-muted)', margin: '12px 0 0', textAlign: 'center' }}>
              This link works once and expires. Signing in elsewhere will end after you set a new password.
            </p>
          </form>
        )}
      </div>
    </div>
  )
}
