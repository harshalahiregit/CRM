import { useState, useEffect, useRef } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { purchaseVendorAuthApi } from '@/services/purchaseVendorAuthApi'
import { AuthShell, primaryBtn, linkStyle, errStyle } from './PurchaseVendorLogin'

/**
 * Where the registration verification link lands.
 *
 * Previously this route rendered a ComingSoon placeholder, so even once the
 * mail existed the link went nowhere. Verifies on mount from ?token=.
 */
export default function PurchaseVendorVerifyEmail() {
  const [params] = useSearchParams()
  const token = params.get('token') || ''
  const [state, setState] = useState(token ? 'verifying' : 'missing')
  const [err, setErr] = useState('')
  // StrictMode mounts effects twice in dev; the token is single-use, so a
  // second POST would report "already used" on a verification that worked.
  const fired = useRef(false)

  useEffect(() => {
    if (!token || fired.current) return
    fired.current = true
    purchaseVendorAuthApi.verifyEmail(token)
      .then(() => setState('done'))
      .catch((e) => {
        setErr(e?.response?.data?.message || 'This link is not valid or has already been used.')
        setState('failed')
      })
  }, [token])

  const body = {
    verifying: <p style={{ fontSize: 13.5, color: 'var(--text-muted)', margin: 0 }}>Confirming your email address…</p>,
    done: (
      <>
        <p style={{ fontSize: 13.5, color: 'var(--text-muted)', margin: '0 0 14px' }}>
          Your email is confirmed. You can sign in once an administrator activates your account.
        </p>
        <Link to="/purchase-portal/login" style={{ ...primaryBtn, display: 'block', textAlign: 'center', textDecoration: 'none' }}>
          Go to Sign In
        </Link>
      </>
    ),
    failed: (
      <>
        <div style={errStyle}>{err}</div>
        <div style={{ textAlign: 'center', fontSize: 12.5, marginTop: 12 }}>
          <Link to="/purchase-portal/login" style={linkStyle}>Back to Sign In</Link>
        </div>
      </>
    ),
    missing: (
      <>
        <div style={errStyle}>This link is missing its verification token. Open the link from your email exactly as sent.</div>
        <div style={{ textAlign: 'center', fontSize: 12.5, marginTop: 12 }}>
          <Link to="/purchase-portal/login" style={linkStyle}>Back to Sign In</Link>
        </div>
      </>
    ),
  }[state]

  return (
    <AuthShell
      title={state === 'done' ? 'Email confirmed' : 'Email verification'}
      subtitle={state === 'done' ? 'Thanks — that address is now confirmed' : 'Confirming the address you registered with'}
    >
      {body}
    </AuthShell>
  )
}
