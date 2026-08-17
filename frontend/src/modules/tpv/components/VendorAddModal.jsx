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
 * fields: [{ name, label, type, required, options, placeholder, help }]
 *   type: 'text' | 'textarea' | 'number' | 'date' | 'select' | 'checkbox'
 */
export default function VendorAddModal({ title, fields, initial = {}, submitLabel = 'Save', onClose, onSubmit, onSaved, blockedReason = null }) {
  const [form, setForm] = useState(() => {
    const seed = {}
    fields.forEach(f => { seed[f.name] = initial[f.name] ?? (f.type === 'checkbox' ? false : '') })
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
      await onSubmit(payload)
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
      <p style={{ margin: '0 0 18px', fontSize: 11.5, color: 'var(--text-muted)' }}>
        Saved through the owning module — the same rules apply as adding it there.
      </p>

      {/* A prerequisite is missing (no workers to examine, no project to book
          against). Say so instead of showing a form whose only required field is
          an empty dropdown — the button stays visible so it never looks broken. */}
      {blockedReason && (
        <div style={{
          display: 'flex', alignItems: 'flex-start', gap: 8, padding: 12, borderRadius: 12,
          background: 'rgba(245,158,11,0.08)', border: '1px solid rgba(245,158,11,0.25)',
          fontSize: 12.5, color: 'var(--text-body)',
        }}>
          <AlertCircle size={14} style={{ color: 'var(--color-warning-500)', flexShrink: 0, marginTop: 1 }} />
          <span>{blockedReason}</span>
        </div>
      )}

      <div style={{ display: 'grid', gap: 12, opacity: blockedReason ? 0.45 : 1, pointerEvents: blockedReason ? 'none' : 'auto' }}>
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

      <ModalFooter onClose={onClose} onConfirm={save} loading={busy} disabled={!!blockedReason} confirmLabel={submitLabel} />
    </Overlay>
  )
}
