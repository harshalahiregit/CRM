import { useState } from 'react'
import { createPortal } from 'react-dom'
import { TrendingUp, X, Loader2, Building2 } from 'lucide-react'

/**
 * Convert a lead into a customer.
 *
 * Replaces a one-click convert that silently used the lead's raw values. The
 * company name and primary contact become real records, so this is the last chance
 * to correct them — and the carry-over choices mirror the old CRM's convert
 * checkboxes rather than guessing.
 *
 * Everything is pre-filled from the lead: submitting unchanged behaves exactly
 * like the old one-click did, only now it actually creates the customer.
 */
export default function ConvertLeadDialog({ lead, onCancel, onConfirm, busy = false }) {
  const [form, setForm] = useState({
    company: lead.company || lead.name || '',
    contact_name: lead.name || '',
    contact_email: lead.email || '',
    phone: lead.phone || '',
    transfer_notes: true,
    transfer_custom_fields: true,
  })
  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  return createPortal(
    <div className="fixed inset-0 z-[130] flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,0.55)' }}>
      <div className="w-full max-w-lg rounded-2xl overflow-hidden flex flex-col"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '90vh' }}>
        <div className="flex items-center justify-between gap-3 px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <div className="flex items-center gap-2.5 min-w-0">
            <div className="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
              style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED)' }}>
              <TrendingUp size={16} className="text-white" />
            </div>
            <div className="min-w-0">
              <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Convert to customer</p>
              <p className="text-[11px] truncate" style={{ color: 'var(--text-muted)' }}>
                Creates a customer record and a primary contact
              </p>
            </div>
          </div>
          <button onClick={onCancel} className="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
            style={{ border: '1px solid var(--border)' }}>
            <X size={14} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        <div className="overflow-y-auto p-5 space-y-3">
          <div>
            <label className="label">Company name *</label>
            <input className="input-3d text-sm" value={form.company} onChange={e => sf('company', e.target.value)}
              placeholder="Customer company" />
            <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
              This becomes the customer's name across invoices and proposals.
            </p>
          </div>

          <div className="grid sm:grid-cols-2 gap-3">
            <div>
              <label className="label">Primary contact</label>
              <input className="input-3d text-sm" value={form.contact_name} onChange={e => sf('contact_name', e.target.value)} />
            </div>
            <div>
              <label className="label">Contact email</label>
              <input className="input-3d text-sm" value={form.contact_email} onChange={e => sf('contact_email', e.target.value)}
                placeholder="name@company.com" />
            </div>
          </div>

          <div>
            <label className="label">Phone</label>
            <input className="input-3d text-sm" value={form.phone} onChange={e => sf('phone', e.target.value)} />
          </div>

          {/* The lead's address is copied to the customer's billing address, which
              is what the first invoice needs — stated so it isn't a surprise. */}
          <div className="rounded-xl px-3 py-2.5 text-[11px] flex items-start gap-2"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
            <Building2 size={13} className="flex-shrink-0 mt-0.5" style={{ color: 'var(--accent)' }} />
            <span>
              The lead's address becomes the customer's billing address
              {lead.city ? ` (${[lead.city, lead.state].filter(Boolean).join(', ')})` : ''}.
              Its proposals move across too.
            </span>
          </div>

          <div className="space-y-2 pt-1">
            <Check label="Copy notes to the customer" hint="Keeps the conversation history with the record"
              checked={form.transfer_notes} onChange={v => sf('transfer_notes', v)} />
            <Check label="Copy custom field values" hint="Matched by field name where a customer field exists"
              checked={form.transfer_custom_fields} onChange={v => sf('transfer_custom_fields', v)} />
          </div>
        </div>

        <div className="flex items-center gap-3 px-5 py-4" style={{ borderTop: '1px solid var(--border)' }}>
          <button onClick={onCancel} className="flex-1 py-2.5 rounded-xl text-sm font-semibold"
            style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
            Cancel
          </button>
          <button onClick={() => onConfirm(form)} disabled={busy || !form.company.trim()}
            className="flex-[2] py-2.5 rounded-xl text-sm font-bold text-white inline-flex items-center justify-center gap-2 disabled:opacity-60"
            style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)' }}>
            {busy ? <Loader2 size={14} className="animate-spin" /> : <TrendingUp size={14} />} Convert
          </button>
        </div>
      </div>
    </div>,
    document.body,
  )
}

function Check({ label, hint, checked, onChange }) {
  return (
    <label className="flex items-start gap-2.5 cursor-pointer">
      <input type="checkbox" checked={checked} onChange={e => onChange(e.target.checked)}
        className="mt-0.5 w-4 h-4 rounded cursor-pointer" style={{ accentColor: '#7C3AED' }} />
      <span className="min-w-0">
        <span className="text-xs font-semibold block" style={{ color: 'var(--text-h)' }}>{label}</span>
        <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{hint}</span>
      </span>
    </label>
  )
}
