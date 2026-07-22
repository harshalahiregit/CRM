import { useState } from 'react'
import { customerApi } from '@/services/customerApi'
import { useToast } from '@/hooks/useToast'

// Shared custom-field definition form — used by the CustomFieldsManager modal,
// the Settings → Custom Fields page, and the inline quick-add in the customer
// flow. `fieldTo` scopes the definition to a module (customers, contacts, …).
// Mirrors the legacy CRM's Setup → Custom Fields form field-for-field.
export const FIELD_TYPES = [
  { value: 'input', label: 'Text' },
  { value: 'number', label: 'Number' },
  { value: 'textarea', label: 'Text area' },
  { value: 'select', label: 'Dropdown (select)' },
  { value: 'multiselect', label: 'Multi-select' },
  { value: 'checkbox', label: 'Checkboxes (multiple)' },
  { value: 'radio', label: 'Radio (single choice)' },
  { value: 'date_picker', label: 'Date' },
  { value: 'date_picker_time', label: 'Date & time' },
  { value: 'colorpicker', label: 'Color' },
  { value: 'link', label: 'Link (URL)' },
]

// Types whose option list is meaningful.
export const OPTION_TYPES = ['select', 'multiselect', 'checkbox', 'radio']
// Types that render as inline checkboxes/radios.
const INLINE_TYPES = ['checkbox', 'radio']

// Module groupings mirror the legacy CRM's field_to lists — they gate which
// visibility options appear on the form.
const PDF_MODULES = ['invoice', 'estimate', 'credit_note']
const CLIENT_PORTAL_MODULES = ['customers', 'contacts', 'estimate', 'invoice', 'proposal', 'contracts', 'tasks', 'projects', 'credit_note', 'tickets']
const CLIENT_EDITABLE_MODULES = ['customers', 'contacts', 'tasks']

// Bootstrap-style widths (bs_column) surfaced as friendly labels.
export const WIDTHS = [
  { value: 12, label: 'Full width' },
  { value: 6, label: 'Half (½)' },
  { value: 4, label: 'Third (⅓)' },
  { value: 3, label: 'Quarter (¼)' },
]

