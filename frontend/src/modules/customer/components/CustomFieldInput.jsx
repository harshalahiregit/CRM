/**
 * Renders the right input for a custom-field definition type. Shared by the
 * customer drawer (Customers.jsx), the profile Profile tab (CustomerDetail.jsx)
 * and the contact drawer. Matches the legacy CRM's render_custom_field types.
 */
export default function CustomFieldInput({ def, value, onChange }) {
  // `options` can arrive as an array (customer show endpoint) or a newline/
  // comma string (raw custom-field definition list) — normalize to an array.
  const opts = Array.isArray(def.options)
    ? def.options
    : (def.options ? String(def.options).split(/\r?\n|,/).map(s => s.trim()).filter(Boolean) : [])

  const inline = !!def.display_inline

  if (def.type === 'textarea') {
    return <textarea rows={2} className="input-3d text-sm resize-none" value={value || ''} onChange={e => onChange(e.target.value)} />
  }
  if (def.type === 'number') {
    return <input type="number" className="input-3d text-sm" value={value || ''} onChange={e => onChange(e.target.value)} />
  }
  if (def.type === 'date_picker') {
    return <input type="date" className="input-3d text-sm" value={value || ''} onChange={e => onChange(e.target.value)} />
  }
  if (def.type === 'date_picker_time') {
    return <input type="datetime-local" className="input-3d text-sm" value={value || ''} onChange={e => onChange(e.target.value)} />
  }
  if (def.type === 'colorpicker') {
    return (
      <div className="flex items-center gap-2">
        <input type="color" className="w-10 h-9 rounded-lg cursor-pointer" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }} value={value || '#7c3aed'} onChange={e => onChange(e.target.value)} />
        <span className="text-xs font-mono" style={{ color: 'var(--text-muted)' }}>{value || '—'}</span>
      </div>
    )
  }
  if (def.type === 'link') {
    return <input type="url" className="input-3d text-sm" placeholder="https://…" value={value || ''} onChange={e => onChange(e.target.value)} />
  }
  if (def.type === 'radio') {
    return (
      <div className={inline ? 'flex flex-wrap gap-4' : 'flex flex-col gap-2'}>
        {opts.map(o => (
          <label key={o} className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-h)' }}>
            <input type="radio" checked={value === o} onChange={() => onChange(o)} /> {o}
          </label>
        ))}
      </div>
    )
  }
  if (def.type === 'checkbox' || def.type === 'multiselect') {
    // Multi-value: value is an array of selected options.
    const arr = Array.isArray(value) ? value : (value ? [value] : [])
    const toggle = (o) => onChange(arr.includes(o) ? arr.filter(x => x !== o) : [...arr, o])
    return (
      <div className={inline ? 'flex flex-wrap gap-4' : 'flex flex-col gap-2'}>
        {opts.map(o => (
          <label key={o} className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--text-h)' }}>
            <input type="checkbox" checked={arr.includes(o)} onChange={() => toggle(o)} /> {o}
          </label>
        ))}
      </div>
    )
  }
  if (def.type === 'select') {
    return (
      <select className="input-3d text-sm" value={value || ''} onChange={e => onChange(e.target.value)}>
        <option value="">— Select —</option>
        {opts.map(o => <option key={o} value={o}>{o}</option>)}
      </select>
    )
  }
  return <input className="input-3d text-sm" value={value || ''} onChange={e => onChange(e.target.value)} />
}

/** Maps a field's bs_column (12/6/4/3) to a responsive flex-basis width. */
export function cfWidthStyle(bsColumn) {
  const col = Number(bsColumn) || 12
  const pct = { 12: '100%', 6: 'calc(50% - 8px)', 4: 'calc(33.333% - 11px)', 3: 'calc(25% - 12px)' }[col] || '100%'
  return { flexBasis: pct, maxWidth: '100%' }
}
