import { useState, useEffect } from 'react'
import { clientPortalApi } from '@/lib/clientPortalApi'
import { lbl, inp, primaryBtn, errStyle, okStyle } from './ClientPortalLogin'

/**
 * The contact's own details and password.
 *
 * Email is shown read-only on purpose: it is the login identifier, and letting
 * someone move their own account to a different mailbox is an account-takeover
 * route. Changing it is a request to the account manager.
 */
export default function ClientPortalProfile() {
  const [form, setForm] = useState(null)
  const [msg, setMsg] = useState(''); const [err, setErr] = useState(''); const [busy, setBusy] = useState(false)
  const [pw, setPw] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [pwMsg, setPwMsg] = useState(''); const [pwErr, setPwErr] = useState(''); const [pwBusy, setPwBusy] = useState(false)

  useEffect(() => {
    clientPortalApi.me().then(m => setForm({
      first_name: m.first_name || '', last_name: m.last_name || '',
      phone: m.phone || '', title: m.title || '', email: m.email || '', company: m.company || '',
    })).catch(() => setErr('Could not load your profile.'))
  }, [])

  const sf = (k) => (e) => setForm(f => ({ ...f, [k]: e.target.value }))

  const save = async (e) => {
    e.preventDefault(); setBusy(true); setMsg(''); setErr('')
    try {
      const { first_name, last_name, phone, title } = form
      await clientPortalApi.updateProfile({ first_name, last_name, phone, title })
      setMsg('Your details have been saved.')
    } catch (e) { setErr(e?.response?.data?.message || 'Could not save your details.') }
    finally { setBusy(false) }
  }

  const changePassword = async (e) => {
    e.preventDefault(); setPwBusy(true); setPwMsg(''); setPwErr('')
    try {
      await clientPortalApi.changePassword(pw)
      setPwMsg('Your password has been changed.')
      setPw({ current_password: '', password: '', password_confirmation: '' })
    } catch (e) { setPwErr(e?.response?.data?.message || 'Could not change your password.') }
    finally { setPwBusy(false) }
  }

  if (!form) return <div style={{ color: 'var(--text-muted,#9ca3af)', fontSize: 13 }}>Loading…</div>

  const card = { background: 'var(--bg-card,#12141b)', border: '1px solid var(--border,#2a2f3a)', borderRadius: 14, padding: 22, maxWidth: 560 }

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <h1 style={{ fontSize: 21, fontWeight: 800, color: 'var(--text-h,#fff)', margin: 0 }}>My Profile</h1>

      <form onSubmit={save} style={{ ...card, display: 'grid', gap: 12 }}>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <div><label style={lbl}>First name</label><input style={inp} value={form.first_name} onChange={sf('first_name')} required /></div>
          <div><label style={lbl}>Last name</label><input style={inp} value={form.last_name} onChange={sf('last_name')} /></div>
        </div>
        <div><label style={lbl}>Designation</label><input style={inp} value={form.title} onChange={sf('title')} /></div>
        <div><label style={lbl}>Phone</label><input style={inp} value={form.phone} onChange={sf('phone')} /></div>
        <div><label style={lbl}>Email</label>
          <input style={{ ...inp, opacity: 0.6 }} value={form.email} readOnly />
          <p style={{ fontSize: 11.5, color: 'var(--text-muted,#9ca3af)', margin: '5px 0 0' }}>
            This is your sign-in address. Ask your account manager to change it.
          </p></div>
        {msg && <div style={okStyle}>{msg}</div>}
        {err && <div style={errStyle}>{err}</div>}
        <button type="submit" disabled={busy} style={{ ...primaryBtn, opacity: busy ? 0.6 : 1 }}>{busy ? 'Saving…' : 'Save details'}</button>
      </form>

      <form onSubmit={changePassword} style={{ ...card, display: 'grid', gap: 12 }}>
        <div style={{ fontSize: 14, fontWeight: 700, color: 'var(--text-h,#fff)' }}>Change password</div>
        <div><label style={lbl}>Current password</label>
          <input type="password" autoComplete="current-password" style={inp} value={pw.current_password} onChange={e => setPw(p => ({ ...p, current_password: e.target.value }))} required /></div>
        <div><label style={lbl}>New password</label>
          <input type="password" autoComplete="new-password" style={inp} value={pw.password} onChange={e => setPw(p => ({ ...p, password: e.target.value }))} required minLength={8} /></div>
        <div><label style={lbl}>Confirm new password</label>
          <input type="password" autoComplete="new-password" style={inp} value={pw.password_confirmation} onChange={e => setPw(p => ({ ...p, password_confirmation: e.target.value }))} required /></div>
        {pwMsg && <div style={okStyle}>{pwMsg}</div>}
        {pwErr && <div style={errStyle}>{pwErr}</div>}
        <button type="submit" disabled={pwBusy} style={{ ...primaryBtn, opacity: pwBusy ? 0.6 : 1 }}>{pwBusy ? 'Changing…' : 'Change password'}</button>
      </form>
    </div>
  )
}
