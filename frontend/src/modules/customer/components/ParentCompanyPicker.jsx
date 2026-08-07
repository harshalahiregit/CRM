import { useEffect, useState } from 'react'
import { customerApi } from '@/services/customerApi'

/**
 * "Parent Company" picker — links this customer to ANOTHER customer.
 *
 * Selecting an entry sets parent_client_id (a real relationship, so the profile
 * can link through to the parent and list its subsidiaries). The list also
 * carries name-only parents (id: null) — a holding company that exists as a
 * label on its subsidiaries but not as a customer record — and "Other…" lets a
 * brand-new name be typed, because the CSV import round-trips the parent by name
 * and may reference a company that isn't a customer.
 *
 * So the caller always receives BOTH fields: `parent_client_id` (may be null)
 * and `parent_company` (the display name, always set when a parent is chosen).
 */
const OTHER = '__other__'

export default function ParentCompanyPicker({ value, parentClientId, onChange, excludeId = null }) {
  const [options, setOptions] = useState([])
  const [loading, setLoading] = useState(true)
  // Free-text mode: an existing name that isn't in the list, or "Other…" picked.
  const [typing, setTyping] = useState(false)

  useEffect(() => {
    let alive = true
    customerApi.parentCompanies(excludeId)
      .then(list => { if (alive) setOptions(Array.isArray(list) ? list : []) })
      .catch(() => { if (alive) setOptions([]) })
      .finally(() => { if (alive) setLoading(false) })
    return () => { alive = false }
  }, [excludeId])

  // An already-saved name that no longer matches any option must stay editable.
  useEffect(() => {
    if (loading || typing || !value) return
    if (!options.some(o => o.name.toLowerCase() === value.trim().toLowerCase())) setTyping(true)
  }, [loading, options, value, typing])

  const selectValue = parentClientId
    ? `id:${parentClientId}`
    : value
      ? (options.find(o => o.id === null && o.name.toLowerCase() === value.trim().toLowerCase()) ? `name:${value.trim()}` : OTHER)
      : ''

  const onSelect = (raw) => {
    if (raw === '') { setTyping(false); return onChange({ parent_client_id: null, parent_company: '' }) }
    if (raw === OTHER) { setTyping(true); return onChange({ parent_client_id: null, parent_company: '' }) }
    setTyping(false)
    if (raw.startsWith('id:')) {
      const id = Number(raw.slice(3))
      const hit = options.find(o => o.id === id)
      return onChange({ parent_client_id: id, parent_company: hit?.name || '' })
    }
    return onChange({ parent_client_id: null, parent_company: raw.slice(5) })
  }

  if (loading) return <input className="input-3d text-sm" value="Loading…" readOnly style={{ opacity: 0.6 }} />

  return (
    <>
      <select className="input-3d text-sm" value={typing ? OTHER : selectValue} onChange={e => onSelect(e.target.value)}>
        <option value="">— None —</option>
        {options.map(o => (
          <option key={o.id ?? `name:${o.name}`} value={o.id ? `id:${o.id}` : `name:${o.name}`}>
            {o.name}{o.id ? '' : '  (name only)'}
          </option>
        ))}
        <option value={OTHER}>Other — type a new company…</option>
      </select>

      {typing && (
        <input
          className="input-3d text-sm mt-2"
          autoFocus
          placeholder="New parent company name"
          value={value || ''}
          onChange={e => onChange({ parent_client_id: null, parent_company: e.target.value })}
        />
      )}

      {parentClientId && (
        <p className="text-[11px] mt-1" style={{ color: '#10b981' }}>Linked to an existing customer</p>
      )}
    </>
  )
}
