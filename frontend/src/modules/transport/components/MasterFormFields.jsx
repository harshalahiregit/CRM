import { VEHICLE_OWNERSHIP } from '../constants'

/**
 * Form primitives shared by the Vehicle and Driver forms.
 *
 * Same Section/Field shape as TransportOrderForm, so the three master screens
 * read as one module rather than three. Kept here instead of duplicated because
 * two copies of a form primitive drift apart on the first style change.
 */

export const inputStyle = { width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }
export const selectStyle = { ...inputStyle, cursor: 'pointer' }
export const readonlyStyle = { ...inputStyle, background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'not-allowed' }

export function Section({ icon: Icon, title, children, cols = 2 }) {
  return (
    <div style={{ marginBottom: 6 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, margin: '4px 0 12px' }}>
        {Icon && <Icon size={15} style={{ color: '#7C3AED' }} />}
        <h3 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: 0, textTransform: 'uppercase', letterSpacing: '.03em' }}>{title}</h3>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: `repeat(${cols}, 1fr)`, gap: 12 }}>{children}</div>
    </div>
  )
}

export function Field({ label, required, children, full, hint }) {
  return (
    <div style={full ? { gridColumn: '1 / -1' } : undefined}>
      <label className="label" style={{ display: 'block', marginBottom: 4 }}>
        {label}{required && <span style={{ color: '#ef4444' }}> *</span>}
      </label>
      {children}
      {hint && <p style={{ fontSize: 11, color: 'var(--text-muted)', margin: '4px 0 0' }}>{hint}</p>}
    </div>
  )
}

/** A status pill. UX §129: the label always travels with the colour. */
export function Chip({ cfg, size = 12 }) {
  return (
    <span style={{ padding: '3px 9px', borderRadius: 999, fontSize: size, fontWeight: 700, color: cfg.color, background: cfg.bg, whiteSpace: 'nowrap' }}>
      {cfg.label}
    </span>
  )
}

export function FilterChip({ active, onClick, label, count, cfg }) {
  return (
    <button onClick={onClick}
      style={{
        padding: '6px 12px', borderRadius: 999, fontSize: 12, fontWeight: 700, cursor: 'pointer',
        border: `1px solid ${active ? (cfg?.color || '#7C3AED') : 'var(--border)'}`,
        background: active ? (cfg?.bg || 'rgba(124,58,237,0.14)') : 'var(--bg-input)',
        color: active ? (cfg?.color || '#a78bfa') : 'var(--text-p)',
        display: 'inline-flex', alignItems: 'center', gap: 6,
      }}>
      {label}
      <span style={{ fontSize: 11, opacity: 0.75 }}>{count ?? 0}</span>
    </button>
  )
}

export { VEHICLE_OWNERSHIP }
