import { useState, useEffect } from 'react'
import { X, Plus, Edit2, Trash2 } from 'lucide-react'
import { customerApi } from '@/services/customerApi'
import { useToast } from '@/hooks/useToast'
import ConfirmDialog from '@/components/ui/ConfirmDialog'

// Mirrors the old CRM's Setup → Custom Fields: define fields once (scoped to
// customers) and they render on every customer's Custom Fields tab to fill in.
const TYPES = [
  { value: 'input', label: 'Text' },
  { value: 'number', label: 'Number' },
  { value: 'textarea', label: 'Text area' },
  { value: 'select', label: 'Dropdown (select)' },
  { value: 'multiselect', label: 'Multi-select' },
  { value: 'checkbox', label: 'Checkbox' },
  { value: 'date_picker', label: 'Date' },
]
const NEEDS_OPTIONS = ['select', 'multiselect']
const EMPTY = { name: '', type: 'input', options: '', required: false, show_on_table: false, field_to: 'customers' }

export default function CustomFieldsManager({ onClose }) {
  const toast = useToast()
  const [fields, setFields] = useState(null)
  const [editing, setEditing] = useState(null)   // null = list view; object = form
  const [form, setForm] = useState(EMPTY)
  const [saving, setSaving] = useState(false)
  const [confirmDel, setConfirmDel] = useState(null)

  const load = () => customerApi.customFields.list('customers').then(setFields).catch(e => toast.error(e.message))
  useEffect(() => { load() }, [])

  const openNew = () => { setForm(EMPTY); setEditing({ id: null }) }
  const openEdit = (f) => {
    setForm({ name: f.name, type: f.type, options: f.options || '', required: !!f.required, show_on_table: !!f.show_on_table, field_to: 'customers' })
    setEditing(f)
  }

  const save = async () => {
    if (!form.name.trim()) return toast.error('Field name is required')
    setSaving(true)
    try {
      if (editing?.id) { await customerApi.customFields.update(editing.id, form); toast.success('Custom field updated') }
      else { await customerApi.customFields.create(form); toast.success('Custom field created') }
      setEditing(null); load()
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  const doDelete = async () => {
    try { await customerApi.customFields.remove(confirmDel.id); toast.success('Custom field deleted'); load() }
    catch (e) { toast.error(e.message) } finally { setConfirmDel(null) }
  }

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  return (
    <>
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,0.7)' }}>
        <div className="rounded-2xl shadow-2xl w-full max-w-xl max-h-[90vh] overflow-y-auto" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          {/* Header */}
          <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
            <div>
              <h2 className="font-black text-lg" style={{ color: 'var(--text-h)' }}>Customer Custom Fields</h2>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Define fields that appear on every customer's Custom Fields tab</p>
            </div>
            <button onClick={onClose} className="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[rgba(239,68,68,0.08)]" style={{ border: '1px solid var(--border)' }}>
              <X size={16} style={{ color: 'var(--text-muted)' }} />
            </button>
          </div>

          <div className="p-5">
            {/* ── Definition form ── */}
            {editing ? (
              <div className="space-y-4">
                <div>
                  <label className="label">Field Name *</label>
                  <input className="input-3d text-sm" placeholder="e.g. Industry" value={form.name} onChange={e => sf('name', e.target.value)} />
                </div>
                <div>
                  <label className="label">Field Type</label>
                  <select className="input-3d text-sm" value={form.type} onChange={e => sf('type', e.target.value)}>
                    {TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
                  </select>
                </div>
                {NEEDS_OPTIONS.includes(form.type) && (
                  <div>
                    <label className="label">Options <span style={{ textTransform: 'none', fontWeight: 400 }}>(one per line)</span></label>
                    <textarea rows={4} className="input-3d text-sm resize-none" placeholder={'IT Services\nManufacturing\nRetail'} value={form.options} onChange={e => sf('options', e.target.value)} />
                  </div>
                )}
                <div className="flex gap-5">
                  <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-muted)' }}>
                    <input type="checkbox" checked={form.required} onChange={e => sf('required', e.target.checked)} /> Required
                  </label>
                  <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-muted)' }}>
                    <input type="checkbox" checked={form.show_on_table} onChange={e => sf('show_on_table', e.target.checked)} /> Show in list
                  </label>
                </div>
                <div className="flex gap-2 justify-end pt-2">
                  <button onClick={() => setEditing(null)} className="px-4 py-2 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
                  <button onClick={save} disabled={saving} className="px-5 py-2 rounded-xl text-sm font-bold text-white disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>{saving ? 'Saving…' : editing.id ? 'Save' : 'Add Field'}</button>
                </div>
              </div>
            ) : (
              /* ── Definition list ── */
              <div className="space-y-3">
                <div className="flex justify-end">
                  <button onClick={openNew} className="flex items-center gap-1 px-4 py-2 rounded-xl text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}><Plus size={13} /> New Field</button>
                </div>
                {fields === null ? (
                  <div className="skeleton h-16 rounded-xl" style={{ background: 'var(--border)' }} />
                ) : fields.length === 0 ? (
                  <div className="py-10 text-center text-sm" style={{ color: 'var(--text-muted)' }}>No custom fields yet. Click “New Field” to add one.</div>
                ) : fields.map(f => (
                  <div key={f.id} className="flex items-center justify-between p-3 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    <div>
                      <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{f.name}{f.required ? ' *' : ''}</p>
                      <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{TYPES.find(t => t.value === f.type)?.label || f.type}{f.active === false ? ' · inactive' : ''}</p>
                    </div>
                    <div className="flex gap-1">
                      <button onClick={() => openEdit(f)} className="p-1.5 rounded-lg hover:bg-[rgba(124,58,237,0.08)]" title="Edit"><Edit2 size={13} style={{ color: 'var(--text-muted)' }} /></button>
                      <button onClick={() => setConfirmDel(f)} className="p-1.5 rounded-lg hover:bg-[rgba(239,68,68,0.08)]" title="Delete"><Trash2 size={13} style={{ color: '#f87171' }} /></button>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>

      {confirmDel && (
        <ConfirmDialog
          title="Delete custom field?"
          message={`“${confirmDel.name}” and all its saved values will be permanently removed.`}
          confirmLabel="Delete"
          onConfirm={doDelete}
          onCancel={() => setConfirmDel(null)}
        />
      )}
    </>
  )
}
