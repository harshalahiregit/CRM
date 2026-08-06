import { useState, useEffect } from 'react'
import { Save } from 'lucide-react'
import { settingsApi } from '@/services/settingsApi'
import { useToast } from '@/hooks/useToast'
import { invalidateFormats } from '@/hooks/useFormats'

// Options mirror the backend SettingRegistry 'localization' rules — the server
// validates against the same list.
const DATE_FORMATS = [
  ['d/m/Y', '31/12/2026'], ['m/d/Y', '12/31/2026'], ['Y-m-d', '2026-12-31'],
  ['d-m-Y', '31-12-2026'], ['d M Y', '31 Dec 2026'], ['M d Y', 'Dec 31 2026'],
]
const DAYS = [[0, 'Sunday'], [1, 'Monday'], [2, 'Tuesday'], [3, 'Wednesday'], [4, 'Thursday'], [5, 'Friday'], [6, 'Saturday']]
const TIMEZONES = [
  'Asia/Kolkata', 'Asia/Dubai', 'Asia/Singapore', 'Asia/Tokyo',
  'Europe/London', 'Europe/Berlin', 'America/New_York', 'America/Los_Angeles', 'Australia/Sydney', 'UTC',
]

export default function LocalizationSettings() {
  const toast = useToast()
  const [values, setValues] = useState(null)
  const [saving, setSaving] = useState(false)

  const sv = (k, v) => setValues(p => ({ ...p, [k]: v }))

  useEffect(() => {
    settingsApi.group.get('localization').then(d => setValues(d?.values || {})).catch(e => toast.error(e.message))
  }, [])

  const save = async () => {
    setSaving(true)
    try {
      const d = await settingsApi.group.update('localization', values)
      setValues(d?.values || {})
      invalidateFormats()  // every screen re-reads the new date/time contract
      toast.success('Localization settings saved')
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  if (!values) return <div className="card-3d"><div className="skeleton h-40 rounded-xl" style={{ background: 'var(--border)' }} /></div>

  return (
    <div className="space-y-4">
      <div className="card-3d">
        <h2 className="font-bold text-base mb-1" style={{ color: 'var(--text-h)' }}>Localization</h2>
        <p className="text-xs mb-5" style={{ color: 'var(--text-muted)' }}>
          Language, timezone and date/time formats. Every module formats dates through these settings.
        </p>
        <div className="grid md:grid-cols-2 gap-4">
          <div><label className="label">Default Language</label>
            <input className="input-3d text-sm" value={values.language ?? ''} onChange={e => sv('language', e.target.value)} placeholder="en" /></div>
          <div><label className="label">Locale</label>
            <input className="input-3d text-sm" value={values.locale ?? ''} onChange={e => sv('locale', e.target.value)} placeholder="en_IN" /></div>
          <div><label className="label">Timezone</label>
            <select className="input-3d text-sm" value={values.timezone || 'Asia/Kolkata'} onChange={e => sv('timezone', e.target.value)}>
              {TIMEZONES.map(tz => <option key={tz} value={tz}>{tz}</option>)}
            </select></div>
          <div><label className="label">Date Format</label>
            <select className="input-3d text-sm" value={values.date_format || 'd/m/Y'} onChange={e => sv('date_format', e.target.value)}>
              {DATE_FORMATS.map(([v, sample]) => <option key={v} value={v}>{v} — {sample}</option>)}
            </select></div>
          <div><label className="label">Time Format</label>
            <select className="input-3d text-sm" value={String(values.time_format || '12')} onChange={e => sv('time_format', e.target.value)}>
              <option value="12">12-hour (09:15 PM)</option>
              <option value="24">24-hour (21:15)</option>
            </select></div>
          <div><label className="label">First day of week</label>
            <select className="input-3d text-sm" value={Number(values.week_start_day ?? 1)} onChange={e => sv('week_start_day', Number(e.target.value))}>
              {DAYS.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
            </select></div>
        </div>
      </div>

      <div className="flex justify-end">
        <button onClick={save} disabled={saving} className="btn-3d flex items-center gap-2 disabled:opacity-60"><Save size={14} /> {saving ? 'Saving…' : 'Save Settings'}</button>
      </div>
    </div>
  )
}
