import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { SlidersHorizontal, Save, Loader2, ArrowRight } from 'lucide-react'
import { leadEngagementApi } from '@/services/leadEngagementApi'
import CustomFieldInput from '@/modules/customer/components/CustomFieldInput'
import EmptyState from '@/components/ui/EmptyState'
import { useToast } from '@/hooks/useToast'

/**
 * Custom fields on a lead.
 *
 * Reuses the platform's existing engine — the same definitions table, the same
 * value store and the same CustomFieldInput the customer profile renders. Only
 * `field_to` differs ('leads'), so a field type added for customers works here
 * with no extra work.
 */
export default function LeadCustomFieldsTab({ leadId }) {
  const navigate = useNavigate()
  const toast = useToast()
  const [defs, setDefs] = useState(null)
  const [values, setValues] = useState({})
  const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    leadEngagementApi.customFields.get(leadId)
      .then(d => {
        const list = Array.isArray(d) ? d : (d?.data ?? [])
        setDefs(list)
        // Seed the editable copy from stored values, falling back to each
        // field's default so a new lead shows what it would save.
        setValues(Object.fromEntries(list.map(f => [f.id, f.value ?? f.default_value ?? ''])))
      })
      .catch(e => { toast.error(e.message); setDefs([]) })
  }, [leadId])
  useEffect(() => { load() }, [load])

  const dirty = defs?.some(f => String(values[f.id] ?? '') !== String(f.value ?? f.default_value ?? ''))

  const save = async () => {
    const missing = (defs || []).filter(f => f.required && !String(values[f.id] ?? '').trim())
    if (missing.length) return toast.error(`Required: ${missing.map(f => f.name).join(', ')}`)
    setSaving(true)
    try {
      await leadEngagementApi.customFields.save(leadId, values)
      toast.success('Custom fields saved')
      load()
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  if (defs === null) {
    return <div className="card-3d"><div className="skeleton h-24 rounded-xl" style={{ background: 'var(--border)' }} /></div>
  }

  // No definitions is a setup gap, not an error — point at where they're created.
  if (!defs.length) {
    return (
      <div className="card-3d" style={{ padding: '20px' }}>
        <EmptyState icon={SlidersHorizontal} title="No lead custom fields yet"
          description="Define fields for leads and they'll appear here on every lead." />
        <div className="flex justify-center mt-3">
          <button onClick={() => navigate('/app/settings/custom-fields')}
            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold"
            style={{ background: 'rgba(124,58,237,0.1)', color: 'var(--accent)', border: '1px solid var(--border)' }}>
            Set up custom fields <ArrowRight size={12} />
          </button>
        </div>
      </div>
    )
  }

  return (
    <div className="card-3d" style={{ padding: '20px' }}>
      <div className="flex items-center justify-between mb-4">
        <h3 className="font-bold text-sm flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
          <SlidersHorizontal size={14} style={{ color: 'var(--accent)' }} /> Custom fields
        </h3>
        <button onClick={save} disabled={saving || !dirty}
          title={dirty ? 'Save changes' : 'No changes to save'}
          className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold text-white disabled:opacity-45"
          style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
          {saving ? <Loader2 size={12} className="animate-spin" /> : <Save size={12} />} Save
        </button>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        {defs.map(f => (
          <div key={f.id} className={f.bs_column === 12 ? 'sm:col-span-2' : ''}>
            <label className="label">
              {f.name}{f.required ? <span style={{ color: '#f87171' }}> *</span> : null}
            </label>
            <CustomFieldInput def={f} value={values[f.id]} onChange={v => setValues(p => ({ ...p, [f.id]: v }))} />
          </div>
        ))}
      </div>
    </div>
  )
}
