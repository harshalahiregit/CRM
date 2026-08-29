import Select from './Select'

/**
 * Drop-in replacement for kit3d's <SelectInput> that renders the searchable
 * popover Select (the same one Tasks/Helpdesk tickets use) WITHOUT any call-site
 * changes. It accepts the exact SelectInput API — `value`, `onChange(event)`,
 * `options` ([[value,label]] with `pairs`, or [value]), `disabled`, `placeholder`
 * — and adapts it onto Select's `value` / `onChange(value)` / `options:[{value,label}]`
 * shape. A filter box auto-appears once a list is long, so long dropdowns become
 * type-to-search while short enums stay clean.
 *
 * Swap only the import in a file (kit3d → this) and every dropdown there becomes
 * searchable; the JSX stays untouched because the prop contract is identical.
 */
export default function SearchableSelectInput({
  value,
  onChange,
  options = [],
  pairs = false,
  disabled = false,
  placeholder = 'Select…',
  searchable = 'auto',
  style = {},
}) {
  const opts = (options || []).map((o) =>
    pairs
      ? { value: String(o?.[0] ?? ''), label: o?.[1] ?? '' }
      : { value: String(o ?? ''), label: String(o ?? '') }
  )

  return (
    <Select
      value={value == null ? '' : String(value)}
      // kit3d callers expect an event-like arg (they read e.target.value).
      onChange={(v) => onChange?.({ target: { value: v } })}
      options={opts}
      disabled={disabled}
      placeholder={placeholder}
      searchable={searchable}
      style={style}
    />
  )
}
