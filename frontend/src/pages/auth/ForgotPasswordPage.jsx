import { useState } from 'react'
import { Link } from 'react-router-dom'
import { KeyRound, Loader2, MailCheck, ArrowLeft } from 'lucide-react'
import api from '@/lib/api'

/**
 * Request a password reset link.
 *
 * Deliberately shares the token pipeline with the vendor login-link action:
 * POST /auth/forgot-password mints a token into password_reset_tokens and emails
 * the same /auth/set-password link, which SetPasswordPage already handles. No
 * second reset flow to keep in step.
 *
 * The success state is shown for ANY syntactically valid address, matching the
 * server's deliberately identical response — telling the visitor "no such
 * account" would turn this page into a way to test which emails are registered.
 */
export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [busy, setBusy]   = useState(false)
  const [sent, setSent]   = useState(false)
  const [err, setErr]     = useState(null)

  const submit = async (e) => {
    e.preventDefault()
    if (!email.trim()) return setErr('Enter the email address for your account.')

    setBusy(true); setErr(null)
    try {
      await api.post('/auth/forgot-password', { email: email.trim() })
      setSent(true)
    } catch (e2) {
      // 422 is a malformed address; 429 is the rate limiter. Anything else is
      // reported plainly rather than pretending the mail went out.
      setErr(e2?.response?.status === 429
        ? 'Too many requests. Wait a minute and try again.'
        : (e2?.response?.data?.message || 'Could not send the reset link. Try again.'))
    } finally { setBusy(false) }
  }

  const card = {
    width: 'min(420px, 94vw)', background: 'var(--bg-card)', border: '1px solid var(--border)',
    borderRadius: 18, padding: '28px 26px', boxShadow: '0 20px 60px rgba(0,0,0,0.18)',
  }

  return (
    <div style={{ minHeight: '100vh', display: 'grid', placeItems: 'center', background: 'var(--bg-global)' }}>
      <div style={card}>
        {sent ? (
          <>
            <MailCheck size={26} style={{ color: '#10b981' }} />
            <h1 style={{ fontSize: 18, fontWeight: 900, color: 'var(--text-h)', margin: '12px 0 6px' }}>Check your email</h1>
            <p style={{ fontSize: 13, color: 'var(--text-muted)', margin: 0, lineHeight: 1.55 }}>
              If <b style={{ color: 'var(--text-h)' }}>{email.trim()}</b> is registered, a reset link is on its way.
              It can be used once and expires in 60 minutes.
            </p>
            <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '12px 0 0' }}>
              Didn’t get it? Check spam, or{' '}
              <button onClick={() => { setSent(false); setErr(null) }}
                style={{ background: 'none', border: 'none', padding: 0, cursor: 'pointer', color: '#a78bfa', fontWeight: 700, fontSize: 12 }}>
                try again
              </button>.
            </p>
            <Link to="/auth/login" style={{ display: 'inline-flex', alignItems: 'center', gap: 6, marginTop: 18, fontSize: 12.5, fontWeight: 700, color: '#a78bfa' }}>
              <ArrowLeft size={13} /> Back to sign in
            </Link>
          </>
        ) : (
          <form onSubmit={submit}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 4 }}>
              <div style={{ width: 38, height: 38, borderRadius: 12, display: 'grid', placeItems: 'center', background: 'rgba(124,58,237,0.12)' }}>
                <KeyRound size={18} style={{ color: '#a78bfa' }} />
              </div>
              <h1 style={{ fontSize: 18, fontWeight: 900, color: 'var(--text-h)', margin: 0 }}>Forgot password</h1>
            </div>
            <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '4px 0 18px' }}>
              We’ll email you a link to set a new one.
            </p>

            <label style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
              Email address
            </label>
            <input
              type="email" value={email} autoFocus autoComplete="email"
              onChange={e => setEmail(e.target.value)}
              placeholder="you@company.com"
              style={{
                width: '100%', margin: '6px 0 4px', padding: '10px 12px', borderRadius: 12, fontSize: 13.5,
                background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', outline: 'none',
              }}
            />

            {err && <p style={{ fontSize: 12.5, color: '#ef4444', margin: '10px 0 0' }}>{err}</p>}

            <button type="submit" disabled={busy}
              style={{ width: '100%', marginTop: 18, padding: '11px 0', borderRadius: 12, border: 'none', cursor: busy ? 'default' : 'pointer',
                background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 800, fontSize: 13.5,
                display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 8, opacity: busy ? 0.7 : 1 }}>
              {busy && <Loader2 size={15} className="animate-spin" />} Send reset link
            </button>

            <Link to="/auth/login" style={{ display: 'inline-flex', alignItems: 'center', gap: 6, marginTop: 14, fontSize: 12.5, fontWeight: 700, color: '#a78bfa' }}>
              <ArrowLeft size={13} /> Back to sign in
            </Link>
          </form>
        )}
      </div>
    </div>
  )
}
