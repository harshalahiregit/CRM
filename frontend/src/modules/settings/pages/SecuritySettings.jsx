import { useState, useEffect } from 'react'
import { Save } from 'lucide-react'
import { settingsApi } from '@/services/settingsApi'
import { useToast } from '@/hooks/useToast'

// Keys mirror the backend SettingRegistry 'security' group. Settings only — this
// page stores configuration; authentication behaviour is unchanged for now.
const TOGGLES = [
  ['require_uppercase', 'Require uppercase letter'],
  ['require_lowercase', 'Require lowercase letter'],
  ['require_numbers', 'Require a number'],
  ['require_special', 'Require a special character'],
  ['force_logout_after_password_change', 'Force logout after password change'],
  ['single_session_only', 'Allow only a single active session'],
  ['two_factor_enabled', 'Two-factor authentication — not enforced yet'],
]
const NUMS = [
  ['password_min_length', 'Password minimum length'],
  ['password_expiry_days', 'Password expiry (days, 0 = never)'],
  ['failed_login_lockout', 'Failed logins before lockout (0 = off)'],
  ['lockout_duration_minutes', 'Lockout duration (minutes)'],
  ['session_timeout_minutes', 'Session timeout (minutes) — not enforced yet'],
  ['remember_me_days', 'Remember-me duration (days)'],
  ['api_token_expiry_days', 'API token expiry (days, 0 = never)'],
]

export default function SecuritySettings() {
  const toast = useToast()
  const [values, setValues] = useState(null)
  const [saving, setSaving] = useState(false)

  const sv = (k, v) => setValues(p => ({ ...p, [k]: v }))

  useEffect(() => {
    settingsApi.group.get('security').then(d => setValues(d?.values || {})).catch(e => toast.error(e.message))
  }, [])

  const save = async () => {
    setSaving(true)
    try {
      const d = await settingsApi.group.update('security', values)
      setValues(d?.values || {})
      toast.success('Security settings saved')
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  if (!values) return <div className="card-3d"><div className="skeleton h-40 rounded-xl" style={{ background: 'var(--border)' }} /></div>

  return (
    <div className="space-y-4">
      <div className="card-3d">
        <h2 className="font-bold text-base mb-1" style={{ color: 'var(--text-h)' }}>Password Policy</h2>
        <p className="text-xs mb-5" style={{ color: 'var(--text-muted)' }}>Complexity and expiry rules (applied when authentication enforcement lands).</p>
        <div className="grid md:grid-cols-2 gap-3 mb-4">
          {TOGGLES.slice(0, 4).map(([k, label]) => (
            <label key={k} className="flex items-center gap-2 text-sm font-semibold cursor-pointer" style={{ color: 'var(--text-body)' }}>
              <input type="checkbox" checked={!!values[k]} onChange={e => sv(k, e.target.checked)} /> {label}
            </label>
          ))}
        </div>
        <div className="grid md:grid-cols-2 gap-4">
          {NUMS.slice(0, 2).map(([k, label]) => (
            <div key={k}><label className="label">{label}</label>
              <input type="number" min="0" className="input-3d text-sm" value={values[k] ?? ''} onChange={e => sv(k, e.target.value === '' ? '' : Number(e.target.value))} /></div>
          ))}
        </div>
      </div>

      <div className="card-3d">
        <h2 className="font-bold text-base mb-1" style={{ color: 'var(--text-h)' }}>Sessions & Lockout</h2>
        {/* This card used to carry no caveat at all while none of it was wired,
            so an admin got a success toast and an unchanged security posture.
            Lockout and single-session are enforced now; the two that are not are
            marked on their own labels rather than left looking identical. */}
        <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
          Lockout and single-session are enforced at sign-in. Items marked
          “not enforced yet” are stored for when that feature lands.
        </p>
        <div className="grid md:grid-cols-2 gap-4 mb-4">
          {NUMS.slice(2).map(([k, label]) => (
            <div key={k}><label className="label">{label}</label>
              <input type="number" min="0" className="input-3d text-sm" value={values[k] ?? ''} onChange={e => sv(k, e.target.value === '' ? '' : Number(e.target.value))} /></div>
          ))}
        </div>
        <div className="grid md:grid-cols-2 gap-3">
          {TOGGLES.slice(4).map(([k, label]) => (
            <label key={k} className="flex items-center gap-2 text-sm font-semibold cursor-pointer" style={{ color: 'var(--text-body)' }}>
              <input type="checkbox" checked={!!values[k]} onChange={e => sv(k, e.target.checked)} /> {label}
            </label>
          ))}
        </div>
      </div>

      <div className="card-3d">
        <h2 className="font-bold text-base mb-1" style={{ color: 'var(--text-h)' }}>Access Control</h2>
        <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>Placeholders — stored now, enforced later. Comma or newline separated.</p>
        <div className="grid md:grid-cols-2 gap-4">
          <div><label className="label">IP whitelist</label>
            <textarea rows={3} className="input-3d text-sm" value={values.ip_whitelist ?? ''} onChange={e => sv('ip_whitelist', e.target.value)} placeholder="203.0.113.4, 198.51.100.0/24" /></div>
          <div><label className="label">Trusted domains</label>
            <textarea rows={3} className="input-3d text-sm" value={values.trusted_domains ?? ''} onChange={e => sv('trusted_domains', e.target.value)} placeholder="yourcompany.in, partner.com" /></div>
        </div>
      </div>

      <div className="flex justify-end">
        <button onClick={save} disabled={saving} className="btn-3d flex items-center gap-2 disabled:opacity-60"><Save size={14} /> {saving ? 'Saving…' : 'Save Settings'}</button>
      </div>
    </div>
  )
}
