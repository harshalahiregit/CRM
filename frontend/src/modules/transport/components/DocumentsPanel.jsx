import { useState } from 'react'
import { FileText, Plus, RefreshCw, AlertTriangle } from 'lucide-react'
import Modal from '@/components/ui/Modal'
import { useToast } from '@/components/ui/Toast'
import { expiryCfg, fmtDate, DOCUMENT_TYPE_LABEL } from '../constants'
import { Section, Field, Chip, inputStyle, selectStyle } from './MasterFormFields'

/**
 * Documents filed against a vehicle, a driver or a consignment — DB-019.
 *
 * The consignment case arrived on 2026-09-19 (ORD-005/006) and is why the
 * heading, the empty sentence and the expiry column are props: an LR is not a
 * compliance document and does not expire, and a panel that says otherwise is
 * wrong on the screen a client is looking at.
 *
 * Shows what CTD §22/§23 ask a document panel to show: the document, its status
 * and its expiry. The expiry state comes from expiryCfg, which reads the same
 * `valid_until` the backend's daysUntilExpiry() reads — the rule is not
 * reimplemented here, only rendered.
 *
 * Renewal opens the same form as filing, because STOS-DOC §26 makes a renewal a
 * NEW VERSION rather than an edit: "do not silently overwrite the previous
 * version. Maintain Version 1, Version 2, Version 3 with history."
 */
