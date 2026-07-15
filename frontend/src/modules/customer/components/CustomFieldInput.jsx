/**
 * Renders the right input for a custom-field definition type. Shared by the
 * customer drawer (Customers.jsx) and the profile Profile tab (CustomerDetail.jsx).
 */
export default function CustomFieldInput({ def, value, onChange }) {
  // `options` can arrive as an array (customer show endpoint) or a newline
  // string (raw custom-field definition list) — normalize to an array.
  const opts = Array.isArray(def.options)
    ? def.options
    : (def.options ? String(def.options).split(/\r?\n/).map(s => s.trim()).filter(Boolean) : [])

  if (def.type === 'textarea') {
    return <textarea rows={2} className="input-3d text-sm resize-none" value={value} onChange={e => onChange(e.target.value)} />
  }
  if (def.type === 'number') {
    return <input type="number" className="input-3d text-sm" value={value} onChange={e => onChange(e.target.value)} />
  }
  if (def.type === 'date_picker') {
    return <input type="date" className="input-3d text-sm" value={value} onChange={e => onChange(e.target.value)} />
  }
  if (def.type === 'checkbox') {
    return (
      <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-muted)' }}>
        <input type="checkbox" checked={value === '1' || value === true} onChange={e => onChange(e.target.checked ? '1' : '')} /> Yes
      </label>
    )
  }
  if (def.type === 'select' || def.type === 'multiselect') {
    return (
      <select className="input-3d text-sm" value={value} onChange={e => onChange(e.target.value)}>
        <option value="">— Select —</option>
        {opts.map(o => <option key={o} value={o}>{o}</option>)}
      </select>
    )
  }
  return <input className="input-3d text-sm" value={value} onChange={e => onChange(e.target.value)} />
}
