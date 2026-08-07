import { useState, useEffect, useCallback } from 'react'
import { createPortal } from 'react-dom'
import { LayoutTemplate, Save, Trash2, X, Loader2 } from 'lucide-react'
import { salesDocumentTemplateApi } from '@/services/salesDocumentTemplateApi'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import { useToast } from '@/hooks/useToast'

/**
 * Apply / save a reusable set of line items on the forms that have a line-items
 * grid: invoices, estimates and proposals.
 *
 * Those forms are mostly their grid, and the same package of items gets re-keyed
 * on every document. A template carries the items plus the document defaults
 * (terms, notes, discount, currency) — never the client, dates or numbering,
 * which are always specific to one document.
 *
 * Complements proposal templates rather than overlapping them: those carry cover
 * and content pages but deliberately no pricing, which is exactly what this adds.
 * Credit notes are not included — that form has no grid to fill.
 *
 * Shared by every page so the behaviour can't drift; `docType` scopes the list,
 * so an invoice picker never offers an estimate's template.
 *
 * Props:
 *   docType  'invoice' | 'estimate' | 'proposal'
 *   form     the drawer's form object (read for save, patched on apply)
 *   onApply  (patch) => void — merges the template's fields into the form
 */
const LABEL = { invoice: 'invoice', estimate: 'estimate', proposal: 'proposal' }