export default function DocumentsPanel({
  documents = [], types = [], onFile, onRenew, canEdit = true, windowDays = 30,
  /* ── Three words this panel used to own, now its caller's ─────────────
   *
   * It was written for a VEHICLE and a DRIVER, where every document is a
   * compliance document with an expiry. A consignment's paperwork is not: an
   * LR and a delivery order are what travels with the goods, they do not
   * expire, and "Compliance documents · Valid until —" told a reader three
   * wrong things at once. Defaults are the vehicle/driver behaviour exactly,
   * so those two screens are unchanged.
   */
  heading = 'Compliance documents',
  emptyText = 'No documents on file. Required documents are configured per workspace — until then, nothing is treated as missing.',
  showExpiry = true,
}) {
  const toast = useToast()
  const [open, setOpen] = useState(false)
  const [renewing, setRenewing] = useState(null)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState({ document_type: '', document_number: '', issued_on: '', valid_from: '', valid_until: '', notes: '' })

  const start = (doc = null) => {
    setRenewing(doc)
    setForm({
      document_type: doc?.document_type || types[0]?.value || '',
      document_number: doc?.document_number || '',
      issued_on: '', valid_from: '', valid_until: '', notes: '',
    })
    setOpen(true)
  }

  const submit = async () => {
    if (!form.document_type) return toast.error('Choose a document type.')
    setSaving(true)
    try {
      if (renewing) await onRenew(renewing.id, form)
      else await onFile(form)
      toast.success(renewing ? 'Document renewed — the previous version is kept.' : 'Document filed.')
      setOpen(false); setRenewing(null)
    } catch (e) {
      toast.error(e?.message || 'The document could not be saved.')
    } finally {
      setSaving(false)
    }
  }

  const active = documents.filter((d) => d.status === 'active')
  const superseded = documents.filter((d) => d.status !== 'active')

  return (
    <div className="pr-glass" style={{ padding: 16, borderRadius: 12 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <FileText size={15} style={{ color: '#7C3AED' }} />
          <h3 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: 0, textTransform: 'uppercase', letterSpacing: '.03em' }}>
            {heading}
          </h3>
        </div>
        {canEdit && (
          <button onClick={() => start()}
            style={{ padding: '6px 11px', borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)', fontSize: 12, fontWeight: 700, display: 'inline-flex', alignItems: 'center', gap: 5, cursor: 'pointer' }}>
            <Plus size={12} /> File document
          </button>
        )}
      </div>

      {active.length === 0 && (
        <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: 0 }}>
          {emptyText}
        </p>
      )}

      {active.length > 0 && (
        <div style={{ display: 'grid', gap: 8 }}>
          {active.map((d) => {
            const cfg = expiryCfg(d.valid_until, windowDays)
            return (
              <div key={d.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '9px 11px', background: 'var(--bg-input)', borderRadius: 9, border: '1px solid var(--border)', flexWrap: 'wrap' }}>
                <div style={{ flex: 1, minWidth: 180 }}>
                  <p style={{ margin: 0, fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>
                    {DOCUMENT_TYPE_LABEL[d.document_type] || d.document_type}
                    <span style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 600 }}> · v{d.version}</span>
                  </p>
                  <p style={{ margin: '2px 0 0', fontSize: 11.5, color: 'var(--text-muted)' }}>
                    {d.document_number || 'No number recorded'}
                    {showExpiry ? ` · Valid until ${fmtDate(d.valid_until)}` : (d.issued_on ? ` · issued ${fmtDate(d.issued_on)}` : '')}
                  </p>
                </div>
                {showExpiry && cfg.days !== null && cfg.days < 0 && <AlertTriangle size={14} style={{ color: '#f87171' }} />}
                {showExpiry && <Chip cfg={cfg} />}
                {canEdit && (
                  <button onClick={() => start(d)} title="Renew — keeps the current version as history"
                    style={{ padding: '5px 9px', borderRadius: 7, background: 'transparent', border: '1px solid var(--border)', color: 'var(--text-p)', fontSize: 11.5, fontWeight: 700, display: 'inline-flex', alignItems: 'center', gap: 4, cursor: 'pointer' }}>
                    <RefreshCw size={11} /> Renew
                  </button>
                )}
              </div>
            )
          })}
        </div>
      )}

      {superseded.length > 0 && (
        <details style={{ marginTop: 10 }}>
          <summary style={{ fontSize: 11.5, color: 'var(--text-muted)', cursor: 'pointer' }}>
            {superseded.length} superseded version(s) — kept as history
          </summary>
          <div style={{ display: 'grid', gap: 5, marginTop: 7 }}>
            {superseded.map((d) => (
              <p key={d.id} style={{ margin: 0, fontSize: 11.5, color: 'var(--text-muted)' }}>
                {DOCUMENT_TYPE_LABEL[d.document_type] || d.document_type} v{d.version} · valid until {fmtDate(d.valid_until)}
              </p>
            ))}
          </div>
        </details>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title={renewing ? 'Renew document' : 'File a document'} size="md">
        <div style={{ display: 'grid', gap: 14 }}>
          {renewing && (
            <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: 0 }}>
              The current version stays on file as history — a renewal never overwrites it.
            </p>
          )}
          <Section title="Document" cols={2}>
            <Field label="Type" required>
              <select value={form.document_type} disabled={!!renewing}
                onChange={(e) => setForm({ ...form, document_type: e.target.value })} style={selectStyle}>
                <option value="">Select…</option>
                {types.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
              </select>
            </Field>
            <Field label="Document number">
              <input value={form.document_number} onChange={(e) => setForm({ ...form, document_number: e.target.value })}
                placeholder="Policy / certificate number" style={inputStyle} />
            </Field>
            <Field label="Issued on">
              <input type="date" value={form.issued_on} onChange={(e) => setForm({ ...form, issued_on: e.target.value })} style={inputStyle} />
            </Field>
            <Field label="Valid from">
              <input type="date" value={form.valid_from} onChange={(e) => setForm({ ...form, valid_from: e.target.value })} style={inputStyle} />
            </Field>
            <Field label="Valid until" hint="Leave blank for a document that does not expire (an RC book, for example).">
              <input type="date" value={form.valid_until} onChange={(e) => setForm({ ...form, valid_until: e.target.value })} style={inputStyle} />
            </Field>
            <Field label="Notes" full>
              <input value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} style={inputStyle} />
            </Field>
          </Section>
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            <button onClick={() => setOpen(false)} style={{ padding: '8px 14px', borderRadius: 9, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)', fontSize: 12.5, fontWeight: 700, cursor: 'pointer' }}>Cancel</button>
            <button onClick={submit} disabled={saving}
              style={{ padding: '8px 16px', borderRadius: 9, background: '#7C3AED', border: '1px solid #7C3AED', color: '#fff', fontSize: 12.5, fontWeight: 800, cursor: saving ? 'wait' : 'pointer' }}>
              {saving ? 'Saving…' : renewing ? 'Renew' : 'File document'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
