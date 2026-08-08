import { useState, useEffect, useCallback } from 'react'
import { LayoutTemplate } from 'lucide-react'
import { salesDocumentTemplateApi } from '@/services/salesDocumentTemplateApi'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import SaveAsTemplateButton from './SaveAsTemplateButton'
import { useToast } from '@/hooks/useToast'

/**
 * Pick a line-item template from inside a form.
 *
 * Only the proposal wizard uses this now. Invoices and estimates choose their
 * template on the page you land on from "New" — a picker sitting in the middle of
 * those drawers was both misplaced and a second way to do the same thing. The
 * wizard is a full page whose costing step has room for it, and its own step 0
 * offers proposal-content templates rather than pricing ones, so this is the only
 * place a proposal's line items can come from a template.
 *
 * A template carries the items plus the document defaults (terms, notes,
 * discount, currency) — never the client, dates or numbering.
 *
 * Saving is delegated to SaveAsTemplateButton so there is one save dialog.
 *
 * Props:
 *   docType  'invoice' | 'estimate' | 'proposal' — scopes the list
 *   form     the form object (read for save, patched on apply)
 *   onApply  (patch) => void — merges the template's fields into the form
 */
const LABEL = { invoice: 'invoice', estimate: 'estimate', proposal: 'proposal' }

export default function DocumentTemplateBar({ docType, form, onApply }) {
  const toast = useToast()
  const [templates, setTemplates] = useState(null)
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

        <SaveAsTemplateButton docType={docType} form={form} variant="inline" />

        {!!templates?.length && (
          <span className="text-[11px] ml-auto" style={{ color: 'var(--text-muted)' }}>
            {templates.length} saved
          </span>
        )}
      </div>

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
