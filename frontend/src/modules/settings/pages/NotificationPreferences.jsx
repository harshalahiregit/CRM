import { useState, useEffect } from 'react'
import { Save } from 'lucide-react'
import { settingsApi } from '@/services/settingsApi'
import { useToast } from '@/hooks/useToast'

// Channels mirror the backend SettingRegistry 'notifications' group; categories are
// rendered from whatever the backend returns (registry-driven), so adding a
// category server-side needs no frontend change. Tenant defaults only.
const CHANNELS = [
  ['email', 'Email'],
  ['browser', 'Browser'],
  ['whatsapp', 'WhatsApp'],
  ['sms', 'SMS'],
  ['push', 'Push'],
]

export default function NotificationPreferences() {
  const toast = useToast()
  const [values, setValues] = useState(null)
  const [saving, setSaving] = useState(false)

  const sv = (k, v) => setValues(p => ({ ...p, [k]: v }))
  const setCell = (cat, chan, v) => setValues(p => ({
    ...p,
    categories: { ...p.categories, [cat]: { ...(p.categories?.[cat] || {}), [chan]: v } },
  }))

  useEffect(() => {
    settingsApi.group.get('notifications').then(d => setValues(d?.values || {})).catch(e => toast.error(e.message))
  }, [])

  const save = async () => {
    setSaving(true)
    try {
      const d = await settingsApi.group.update('notifications', values)
      setValues(d?.values || {})
      toast.success('Notification preferences saved')
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  if (!values) return <div className="card-3d"><div className="skeleton h-40 rounded-xl" style={{ background: 'var(--border)' }} /></div>

  const categories = Object.keys(values.categories || {})

  return (
    <div className="space-y-4">
      <div className="card-3d">
        <h2 className="font-bold text-base mb-1" style={{ color: 'var(--text-h)' }}>Delivery Channels</h2>
        <p className="text-xs mb-5" style={{ color: 'var(--text-muted)' }}>Master switch per channel. These are workspace defaults; users may still have their own preferences.</p>
        <div className="grid md:grid-cols-3 gap-3">
          {CHANNELS.map(([k, label]) => (
            <label key={k} className="flex items-center gap-2 text-sm font-semibold cursor-pointer" style={{ color: 'var(--text-body)' }}>
              <input type="checkbox" checked={!!values[k]} onChange={e => sv(k, e.target.checked)} /> {label}
            </label>
          ))}
        </div>
      </div>

      <div className="card-3d">
        <h2 className="font-bold text-base mb-1" style={{ color: 'var(--text-h)' }}>Per-module Preferences</h2>
        <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>Default channels for each module's notifications.</p>
        <div style={{ overflowX: 'auto' }}>
          <table className="w-full text-sm" style={{ borderCollapse: 'collapse', minWidth: 560 }}>
            <thead>
              <tr>
                <th className="text-left" style={{ padding: '8px 10px', color: 'var(--text-muted)', fontSize: 11, fontWeight: 800, textTransform: 'uppercase' }}>Module</th>
                {CHANNELS.map(([k, label]) => (
                  <th key={k} style={{ padding: '8px 10px', color: 'var(--text-muted)', fontSize: 11, fontWeight: 800, textTransform: 'uppercase', textAlign: 'center' }}>{label}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {categories.map(cat => (
                <tr key={cat} style={{ borderTop: '1px solid var(--border)' }}>
                  <td style={{ padding: '9px 10px', fontWeight: 700, color: 'var(--text-h)' }}>{cat}</td>
                  {CHANNELS.map(([chan]) => (
                    <td key={chan} style={{ padding: '9px 10px', textAlign: 'center' }}>
                      <input type="checkbox" checked={!!values.categories?.[cat]?.[chan]} onChange={e => setCell(cat, chan, e.target.checked)} />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="flex justify-end">
        <button onClick={save} disabled={saving} className="btn-3d flex items-center gap-2 disabled:opacity-60"><Save size={14} /> {saving ? 'Saving…' : 'Save Settings'}</button>
      </div>
    </div>
  )
}
