import { useState, useEffect } from 'react'
import { Save } from 'lucide-react'
import { settingsApi } from '@/services/settingsApi'
import { useToast } from '@/hooks/useToast'

// Keys mirror the backend SettingRegistry 'upload' group. Server is the source of
// truth for defaults; this form just renders and saves the values it returns.
const NUM = [
  ['max_upload_mb', 'Max upload size (MB)'],
  ['avatar_max_mb', 'Avatar limit (MB)'],
  ['logo_max_mb', 'Company logo limit (MB)'],
  ['attachment_max_mb', 'Attachment limit (MB)'],
]
const EXT = [
  ['allowed_image_ext', 'Allowed image extensions'],
  ['allowed_document_ext', 'Allowed document extensions'],
  ['allowed_video_ext', 'Allowed video extensions'],
  ['allowed_archive_ext', 'Allowed archive extensions'],
]

export default function UploadSettings() {
  const toast = useToast()
  const [values, setValues] = useState(null)
  const [saving, setSaving] = useState(false)

  const sv = (k, v) => setValues(p => ({ ...p, [k]: v }))

  useEffect(() => {
    settingsApi.group.get('upload').then(d => setValues(d?.values || {})).catch(e => toast.error(e.message))
  }, [])

  const save = async () => {
    setSaving(true)
    try {
      const d = await settingsApi.group.update('upload', values)
      setValues(d?.values || {})
      toast.success('Upload settings saved')
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  if (!values) return <div className="card-3d"><div className="skeleton h-40 rounded-xl" style={{ background: 'var(--border)' }} /></div>

  return (
    <div className="space-y-4">
      <div className="card-3d">
        <h2 className="font-bold text-base mb-1" style={{ color: 'var(--text-h)' }}>Upload Limits</h2>
        <p className="text-xs mb-5" style={{ color: 'var(--text-muted)' }}>Per-tenant file size limits (megabytes).</p>
        <div className="grid md:grid-cols-2 gap-4">
          {NUM.map(([k, label]) => (
            <div key={k}><label className="label">{label}</label>
              <input type="number" min="1" className="input-3d text-sm" value={values[k] ?? ''} onChange={e => sv(k, e.target.value === '' ? '' : Number(e.target.value))} /></div>
          ))}
        </div>
      </div>

      <div className="card-3d">
        <h2 className="font-bold text-base mb-1" style={{ color: 'var(--text-h)' }}>Allowed Extensions</h2>
        <p className="text-xs mb-5" style={{ color: 'var(--text-muted)' }}>Comma-separated, without dots (e.g. jpg,png,pdf).</p>
        <div className="grid md:grid-cols-2 gap-4">
          {EXT.map(([k, label]) => (
            <div key={k}><label className="label">{label}</label>
              <input className="input-3d text-sm" value={values[k] ?? ''} onChange={e => sv(k, e.target.value)} /></div>
          ))}
        </div>
      </div>

      <div className="card-3d">
        <h2 className="font-bold text-base mb-1" style={{ color: 'var(--text-h)' }}>Storage</h2>
        <div className="grid md:grid-cols-2 gap-4 items-end">
          <div><label className="label">Storage disk</label>
            <select className="input-3d text-sm" value={values.storage_disk || 'local'} onChange={e => sv('storage_disk', e.target.value)}>
              <option value="local">Local (private)</option>
              <option value="public">Public</option>
              <option value="s3">S3</option>
            </select>
          </div>
          <label className="flex items-center gap-2 text-sm font-semibold cursor-pointer" style={{ color: 'var(--text-body)' }}>
            <input type="checkbox" checked={!!values.public_visibility} onChange={e => sv('public_visibility', e.target.checked)} />
            Uploaded files are publicly visible
          </label>
        </div>
      </div>

      <div className="flex justify-end">
        <button onClick={save} disabled={saving} className="btn-3d flex items-center gap-2 disabled:opacity-60"><Save size={14} /> {saving ? 'Saving…' : 'Save Settings'}</button>
      </div>
    </div>
  )
}
