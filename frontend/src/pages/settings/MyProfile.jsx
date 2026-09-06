import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { User, Lock, Save, ShieldCheck, ArrowUpRight } from 'lucide-react'
import api from '@/lib/api'
import { useToast } from '@/hooks/useToast'
import { useAuth } from '@/context/AuthContext'

/**
 * The signed-in user's own profile.
 *
 * The global user menu has always offered "My Profile" and it navigated to
 * /app/settings/profile — a route that does not exist. Every user, on every
 * screen, got a 404 from the most obvious item in the header.
 *
 * Deliberately narrow. You may correct how you appear to colleagues and change
 * your own password. Role, department assignment, status and access expiry are
 * on the same record and are somebody else's decision; the server refuses them
 * regardless of what this sends.
 */
export default function MyProfile() {
  const toast = useToast()
  const navigate = useNavigate()
  const { user, setUser } = useAuth() ?? {}

  const [form, setForm] = useState({ name: '', phone: '', designation: '', department: '', emails_enabled: true, mail_from_name: '', mail_from_email: '' })
  const [pw, setPw] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [saving, setSaving] = useState(false)
  const [changing, setChanging] = useState(false)
  const [me, setMe] = useState(null)

  useEffect(() => {
    api.get('/auth/me').then((r) => {
      const u = r.data?.data?.user ?? r.data?.user ?? null
      setMe(u)
      if (u) setForm({
        name: u.name || '', phone: u.phone || '', designation: u.designation || '',
        department: u.department || '', emails_enabled: u.emails_enabled ?? true,
        mail_from_name: u.mail_from_name || '', mail_from_email: u.mail_from_email || '',
      })
    }).catch(() => setMe(null))
  }, [])

  const sf = (k, v) => setForm((p) => ({ ...p, [k]: v }))

  const save = async () => {
    if (!form.name.trim()) return toast.error('Name is required')
    setSaving(true)
    try {
      // Sent explicitly rather than posting the whole form: the sender identity
      // is admin-set and read-only here. The endpoint ignores it either way, but
      // naming the editable fields means a future change to how the payload is
      // applied server-side cannot quietly make it settable again.
      const r = await api.put('/auth/profile', {
        name: form.name,
        phone: form.phone,
        designation: form.designation,
        department: form.department,
        emails_enabled: form.emails_enabled,
      })
      const updated = r.data?.data
      toast.success('Profile updated')
      setMe((m) => ({ ...m, ...updated }))
      // Keep the header in step — otherwise the name in the corner stays stale
      // until the next sign-in and it looks like the save did not work.
      if (setUser && user) setUser({ ...user, ...updated })
    } catch (e) {
      toast.error(e?.response?.data?.message || e.message || 'Could not save your profile.')
    } finally { setSaving(false) }
  }

  const changePassword = async () => {
    if (pw.password !== pw.password_confirmation) return toast.error('The new passwords do not match')
    if (pw.password.length < 8) return toast.error('Use at least 8 characters')
    setChanging(true)
    try {
      await api.post('/auth/change-password', pw)
      setPw({ current_password: '', password: '', password_confirmation: '' })
      toast.success('Password changed. Your other sessions have been signed out.')
    } catch (e) {
      toast.error(e?.response?.data?.message || 'Could not change your password.')
    } finally { setChanging(false) }
  }

  const readOnlyField = (label, value) => (
    <div>
      <label className="label">{label}</label>
      <p className="text-sm py-2" style={{ color: value ? 'var(--text-h)' : 'var(--text-muted)' }}>
        {value || 'Workspace default'}
      </p>
    </div>
  )

  const field = (label, key, type = 'text', placeholder = '') => (
    <div>
      <label className="label">{label}</label>
      <input type={type} className="input-3d text-sm" value={form[key] ?? ''} placeholder={placeholder}
        onChange={(e) => sf(key, e.target.value)} />
    </div>
  )

  return (
    <div className="p-4 md:p-6 max-w-[900px] mx-auto space-y-5">
      <div>
        <h1 className="text-xl font-bold" style={{ color: 'var(--text-h)' }}>My Profile</h1>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          How you appear to colleagues, and your sign-in security.
        </p>
      </div>

      <div className="card-3d" style={{ padding: 18 }}>
        <p className="label-caps mb-4" style={{ color: 'var(--accent)' }}>
          <User size={13} className="inline mr-1.5" />Details
        </p>
        <div className="grid md:grid-cols-2 gap-4">
          {field('Full name *', 'name')}
          <div>
            <label className="label">Email</label>
            <input className="input-3d text-sm" value={me?.email ?? ''} disabled />
            <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
              Your email is your sign-in. An administrator changes it.
            </p>
          </div>
          {field('Phone', 'phone', 'tel')}
          {field('Designation', 'designation', 'text', 'e.g. Account Manager')}
          {field('Department', 'department')}
          <div>
            <label className="label">Role</label>
            <input className="input-3d text-sm" value={me?.internal_role || me?.role || ''} disabled />
            <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
              Set by an administrator.
            </p>
          </div>
          <div className="md:col-span-2">
            <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-muted)' }}>
              <input type="checkbox" checked={!!form.emails_enabled}
                onChange={(e) => sf('emails_enabled', e.target.checked)} />
              Send me email notifications
            </label>
          </div>

          {/* ST1 — read-only. The sender identity is set by an admin on the staff
              record, not here: TenantMailer uses it verbatim as the From address,
              so a self-service field let anyone send CRM mail as anyone else.
              Shown rather than hidden because people need to know what their mail
              goes out as — but as text, since an input that silently discards what
              you type is worse than no input at all. */}
          <div className="md:col-span-2 pt-2 mt-1" style={{ borderTop: '1px solid var(--border)' }}>
            <p className="label-caps mb-1" style={{ color: 'var(--text-muted)' }}>Email sender identity</p>
            <p className="text-[11px] mb-3" style={{ color: 'var(--text-muted)' }}>
              Set by an admin. Mail you send goes out as this; with nothing set, the workspace default is used.
            </p>
          </div>
          {readOnlyField('From name', form.mail_from_name)}
          {readOnlyField('From email', form.mail_from_email)}
        </div>
        <div className="flex justify-end mt-4">
          <button onClick={save} disabled={saving}
            className="flex items-center gap-1.5 px-5 py-2 rounded-xl text-sm font-bold text-white disabled:opacity-60"
            style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
            <Save size={14} /> {saving ? 'Saving…' : 'Save'}
          </button>
        </div>
      </div>

      <div className="card-3d" style={{ padding: 18 }}>
        <p className="label-caps mb-4" style={{ color: 'var(--accent)' }}>
          <Lock size={13} className="inline mr-1.5" />Change password
        </p>
        <div className="grid md:grid-cols-3 gap-4">
          {[['Current password', 'current_password'], ['New password', 'password'], ['Confirm new password', 'password_confirmation']]
            .map(([label, key]) => (
              <div key={key}>
                <label className="label">{label}</label>
                <input type="password" className="input-3d text-sm" value={pw[key]}
                  onChange={(e) => setPw((p) => ({ ...p, [key]: e.target.value }))} />
              </div>
            ))}
        </div>
        <p className="text-[11px] mt-2" style={{ color: 'var(--text-muted)' }}>
          Your current password is required — a signed-in session on its own is not proof it is you.
          Your other sessions will be signed out; this one stays.
        </p>
        <div className="flex justify-end mt-4">
          <button onClick={changePassword} disabled={changing}
            className="px-5 py-2 rounded-xl text-sm font-bold disabled:opacity-60"
            style={{ background: 'var(--bg-input)', color: 'var(--text-h)', border: '1px solid var(--border)' }}>
            {changing ? 'Changing…' : 'Change password'}
          </button>
        </div>
      </div>

      <button onClick={() => navigate('/app/sessions')}
        className="card-3d w-full flex items-center gap-3 text-left" style={{ padding: 16 }}>
        <ShieldCheck size={16} style={{ color: 'var(--accent)' }} />
        <div className="flex-1 min-w-0">
          <p className="text-sm font-semibold" style={{ color: 'var(--text-h)', margin: 0 }}>Active sessions</p>
          <p className="text-[11px]" style={{ color: 'var(--text-muted)', margin: 0 }}>
            See where you are signed in, and sign out anywhere you do not recognise.
          </p>
        </div>
        <ArrowUpRight size={14} style={{ color: 'var(--text-muted)' }} />
      </button>
    </div>
  )
}
