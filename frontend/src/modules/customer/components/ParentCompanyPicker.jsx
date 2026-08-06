import { useEffect, useState } from 'react'
import { customerApi } from '@/services/customerApi'

/**
 * "Parent Company" combobox: pick a company already in the workspace, or type a
 * brand-new name.
 *
 * A native <datalist> rather than a custom dropdown, deliberately — it gives
 * type-to-filter and the full list on click, keeps the value free text (so a new
 * parent needs no pre-registration), and inherits the theme from .input-3d, so
 * there is no separate dark-mode surface to maintain.
 *
 * `excludeId` is passed when editing so a company is never offered as its own
 * parent. Suggestions failing to load is non-fatal: the field degrades to the
 * plain text input it was before.
 */
export default function ParentCompanyPicker({ value, onChange, excludeId = null, id = 'parent-company-options' }) {
  const [options, setOptions] = useState([])

  useEffect(() => {
    let alive = true
    customerApi.parentCompanies(excludeId)
      .then(list => { if (alive) setOptions(Array.isArray(list) ? list : []) })
      .catch(() => { if (alive) setOptions([]) })
    return () => { alive = false }
  }, [excludeId])

  const isNew = value?.trim() && !options.some(o => o.toLowerCase() === value.trim().toLowerCase())

  return (
    <>
      <input
        className="input-3d text-sm"
        list={id}
        value={value || ''}
        onChange={e => onChange(e.target.value)}
        placeholder={options.length ? 'Select or type a company…' : 'e.g. UBCPL Group'}
        autoComplete="off"
      />
      <datalist id={id}>
        {options.map(name => <option key={name} value={name} />)}
      </datalist>
      {isNew && (
        <p className="text-[11px] mt-1" style={{ color: 'var(--accent)' }}>
          New parent company — will be saved as “{value.trim()}”
        </p>
      )}
    </>
  )
}
