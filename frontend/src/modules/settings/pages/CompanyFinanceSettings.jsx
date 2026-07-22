import { useState, useEffect } from 'react'
import { Save } from 'lucide-react'
import { settingsApi } from '@/services/settingsApi'
import { useToast } from '@/hooks/useToast'
import { INDIAN_STATES } from '@/lib/indianStates'

export default function CompanyFinanceSettings() {
  const toast = useToast()
  const [form, setForm] = useState({ name: '', state: '', gstin: '' })
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    settingsApi.company.get()
      .then(d => setForm({ name: d.name || '', state: d.state || '', gstin: d.gstin || '' }))
      .catch(e => toast.error(e.message))
      .finally(() => setLoading(false))
  }, [])

  const save = async () => {
    setSaving(true)
    try {
      await settingsApi.company.update({ state: form.state || null, gstin: form.gstin || null })
      toast.success('Company settings saved')
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  if (loading) return <div className="skeleton h-40 rounded-2xl" style={{ background: 'var(--border)' }} />

  return (
    <div className="card-3d max-w-xl">
      <h2 className="font-bold text-base" style={{ color: 'var(--text-h)' }}>Company & Finance</h2>
      <p className="text-xs mt-1 mb-5" style={{ color: 'var(--text-muted)' }}>
        Your registered state decides GST treatment on documents: same state as the customer →
        CGST + SGST split; different state → IGST.
      </p>
      <div className="space-y-4">
        <div><label className="label">Company Name</label><input className="input-3d text-sm" value={form.name} readOnly style={{ opacity: 0.7 }} /></div>
        <div>
          <label className="label">Registered State</label>
          <select className="input-3d text-sm" value={form.state} onChange={e => setForm(p => ({ ...p, state: e.target.value }))}>
            <option value="">Not set</option>
            {INDIAN_STATES.map(s => <option key={s} value={s}>{s}</option>)}
          </select>
        </div>
        <div>
          <label className="label">GSTIN</label>
          <input className="input-3d text-sm uppercase" maxLength={15} placeholder="27AAECM1234K1Z5" value={form.gstin} onChange={e => setForm(p => ({ ...p, gstin: e.target.value.toUpperCase() }))} />
        </div>
      </div>
      <div className="flex justify-end mt-5">
        <button onClick={save} disabled={saving} className="btn-3d flex items-center gap-2 disabled:opacity-60"><Save size={14} /> {saving ? 'Saving…' : 'Save'}</button>
      </div>
    </div>
  )
}
