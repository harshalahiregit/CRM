import { Search, X } from 'lucide-react'

/**
 * Review comment #3 — "Filter option in every listing."
 *
 * The shared search + dropdown bar for listings that had none. Deliberately
 * generic: it owns no state and knows nothing about what it is filtering, so the
 * page keeps its own filter state and decides whether that state is sent to the
 * server or applied in memory.
 *
 * It is NOT a second copy of Learning & Development's local `FilterBar`. That one
 * bundles a fixed Active/Inactive status and an "Add" button, so it can only
 * serve master screens shaped exactly like L&D's; it is used at ten call sites
 * there and rewriting them was not worth the risk. This is the piece those pages
 * could not reuse: filters only, any number of them, no Add button.
 *
 * selects: [{ key, label, value, onChange, options: [string | {value,label}] }]
 */
export default function ListFilter({
  search,
  setSearch,
  placeholder = 'Search…',
  selects = [],
  onClear,
  right = null,
}) {
  // "Is anything filtering?" is derived, never tracked separately — a stored flag
  // drifts out of step with the values the moment one of them is reset elsewhere.
  const active = Boolean(search) || selects.some(s => s.value && s.value !== 'All' && s.value !== '')

  return (
    <div className="card-3d" style={{ padding: '14px 16px' }}>
      <div className="flex gap-3 flex-wrap items-end">
        {setSearch && (
          <div className="relative flex-1 min-w-[190px]">
            <label className="label">Search</label>
            <Search size={14} className="absolute left-3 top-[34px]" style={{ color: 'var(--text-muted)' }} />
            <input
              className="input-3d pl-9 text-sm"
              placeholder={placeholder}
              value={search}
              onChange={e => setSearch(e.target.value)}
            />
          </div>
        )}

        {selects.map(s => (
          <div key={s.key} className="min-w-[140px]">
            <label className="label">{s.label}</label>
            <select className="input-3d text-sm" value={s.value} onChange={e => s.onChange(e.target.value)}>
              {s.options.map(o => {
                const value = typeof o === 'object' ? o.value : o
                const label = typeof o === 'object' ? o.label : o
                return <option key={String(value)} value={value}>{label}</option>
              })}
            </select>
          </div>
        ))}

        {active && onClear && (
          <button
            onClick={onClear}
            className="px-3 py-2.5 rounded-xl text-xs font-bold inline-flex items-center gap-1.5"
            style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}
          >
            <X size={12} /> Clear
          </button>
        )}

        {right && <div className="ml-auto">{right}</div>}
      </div>
    </div>
  )
}

/**
 * The in-memory counterpart, for listings whose endpoint has no filter params.
 *
 * Case-insensitive substring match over the named fields. Returns the rows
 * untouched when nothing is set, so a page can call it unconditionally.
 */
export function applyListFilter(rows, { search = '', fields = [], matchers = [] } = {}) {
  let out = Array.isArray(rows) ? rows : []

  const q = search.trim().toLowerCase()
  if (q) {
    out = out.filter(r => fields.some(f => String(r?.[f] ?? '').toLowerCase().includes(q)))
  }

  // Each matcher is [value, predicate]; an empty/'All' value is a no-op rather
  // than a filter that silently removes every row.
  for (const [value, predicate] of matchers) {
    if (value === '' || value === 'All' || value === undefined || value === null) continue
    out = out.filter(r => predicate(r, value))
  }

  return out
}
