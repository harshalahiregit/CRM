import { useState, useEffect } from 'react'
import { Send, Save } from 'lucide-react'
import { settingsApi } from '@/services/settingsApi'
import { useToast } from '@/hooks/useToast'
import { useAuth } from '@/context/AuthContext'

const BLANK = { host: '', port: 587, username: '', password: '', encryption: 'tls', from_name: '', from_email: '', reply_to: '', enabled: false, verify_peer: true }

export default function MailSettings() {
  const toast = useToast()
  const { user } = useAuth()
  const [form, setForm] = useState(BLANK)
  const [hasPassword, setHasPassword] = useState(false)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [testing, setTesting] = useState(false)
  const [testTo, setTestTo] = useState('')

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  useEffect(() => {
    settingsApi.mail.get()
      .then(d => {
        setForm({ ...BLANK, ...Object.fromEntries(Object.entries(d).filter(([k]) => k in BLANK)), password: '' })
        setHasPassword(!!d.has_password)
        setTestTo(user?.email || '')
      })
      .catch(e => toast.error(e.message))
      .finally(() => setLoading(false))
  }, [])

  const save = async () => {
    setSaving(true)
    try {
      const d = await settingsApi.mail.update(form)
      setHasPassword(!!d.has_password)
      sf('password', '')
      toast.success('Email settings saved')
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  const sendTest = async () => {
    if (!testTo) return toast.error('Enter an address to send the test to')
    setTesting(true)
    try {
      const r = await settingsApi.mail.test(testTo)
      toast.success(r.message)
    } catch (e) { toast.error(e.message) } finally { setTesting(false) }
  }

  if (loading) return <div className="card-3d"><div className="skeleton h-40 rounded-xl" style={{ background: 'var(--border)' }} /></div>

  return (
    <div className="space-y-4">
      <div className="card-3d">
        <div className="flex items-start justify-between mb-5">
          <div>
            <h2 className="font-bold text-base" style={{ color: 'var(--text-h)' }}>Outgoing email (SMTP)</h2>
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
              Emails from this workspace (proposals, OTP codes, contracts) are sent using this
              configuration. When disabled or empty, the platform default sender is used.
            </p>
          </div>
          <label className="flex items-center gap-2 text-xs font-bold cursor-pointer" style={{ color: form.enabled ? '#10b981' : 'var(--text-muted)' }}>
            <input type="checkbox" checked={form.enabled} onChange={e => sf('enabled', e.target.checked)} />
            {form.enabled ? 'Enabled' : 'Disabled'}
          </label>
        </div>

        <div className="grid md:grid-cols-2 gap-4">
          <div><label className="label">SMTP Host</label><input className="input-3d text-sm" placeholder="smtp.gmail.com" value={form.host || ''} onChange={e => sf('host', e.target.value)} /></div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Port</label><input type="number" className="input-3d text-sm" value={form.port || ''} onChange={e => sf('port', e.target.value)} /></div>
            <div><label className="label">Encryption</label><select className="input-3d text-sm" value={form.encryption} onChange={e => sf('encryption', e.target.value)}><option value="tls">TLS</option><option value="ssl">SSL</option><option value="none">None</option></select></div>
          </div>
          <div><label className="label">Username</label><input className="input-3d text-sm" autoComplete="off" value={form.username || ''} onChange={e => sf('username', e.target.value)} /></div>
          <div><label className="label">Password {hasPassword && <span style={{ color: 'var(--text-faint)' }}>(saved — leave blank to keep)</span>}</label><input type="password" autoComplete="new-password" className="input-3d text-sm" placeholder={hasPassword ? '••••••••' : ''} value={form.password} onChange={e => sf('password', e.target.value)} /></div>
          <div><label className="label">From Name</label><input className="input-3d text-sm" placeholder="MLA Consulting" value={form.from_name || ''} onChange={e => sf('from_name', e.target.value)} /></div>
          <div><label className="label">From Email</label><input className="input-3d text-sm" placeholder="crm@yourcompany.in" value={form.from_email || ''} onChange={e => sf('from_email', e.target.value)} /></div>
          <div><label className="label">Reply-To (optional)</label><input className="input-3d text-sm" value={form.reply_to || ''} onChange={e => sf('reply_to', e.target.value)} /></div>
        </div>

        {/* Panel-managed mail servers (Plesk/cPanel) commonly present a self-signed
            or mismatched certificate, which makes STARTTLS fail no matter which
            hostname is used. Unticking this skips that check. */}
        <div className="mt-4">
          <label className="flex items-start gap-2 text-xs cursor-pointer" style={{ color: 'var(--text-muted)' }}>
            <input type="checkbox" className="mt-0.5" checked={form.verify_peer !== false}
              onChange={e => sf('verify_peer', e.target.checked)} />
            <span>
              <span className="font-bold" style={{ color: 'var(--text-h)' }}>Verify TLS certificate</span>
              <br />
              Untick if you get <em>"certificate verify failed"</em> — common on Plesk/cPanel mail servers
              with a self-signed certificate. The connection stays encrypted; only the identity check is skipped.
            </span>
          </label>
        </div>

        <div className="flex justify-end mt-5">
          <button onClick={save} disabled={saving} className="btn-3d flex items-center gap-2 disabled:opacity-60"><Save size={14} /> {saving ? 'Saving…' : 'Save Settings'}</button>
        </div>
      </div>

      <div className="card-3d">
        <h3 className="font-bold text-sm mb-1" style={{ color: 'var(--text-h)' }}>Send a test email</h3>
        <p className="text-xs mb-3" style={{ color: 'var(--text-muted)' }}>Uses the saved configuration above (save first). Errors are shown as-is so you can fix host/credentials.</p>
        <div className="flex gap-2">
          <input className="input-3d text-sm flex-1" placeholder="you@example.com" value={testTo} onChange={e => setTestTo(e.target.value)} />
          <button onClick={sendTest} disabled={testing} className="px-4 py-2 rounded-xl text-sm font-bold flex items-center gap-2 disabled:opacity-60" style={{ background: 'rgba(124,58,237,0.1)', color: 'var(--accent)', border: '1px solid var(--border-purple)' }}>
            <Send size={13} /> {testing ? 'Sending…' : 'Send Test'}
          </button>
        </div>
      </div>

      <StaffEmailSwitches toast={toast} />
    </div>
  )
}

// Master "disable all emails" switch per staff member (client contacts get
// their switch inside the contact form on the customer profile).
function StaffEmailSwitches({ toast }) {
  const [staff, setStaff] = useState(null)

  useEffect(() => {
    settingsApi.staffEmails.list().then(setStaff).catch(e => toast.error(e.message))
  }, [])

  const toggle = async (u) => {
    setStaff(p => p.map(x => x.id === u.id ? { ...x, emails_enabled: !x.emails_enabled } : x))
    try { await settingsApi.staffEmails.toggle(u.id) }
    catch (e) {
      setStaff(p => p.map(x => x.id === u.id ? { ...x, emails_enabled: u.emails_enabled } : x))
      toast.error(e.message)
    }
  }

  return (
    <div className="card-3d">
      <h3 className="font-bold text-sm mb-1" style={{ color: 'var(--text-h)' }}>Staff email notifications</h3>
      <p className="text-xs mb-3" style={{ color: 'var(--text-muted)' }}>Master switch per staff member — off stops ALL system emails to that person.</p>
      {!staff ? (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Loading…</p>
      ) : !staff.length ? (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No staff users found.</p>
      ) : (
        <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
          {staff.map(u => (
            <div key={u.id} className="flex items-center justify-between py-2.5">
              <div>
                <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{u.name}</p>
                <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{u.email}{u.role ? ` · ${u.role}` : ''}</p>
              </div>
              <label className="flex items-center gap-2 text-[11px] font-bold cursor-pointer" style={{ color: u.emails_enabled ? '#10b981' : 'var(--text-muted)' }}>
                {u.emails_enabled ? 'Emails on' : 'Emails off'}
                <input type="checkbox" checked={!!u.emails_enabled} onChange={() => toggle(u)} />
              </label>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
