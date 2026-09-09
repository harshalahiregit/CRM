import { useState } from 'react'
import { AlertCircle } from 'lucide-react'
import { Overlay, ModalFooter } from '@/components/ui/kit3d'

/**
 * The one add-form for every vendor-detail section.
 *
 * A section describes its form as a FIELD LIST and hands over a submit function
 * that calls the module's existing endpoint — so adding a row from the vendor
 * screen is a second entry point to the same API, never a second copy of the
 * business rules. Validation stays server-side; this only enforces "required"
 * so the user is not made to wait on a round trip to learn a field was blank.
 *
 * fields: [{ name, label, type, required, options, placeholder, help, accept }]
 *   type: 'text' | 'textarea' | 'number' | 'date' | 'select' | 'checkbox' | 'file'
 *
 * A 'file' field holds the File itself, and any form carrying one is submitted
 * as multipart — a certificate is the evidence a medical or a training actually
 * happened, and a record that cannot carry its certificate is an assertion.
 */
export default function VendorAddModal({ title, fields, initial = {}, submitLabel = 'Save', onClose, onSubmit, onSaved, blockedReason = null }) {
  const [form, setForm] = useState(() => {
    const seed = {}
    fields.forEach(f => {
      seed[f.name] = initial[f.name] ?? (f.type === 'checkbox' ? false : f.type === 'file' ? null : '')
    })
    return seed
  })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const set = (name, value) => setForm(f => ({ ...f, [name]: value }))

  const missing = fields.filter(f => f.required && (form[f.name] === '' || form[f.name] == null))

  const save = async () => {
    if (missing.length) { setError(`${missing[0].label} is required.`); return }

    setBusy(true); setError(null)
    try {
      // Untouched optional fields hold '', and '' is not null — a rule like
      // `nullable|date` would run against it and reject. Drop them so an omitted
      // field is genuinely omitted. `false` is a real checkbox answer, so it stays.
      const payload = Object.fromEntries(
        Object.entries(form).filter(([, v]) => v !== '' && v !== null && v !== undefined)
      )

      // A File cannot travel as JSON. Once one is attached the whole form goes
      // as multipart, which is also why booleans are sent as 1/0 — FormData
      // stringifies everything, and "false" is truthy on the far side.
      const hasFile = fields.some(f => f.type === 'file' && payload[f.name] instanceof File)
      await onSubmit(hasFile ? toFormData(payload) : payload)
      onSaved?.()
      onClose()
    } catch (e) {
      // Services throw a normalised ApiError (title/message); axios errors carry
      // response.data.message. Read both so no failure shows as "undefined".
      setError(e?.title || e?.response?.data?.message || e?.message || 'Could not save.')
    } finally { setBusy(false) }
  }

  const inputStyle = {
    width: '100%', padding: '8px 10px', borderRadius: 9, fontSize: 12.5,
    background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)',
  }

  return (
    <Overlay onClose={busy ? () => {} : onClose} width={520}>
      <h3 style={{ margin: '0 0 4px', fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>{title}</h3>
      {!blockedReason && (
        <p style={{ margin: '0 0 18px', fontSize: 11.5, color: 'var(--text-muted)' }}>
          Saved through the owning module — the same rules apply as adding it there.
        </p>
      )}

      {/* A prerequisite is missing (no workers to examine, no project to book
          against). Say so INSTEAD of the form — a dead form below the warning
          is a tall modal whose buttons are pushed off the bottom of the screen,
          and every field in it is one nobody can submit. */}
      {blockedReason && (
        <div style={{
          display: 'flex', alignItems: 'flex-start', gap: 8, padding: 12, borderRadius: 12,
          marginTop: 14, lineHeight: 1.5,
          background: 'rgba(245,158,11,0.08)', border: '1px solid rgba(245,158,11,0.25)',
          fontSize: 12.5, color: 'var(--text-body)',
        }}>
          <AlertCircle size={14} style={{ color: 'var(--color-warning-500)', flexShrink: 0, marginTop: 1 }} />
          <span>{blockedReason}</span>
        </div>
      )}

      <div style={{ display: blockedReason ? 'none' : 'grid', gap: 12 }}>
        {fields.map(f => (
          <label key={f.name} style={{ display: 'block' }}>
            <span style={{ display: 'block', fontSize: 11.5, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 4 }}>
              {f.label}{f.required && <span style={{ color: '#ef4444' }}> *</span>}
            </span>

            {f.type === 'textarea' ? (
              <textarea rows={3} value={form[f.name]} placeholder={f.placeholder}
                onChange={e => set(f.name, e.target.value)} style={{ ...inputStyle, resize: 'vertical' }} />
            ) : f.type === 'select' ? (
              <select value={form[f.name]} onChange={e => set(f.name, e.target.value)} style={inputStyle}>
                <option value="">{f.placeholder || 'Select…'}</option>
                {(f.options || []).map(o => (
                  <option key={o.value} value={o.value}>{o.label}</option>
                ))}
              </select>
            ) : f.type === 'file' ? (
              <input type="file" accept={f.accept}
                onChange={e => set(f.name, e.target.files?.[0] ?? null)}
                style={{ ...inputStyle, padding: 6 }} />
            ) : f.type === 'checkbox' ? (
              <input type="checkbox" checked={!!form[f.name]} onChange={e => set(f.name, e.target.checked)}
                style={{ width: 16, height: 16, accentColor: '#7C3AED' }} />
            ) : (
              <input type={f.type || 'text'} value={form[f.name]} placeholder={f.placeholder}
                onChange={e => set(f.name, e.target.value)} style={inputStyle} />
            )}

            {f.help && <span style={{ display: 'block', fontSize: 11, color: 'var(--text-muted)', marginTop: 3 }}>{f.help}</span>}
          </label>
        ))}
      </div>

      {error && (
        <p style={{ margin: '14px 0 0', fontSize: 12.5, color: '#ef4444', whiteSpace: 'pre-wrap' }}>{error}</p>
      )}

      {/* Nothing to confirm when the form is not there. One button that closes
          beats a disabled one that looks like a failure. */}
      {blockedReason ? (
        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 22 }}>
          <button onClick={onClose} style={{ padding: '9px 20px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>
            Close
          </button>
        </div>
      ) : (
        <ModalFooter onClose={onClose} onConfirm={save} loading={busy} confirmLabel={submitLabel} />
      )}
    </Overlay>
  )
}

/** Flatten a form to multipart, with booleans as 1/0 so the server reads them. */
function toFormData(payload) {
  const fd = new FormData()

  Object.entries(payload).forEach(([k, v]) => {
    if (v instanceof File) fd.append(k, v)
    else if (typeof v === 'boolean') fd.append(k, v ? '1' : '0')
    else fd.append(k, v)
  })

  return fd
}
