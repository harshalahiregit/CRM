import { useState, useEffect } from 'react'
import { Save } from 'lucide-react'
import { settingsApi } from '@/services/settingsApi'
import { useToast } from '@/hooks/useToast'

// Mirrors the backend SettingRegistry groups (general + branding). The server is
// the source of truth for keys/defaults; this form just renders/saves them.
const BLANK = {
  general:  { company_name: '', app_name: '', support_email: '', support_phone: '', website: '', address: '' },
  branding: { logo_url: '', logo_dark_url: '', favicon_url: '', primary_color: '#7C3AED' },
}

export default function GeneralBrandingSettings() {
  const toast = useToast()
  const [form, setForm] = useState(BLANK)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const sg = (group, k, v) => setForm(p => ({ ...p, [group]: { ...p[group], [k]: v } }))

  useEffect(() => {
    settingsApi.general.get()
      .then(d => setForm({
        general:  { ...BLANK.general,  ...(d?.general  || {}) },
        branding: { ...BLANK.branding, ...(d?.branding || {}) },
      }))
      .catch(e => toast.error(e.message))
      .finally(() => setLoading(false))
  }, [])

  const save = async () => {
    setSaving(true)
    try {
      const d = await settingsApi.general.update(form)
      setForm({
        general:  { ...BLANK.general,  ...(d?.general  || {}) },
        branding: { ...BLANK.branding, ...(d?.branding || {}) },
      })
      toast.success('General & branding settings saved')
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  if (loading) return <div className="card-3d"><div className="skeleton h-40 rounded-xl" style={{ background: 'var(--border)' }} /></div>

  return (
    <div className="space-y-4">
      {/* General */}
      <div className="card-3d">
        <h2 className="font-bold text-base mb-1" style={{ color: 'var(--text-h)' }}>General</h2>
        <p className="text-xs mb-5" style={{ color: 'var(--text-muted)' }}>Company identity and support contact details for this workspace.</p>
        <div className="grid md:grid-cols-2 gap-4">
          <div><label className="label">Company Name</label><input className="input-3d text-sm" value={form.general.company_name || ''} onChange={e => sg('general', 'company_name', e.target.value)} placeholder="MLA Consulting" /></div>
          <div><label className="label">App Name</label><input className="input-3d text-sm" value={form.general.app_name || ''} onChange={e => sg('general', 'app_name', e.target.value)} placeholder="Sangoe CRM" /></div>
          <div><label className="label">Support Email</label><input className="input-3d text-sm" value={form.general.support_email || ''} onChange={e => sg('general', 'support_email', e.target.value)} placeholder="support@yourcompany.in" /></div>
          <div><label className="label">Support Phone</label><input className="input-3d text-sm" value={form.general.support_phone || ''} onChange={e => sg('general', 'support_phone', e.target.value)} placeholder="+91 …" /></div>
          <div><label className="label">Website</label><input className="input-3d text-sm" value={form.general.website || ''} onChange={e => sg('general', 'website', e.target.value)} placeholder="https://yourcompany.in" /></div>
          <div><label className="label">Address</label><input className="input-3d text-sm" value={form.general.address || ''} onChange={e => sg('general', 'address', e.target.value)} placeholder="Street, City, State" /></div>
        </div>
      </div>

      {/* Branding */}
      <div className="card-3d">
        <h2 className="font-bold text-base mb-1" style={{ color: 'var(--text-h)' }}>Branding</h2>
        <p className="text-xs mb-5" style={{ color: 'var(--text-muted)' }}>Logo, favicon and primary colour. (Image URLs for now; file upload lands with Upload settings.)</p>
        <div className="grid md:grid-cols-2 gap-4">
          <div><label className="label">Logo URL (light)</label><input className="input-3d text-sm" value={form.branding.logo_url || ''} onChange={e => sg('branding', 'logo_url', e.target.value)} placeholder="https://…/logo.png" /></div>
          <div><label className="label">Logo URL (dark)</label><input className="input-3d text-sm" value={form.branding.logo_dark_url || ''} onChange={e => sg('branding', 'logo_dark_url', e.target.value)} placeholder="https://…/logo-dark.png" /></div>
          <div><label className="label">Favicon URL</label><input className="input-3d text-sm" value={form.branding.favicon_url || ''} onChange={e => sg('branding', 'favicon_url', e.target.value)} placeholder="https://…/favicon.ico" /></div>
          <div>
            <label className="label">Primary Colour</label>
            <div className="flex items-center gap-2">
              <input type="color" value={form.branding.primary_color || '#7C3AED'} onChange={e => sg('branding', 'primary_color', e.target.value)} style={{ width: 44, height: 38, borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', cursor: 'pointer', padding: 2 }} />
              <input className="input-3d text-sm flex-1" value={form.branding.primary_color || ''} onChange={e => sg('branding', 'primary_color', e.target.value)} placeholder="#7C3AED" />
            </div>
          </div>
        </div>
      </div>

      <div className="flex justify-end">
        <button onClick={save} disabled={saving} className="btn-3d flex items-center gap-2 disabled:opacity-60"><Save size={14} /> {saving ? 'Saving…' : 'Save Settings'}</button>
      </div>
    </div>
  )
}