export default function DocumentTemplateBar({ docType, form, onApply }) {
  const toast = useToast()
  const [templates, setTemplates] = useState(null)
  const [saving, setSaving] = useState(false)
  const [showSave, setShowSave] = useState(false)
  const [name, setName] = useState('')
  const [pending, setPending] = useState(null)   // template awaiting overwrite confirmation

  const load = useCallback(() => {
    salesDocumentTemplateApi.list(docType)
      .then(d => setTemplates(Array.isArray(d) ? d : (d?.data ?? [])))
      .catch(() => setTemplates([]))
  }, [docType])
  useEffect(() => { load() }, [load])

  const rows = (form.line_items || []).filter(r => r?.item_name)

  /** Copy a template's items + defaults onto the form. */
  const apply = (t) => {
    onApply({
      line_items: (t.line_items || []).map(i => ({
        item_id: i.item_id ?? null,
        item_name: i.item_name,
        description: i.description ?? '',
        hsn_sac_code: i.hsn_sac_code ?? '',
        qty: Number(i.qty) || 1,
        unit: i.unit || 'pcs',
        rate: Number(i.rate) || 0,
        tax: Number(i.tax) || 0,
        taxes: i.taxes ?? null,
        discount: Number(i.discount) || 0,
        discount_mode: i.discount_mode || 'fixed',
      })),
      // Only overwrite a default when the template actually carries one, so
      // applying a template that sets no terms doesn't wipe what's typed.
      ...(t.terms ? { terms: t.terms } : {}),
      ...(t.adminnote ? { adminnote: t.adminnote } : {}),
      ...(t.clientnote ? { clientnote: t.clientnote } : {}),
      ...(t.currency ? { currency: t.currency } : {}),
      ...(t.discount_type ? { discount_type: t.discount_type } : {}),
      ...(t.discount_mode ? { discount_mode: t.discount_mode } : {}),
      ...(Number(t.discount_value) ? { discount_value: Number(t.discount_value) } : {}),
    })
    toast.success(`Applied "${t.name}"`)
  }

  const onPick = (id) => {
    const t = templates?.find(x => String(x.id) === String(id))
    if (!t) return
    // Applying replaces the grid, so don't silently discard typed-in rows.
    if (rows.length) setPending(t)
    else apply(t)
  }

  const save = async () => {
    if (!name.trim()) return toast.error('Give the template a name')
    if (!rows.length) return toast.error('Add at least one line item first')
    setSaving(true)
    try {
      await salesDocumentTemplateApi.create({
        doc_type: docType,
        name: name.trim(),
        line_items: rows,
        terms: form.terms || null,
        adminnote: form.adminnote || null,
        clientnote: form.clientnote || null,
        currency: form.currency || null,
        discount_type: form.discount_type || null,
        discount_mode: form.discount_mode || null,
        discount_value: Number(form.discount_value) || 0,
      })
      toast.success('Template saved')
      setName(''); setShowSave(false); load()
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  const remove = async (t) => {
    try {
      await salesDocumentTemplateApi.delete(t.id)
      toast.success('Template deleted'); load()
    } catch (e) { toast.error(e.message) }
  }

  return (
    <div className="rounded-xl p-3 mb-4" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
      <div className="flex items-center gap-2 flex-wrap">
        <span className="inline-flex items-center gap-1.5 text-[11px] font-bold" style={{ color: 'var(--accent)' }}>
          <LayoutTemplate size={13} /> Templates
        </span>

        {templates === null ? (
          <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Loading…</span>
        ) : (
          <select className="input-3d text-xs" style={{ maxWidth: 260 }} value=""
            onChange={e => { onPick(e.target.value); e.target.value = '' }}>
            <option value="">
              {templates.length ? `Apply a saved ${LABEL[docType]} template…` : `No ${LABEL[docType]} templates yet`}
            </option>
            {templates.map(t => (
              <option key={t.id} value={t.id}>
                {t.name} — {t.items_count ?? (t.line_items || []).length} item(s)
              </option>
            ))}
          </select>
        )}

        <button type="button" onClick={() => setShowSave(true)} disabled={!rows.length}
          title={rows.length ? 'Save these line items as a reusable template' : 'Add line items first'}
          className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold disabled:opacity-45"
          style={{ background: 'rgba(124,58,237,0.1)', color: 'var(--accent)', border: '1px solid var(--border)' }}>
          <Save size={12} /> Save as template
        </button>

        {!!templates?.length && (
          <span className="text-[11px] ml-auto" style={{ color: 'var(--text-muted)' }}>
            {templates.length} saved
          </span>
        )}
      </div>

      {showSave && createPortal(
        <div className="fixed inset-0 z-[120] flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,0.5)' }}>
          <div className="w-full max-w-md rounded-2xl p-5" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            <div className="flex items-center justify-between mb-3">
              <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Save as template</p>
              <button onClick={() => setShowSave(false)} className="w-7 h-7 rounded-lg flex items-center justify-center"
                style={{ border: '1px solid var(--border)' }}><X size={13} style={{ color: 'var(--text-muted)' }} /></button>
            </div>
            <p className="text-xs mb-3" style={{ color: 'var(--text-muted)' }}>
              Saves {rows.length} line item{rows.length === 1 ? '' : 's'} plus the terms, notes and discount on this
              form. The client, dates and number are never stored.
            </p>
            <input autoFocus className="input-3d text-sm" placeholder="e.g. Standard retainer"
              value={name} onChange={e => setName(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && save()} />
            <div className="flex justify-end gap-2 mt-4">
              <button onClick={() => setShowSave(false)} className="px-4 py-2 rounded-xl text-xs font-bold"
                style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
              <button onClick={save} disabled={saving}
                className="px-4 py-2 rounded-xl text-xs font-bold text-white inline-flex items-center gap-1.5 disabled:opacity-60"
                style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED)' }}>
                {saving ? <Loader2 size={12} className="animate-spin" /> : <Save size={12} />} Save
              </button>
            </div>

            {!!templates?.length && (
              <div className="mt-4 pt-3" style={{ borderTop: '1px solid var(--border)' }}>
                <p className="label-caps mb-2">Existing</p>
                <div className="space-y-1 max-h-40 overflow-y-auto">
                  {templates.map(t => (
                    <div key={t.id} className="flex items-center justify-between gap-2 text-xs px-2 py-1.5 rounded-lg"
                      style={{ background: 'var(--bg-input)' }}>
                      <span className="truncate" style={{ color: 'var(--text-h)' }}>
                        {t.name} <span style={{ color: 'var(--text-muted)' }}>· {t.items_count ?? 0} item(s)</span>
                      </span>
                      <button onClick={() => remove(t)} title="Delete template"><Trash2 size={12} style={{ color: '#f87171' }} /></button>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>,
        document.body,
      )}

      {pending && (
        <ConfirmDialog
          title={`Replace ${rows.length} line item${rows.length === 1 ? '' : 's'}?`}
          message={`Applying "${pending.name}" replaces what's currently in the grid.`}
          confirmLabel="Replace"
          onCancel={() => setPending(null)}
          onConfirm={() => { apply(pending); setPending(null) }}
        />
      )}
    </div>
  )
}
