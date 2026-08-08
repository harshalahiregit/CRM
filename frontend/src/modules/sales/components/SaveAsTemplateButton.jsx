import { useState, useEffect, useCallback } from 'react'
import { createPortal } from 'react-dom'
import { LayoutTemplate, Save, Trash2, X, Loader2 } from 'lucide-react'
import { salesDocumentTemplateApi } from '@/services/salesDocumentTemplateApi'
import { useToast } from '@/hooks/useToast'

/**
 * "Save as template" — keeps the current line items for reuse.
 *
 * Lives in the drawer FOOTER beside Create, not mid-form: saving a template is a
 * document-level action taken once the form is filled, so it belongs with the
 * other document-level actions rather than interrupting the line-items grid.
 *
 * Applying a template is deliberately NOT here — that happens on the
 * pick-a-template page you land on from "New", before this form opens.
 *
 * `variant="inline"` renders the wider bar used on the roomy proposal-wizard
 * step; the default suits a footer.
 *
 * Props:
 *   docType  'invoice' | 'estimate' | 'proposal'
 *   form     the form object — line items and defaults are read from it
 */
export default function SaveAsTemplateButton({ docType, form, variant = 'footer' }) {
  const toast = useToast()
  const [open, setOpen] = useState(false)
  const [templates, setTemplates] = useState(null)
  const [name, setName] = useState('')
  const [saving, setSaving] = useState(false)

  // Only fetched once the dialog opens — the button itself doesn't need the list,
  // so a closed drawer costs no request.
  const load = useCallback(() => {
    salesDocumentTemplateApi.list(docType)
      .then(d => setTemplates(Array.isArray(d) ? d : (d?.data ?? [])))
      .catch(() => setTemplates([]))
  }, [docType])
  useEffect(() => { if (open) load() }, [open, load])

  const rows = (form.line_items || []).filter(r => r?.item_name)

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
      setName(''); setOpen(false)
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  const remove = async (t) => {
    try { await salesDocumentTemplateApi.delete(t.id); toast.success('Template deleted'); load() }
    catch (e) { toast.error(e.message) }
  }

  const disabled = !rows.length
  const title = disabled ? 'Add line items first' : 'Keep these line items as a reusable template'

  return (
    <>
      <button type="button" onClick={() => setOpen(true)} disabled={disabled} title={title}
        // `.drawer-footer > *` forces width:100% on every child, so the footer
        // sizes purely by flex ratio — flex-none here would take the full width and
        // refuse to shrink. flex-1 puts it on a par with Cancel, leaving Create
        // (flex-[2]) the widest and clearly the primary action.
        className={variant === 'inline'
          ? 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold disabled:opacity-45'
          : 'flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-3 rounded-2xl text-sm font-semibold whitespace-nowrap transition-all disabled:opacity-45'}
        style={{ background: 'var(--bg-input)', color: 'var(--accent)', border: '1px solid var(--border)' }}>
        <LayoutTemplate size={variant === 'inline' ? 12 : 15} />
        <span className={variant === 'inline' ? '' : 'hidden sm:inline'}>Save as template</span>
      </button>

      {open && createPortal(
        <div className="fixed inset-0 z-[120] flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,0.5)' }}>
          <div className="w-full max-w-md rounded-2xl p-5" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            <div className="flex items-center justify-between mb-3">
              <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Save as template</p>
              <button onClick={() => setOpen(false)} className="w-7 h-7 rounded-lg flex items-center justify-center"
                style={{ border: '1px solid var(--border)' }}>
                <X size={13} style={{ color: 'var(--text-muted)' }} />
              </button>
            </div>

            <p className="text-xs mb-3" style={{ color: 'var(--text-muted)' }}>
              Saves {rows.length} line item{rows.length === 1 ? '' : 's'} plus the terms, notes and discount on this
              form. The client, dates and number are never stored.
            </p>

            <input autoFocus className="input-3d text-sm" placeholder="e.g. Standard retainer"
              value={name} onChange={e => setName(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && save()} />

            <div className="flex justify-end gap-2 mt-4">
              <button onClick={() => setOpen(false)} className="px-4 py-2 rounded-xl text-xs font-bold"
                style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
              <button onClick={save} disabled={saving}
                className="px-4 py-2 rounded-xl text-xs font-bold text-white inline-flex items-center gap-1.5 disabled:opacity-60"
                style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED)' }}>
                {saving ? <Loader2 size={12} className="animate-spin" /> : <Save size={12} />} Save
              </button>
            </div>

            {/* Managing them from here as well, since there's no separate screen yet. */}
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
                      <button onClick={() => remove(t)} title="Delete template">
                        <Trash2 size={12} style={{ color: '#f87171' }} />
                      </button>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>,
        document.body,
      )}
    </>
  )
}