export default function CustomFieldForm({ fieldTo = 'customers', initial = null, onSaved, onCancel }) {
  const toast = useToast()
  const [form, setForm] = useState(initial ? {
    name: initial.name, type: initial.type, options: initial.options || '',
    default_value: initial.default_value || '', field_order: initial.field_order ?? 0,
    bs_column: initial.bs_column ?? 12,
    required: !!initial.required, show_on_table: !!initial.show_on_table,
    only_admin: !!initial.only_admin, display_inline: !!initial.display_inline,
    active: initial.active !== false,
    show_on_pdf: !!initial.show_on_pdf, show_on_client_portal: !!initial.show_on_client_portal,
    disallow_client_edit: !!initial.disallow_client_edit, show_on_ticket_form: !!initial.show_on_ticket_form,
    field_to: fieldTo,
  } : {
    name: '', type: 'input', options: '', default_value: '', field_order: 0, bs_column: 12,
    required: false, show_on_table: false, only_admin: false, display_inline: false, active: true,
    show_on_pdf: false, show_on_client_portal: false, disallow_client_edit: false, show_on_ticket_form: false,
    field_to: fieldTo,
  })
  const [saving, setSaving] = useState(false)

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))
  const needsOptions = OPTION_TYPES.includes(form.type)
  const canInline = INLINE_TYPES.includes(form.type)
  const isPdf = PDF_MODULES.includes(fieldTo)
  const isPortal = CLIENT_PORTAL_MODULES.includes(fieldTo)
  const isClientEditable = CLIENT_EDITABLE_MODULES.includes(fieldTo)

  const save = async () => {
    if (!form.name.trim()) return toast.error('Field name is required')
    if (needsOptions && !form.options.trim()) return toast.error('Add at least one option for this field type')
    setSaving(true)
    try {
      const payload = {
        ...form, field_to: fieldTo,
        field_order: Number(form.field_order) || 0,
        bs_column: Number(form.bs_column) || 12,
      }
      let saved
      if (initial?.id) { saved = await customerApi.customFields.update(initial.id, payload); toast.success('Custom field updated') }
      else { saved = await customerApi.customFields.create(payload); toast.success('Custom field created') }
      onSaved?.(saved)
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label className="label">Field Name *</label>
          <input className="input-3d text-sm" placeholder="e.g. Industry" value={form.name} onChange={e => sf('name', e.target.value)} />
        </div>
        <div>
          <label className="label">Field Type</label>
          <select className="input-3d text-sm" value={form.type} onChange={e => sf('type', e.target.value)}>
            {FIELD_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
          </select>
        </div>
      </div>

      {needsOptions && (
        <div>
          <label className="label">Options <span style={{ textTransform: 'none', fontWeight: 400 }}>(one per line)</span></label>
          <textarea rows={4} className="input-3d text-sm resize-none" placeholder={'IT Services\nManufacturing\nRetail'} value={form.options} onChange={e => sf('options', e.target.value)} />
        </div>
      )}

      {form.type !== 'link' && (
        <div>
          <label className="label">Default Value <span style={{ textTransform: 'none', fontWeight: 400 }}>(optional)</span></label>
          {form.type === 'textarea'
            ? <textarea rows={2} className="input-3d text-sm resize-none" value={form.default_value} onChange={e => sf('default_value', e.target.value)} />
            : <input className="input-3d text-sm" value={form.default_value} onChange={e => sf('default_value', e.target.value)} />}
        </div>
      )}

      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="label">Field Width</label>
          <select className="input-3d text-sm" value={form.bs_column} onChange={e => sf('bs_column', Number(e.target.value))}>
            {WIDTHS.map(w => <option key={w.value} value={w.value}>{w.label}</option>)}
          </select>
        </div>
        <div>
          <label className="label">Field Order</label>
          <input type="number" min="0" className="input-3d text-sm" value={form.field_order} onChange={e => sf('field_order', e.target.value)} />
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 p-3 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
        <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-muted)' }}>
          <input type="checkbox" checked={form.active} onChange={e => sf('active', e.target.checked)} /> Active
        </label>
        <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-muted)' }}>
          <input type="checkbox" checked={form.required} onChange={e => sf('required', e.target.checked)} /> Required
        </label>
        <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-muted)' }}>
          <input type="checkbox" checked={form.show_on_table} onChange={e => sf('show_on_table', e.target.checked)} /> Show in list
        </label>
        <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-muted)' }}>
          <input type="checkbox" checked={form.only_admin} onChange={e => sf('only_admin', e.target.checked)} /> Only admins can access
        </label>
        {canInline && (
          <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-muted)' }}>
            <input type="checkbox" checked={form.display_inline} onChange={e => sf('display_inline', e.target.checked)} /> Display options inline
          </label>
        )}
        {isPdf && (
          <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-muted)' }}>
            <input type="checkbox" checked={form.show_on_pdf} onChange={e => sf('show_on_pdf', e.target.checked)} /> Show on PDF
          </label>
        )}
        {isPortal && (
          <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-muted)' }} title="Visible to the client on their portal (applies once the client portal ships)">
            <input type="checkbox" checked={form.show_on_client_portal} disabled={form.only_admin} onChange={e => sf('show_on_client_portal', e.target.checked)} /> Show on client portal
          </label>
        )}
        {isPortal && isClientEditable && form.show_on_client_portal && !form.only_admin && (
          <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-muted)' }} title="Client can see the field on their portal but not change it">
            <input type="checkbox" checked={form.disallow_client_edit} onChange={e => sf('disallow_client_edit', e.target.checked)} /> Disallow client to edit
          </label>
        )}
        {fieldTo === 'tickets' && (
          <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-muted)' }}>
            <input type="checkbox" checked={form.show_on_ticket_form} onChange={e => sf('show_on_ticket_form', e.target.checked)} /> Show on ticket form
          </label>
        )}
      </div>

      <div className="flex gap-2 justify-end pt-1">
        <button onClick={onCancel} className="px-4 py-2 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
        <button onClick={save} disabled={saving} className="px-5 py-2 rounded-xl text-sm font-bold text-white disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>{saving ? 'Saving…' : initial?.id ? 'Save' : 'Add Field'}</button>
      </div>
    </div>
  )
}
