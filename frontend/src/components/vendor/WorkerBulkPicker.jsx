import { useEffect, useMemo, useState } from 'react'
import { Search } from 'lucide-react'
import { inputStyle } from '@/components/ui/kit3d'

/**
 * Pick many workers out of a large roster — built for 1000+.
 *
 * Engine-agnostic: it takes workers already normalised to
 *
 *   { id, name, code, status, trade, skill, site, project, vendor,
 *     ready: bool, reason: 'ready'|'inducted'|'medical'|'locked', reasonLabel }
 *
 * so TPV and Purchase (admin and portal alike) share the one picker.
 *
 * Why it looks like this:
 *  - Starts filtered to "ready" rows (the only ones a save can actually take),
 *    with a toggle to see everyone.
 *  - "Select all N matching" selects every row matching the CURRENT filters —
 *    not just the rows on screen — so a whole trade or site is one click.
 *  - Renders 50 rows at a time with "Show more"; a 1000-row roster never puts
 *    1000 checkboxes in the DOM at once.
 *  - Filters only appear when the roster actually has values for them.
 */
const PAGE = 50

const FILTERS = [
  { key: 'trade',   label: 'Trade' },
  { key: 'skill',   label: 'Skill' },
  { key: 'site',    label: 'Site' },
  { key: 'project', label: 'Project' },
  { key: 'vendor',  label: 'Vendor' },
  { key: 'status',  label: 'Status' },
]

const REASON_TONE = {
  ready:    { color: '#10b981', bg: 'rgba(16,185,129,0.14)' },
  inducted: { color: '#64748b', bg: 'rgba(100,116,139,0.16)' },
  medical:  { color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  locked:   { color: '#ef4444', bg: 'rgba(239,68,68,0.13)' },
}

const chipBtn = (active) => ({
  padding: '6px 12px', borderRadius: 20, border: '1.5px solid', fontSize: 11.5, fontWeight: 800, cursor: 'pointer',
  background: active ? '#7c3aed' : 'var(--bg-input)', color: active ? '#fff' : 'var(--text-muted)',
  borderColor: active ? '#7c3aed' : 'var(--border)', whiteSpace: 'nowrap',
})

export default function WorkerBulkPicker({ workers, selectedIds, onChange, max = 1000 }) {
  const [query, setQuery]         = useState('')
  const [readyOnly, setReadyOnly] = useState(true)
  const [selectedOnly, setSelectedOnly] = useState(false)
  const [filters, setFilters]     = useState({})
  const [limit, setLimit]         = useState(PAGE)

  const selected = useMemo(() => new Set(selectedIds), [selectedIds])

  // Distinct values per filter — a filter with nothing to choose from is not shown.
  const options = useMemo(() => {
    const out = {}
    for (const { key } of FILTERS) {
      const vals = new Set()
      for (const w of workers) if (w[key]) vals.add(String(w[key]))
      out[key] = [...vals].sort((a, b) => a.localeCompare(b))
    }
    return out
  }, [workers])
  const visibleFilters = FILTERS.filter(f => options[f.key].length > (f.key === 'vendor' ? 1 : 0))

  const readyCount = useMemo(() => workers.filter(w => w.ready).length, [workers])

  const matching = useMemo(() => {
    const q = query.trim().toLowerCase()
    return workers.filter(w => {
      if (selectedOnly && !selected.has(w.id)) return false
      if (readyOnly && !selectedOnly && !w.ready) return false
      for (const { key } of FILTERS) {
        if (filters[key] && String(w[key] || '') !== filters[key]) return false
      }
      if (!q) return true
      return (w.name || '').toLowerCase().includes(q) || (w.code || '').toLowerCase().includes(q)
    })
  }, [workers, query, readyOnly, selectedOnly, filters, selected])

  // A new filter starts from the top of its list again.
  useEffect(() => { setLimit(PAGE) }, [query, readyOnly, selectedOnly, filters])

  const shown = matching.slice(0, limit)
  const matchingSelected = useMemo(() => matching.reduce((n, w) => n + (selected.has(w.id) ? 1 : 0), 0), [matching, selected])
  const hiddenSelected = selectedIds.length - matchingSelected

  const toggle = (id) => onChange(selected.has(id) ? selectedIds.filter(x => x !== id) : [...selectedIds, id])
  const selectAllMatching = () => {
    const next = new Set(selectedIds)
    for (const w of matching) next.add(w.id)
    onChange([...next])
  }
  const clearAll = () => onChange([])
  const setFilter = (key) => (e) => setFilters(p => ({ ...p, [key]: e.target.value }))
  const anyFilter = query || Object.values(filters).some(Boolean)

  return (
    <div style={{ border: '1px solid var(--border)', borderRadius: 12, background: 'var(--bg-input)', padding: 12, marginBottom: 16 }}>
      {/* Search + readiness toggle */}
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center', marginBottom: 10 }}>
        <div style={{ position: 'relative', flex: '1 1 220px', minWidth: 180 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={query} onChange={e => setQuery(e.target.value)} placeholder="Search by name or worker code…"
            style={{ ...inputStyle, paddingLeft: 30 }} />
        </div>
        <button type="button" onClick={() => { setReadyOnly(true); setSelectedOnly(false) }} style={chipBtn(readyOnly && !selectedOnly)}>
          ✓ Ready for induction ({readyCount})
        </button>
        <button type="button" onClick={() => { setReadyOnly(false); setSelectedOnly(false) }} style={chipBtn(!readyOnly && !selectedOnly)}>
          Show all ({workers.length})
        </button>
        <button type="button" onClick={() => setSelectedOnly(v => !v)} style={chipBtn(selectedOnly)}>
          Selected only ({selectedIds.length})
        </button>
      </div>

      {/* Filters — only those the roster has values for */}
      {visibleFilters.length > 0 && (
        <div style={{ display: 'grid', gridTemplateColumns: `repeat(${Math.min(visibleFilters.length, 4)}, minmax(0,1fr))`, gap: 8, marginBottom: 10 }}>
          {visibleFilters.map(f => (
            <select key={f.key} value={filters[f.key] || ''} onChange={setFilter(f.key)} style={{ ...inputStyle, padding: '7px 10px', fontSize: 12 }} aria-label={f.label}>
              <option value="">All {f.label.toLowerCase()}s</option>
              {options[f.key].map(v => <option key={v} value={v}>{v}</option>)}
            </select>
          ))}
        </div>
      )}

      {/* Bulk actions + count */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginBottom: 8 }}>
        <button type="button" onClick={selectAllMatching} disabled={matching.length === 0}
          style={{ padding: '6px 12px', borderRadius: 8, border: 'none', background: 'linear-gradient(135deg,#7c3aed,#6d28d9)', color: '#fff', fontSize: 12, fontWeight: 800, cursor: matching.length ? 'pointer' : 'not-allowed', opacity: matching.length ? 1 : 0.5 }}>
          Select all {matching.length} matching
        </button>
        <button type="button" onClick={clearAll} disabled={selectedIds.length === 0}
          style={{ padding: '6px 12px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', fontSize: 12, fontWeight: 700, cursor: selectedIds.length ? 'pointer' : 'not-allowed' }}>
          Clear
        </button>
        {anyFilter && (
          <button type="button" onClick={() => { setQuery(''); setFilters({}) }}
            style={{ padding: '6px 10px', borderRadius: 8, border: 'none', background: 'transparent', color: '#7c3aed', fontSize: 12, fontWeight: 700, cursor: 'pointer' }}>
            Reset filters
          </button>
        )}
        <span style={{ marginLeft: 'auto', padding: '4px 12px', borderRadius: 20, background: selectedIds.length ? 'rgba(124,58,237,0.16)' : 'var(--bg-card)', color: selectedIds.length ? '#a78bfa' : 'var(--text-muted)', fontSize: 12, fontWeight: 900 }}>
          {selectedIds.length} selected
          {hiddenSelected > 0 && <span style={{ fontWeight: 600 }}> · {hiddenSelected} not in this view</span>}
        </span>
      </div>
      {selectedIds.length > max && (
        <p style={{ margin: '0 0 8px', fontSize: 12, color: '#ef4444', fontWeight: 700 }}>
          A session can hold at most {max} workers — narrow the selection to save.
        </p>
      )}

      {/* The rows — a page at a time */}
      <div style={{ maxHeight: 300, overflowY: 'auto', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
        {shown.length === 0 ? (
          <div style={{ padding: 20, textAlign: 'center', fontSize: 12.5, color: 'var(--text-muted)' }}>
            {workers.length === 0
              ? 'No workers to show.'
              : readyOnly && !selectedOnly
                ? 'No worker matching these filters is ready for induction. Use “Show all” to see everyone.'
                : 'No worker matches these filters.'}
          </div>
        ) : shown.map(w => {
          const on = selected.has(w.id)
          const tone = REASON_TONE[w.reason] || REASON_TONE.ready
          const meta = [w.trade || w.skill, w.site || w.project, w.vendor].filter(Boolean).join(' · ')
          return (
            <label key={w.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '7px 12px', borderBottom: '1px solid var(--border)', cursor: 'pointer', background: on ? 'rgba(124,58,237,0.08)' : 'transparent' }}>
              <input type="checkbox" checked={on} onChange={() => toggle(w.id)} style={{ width: 15, height: 15, cursor: 'pointer', accentColor: '#7c3aed' }} />
              <div style={{ minWidth: 0, flex: 1 }}>
                <div style={{ fontSize: 12.5, fontWeight: on ? 800 : 600, color: 'var(--text-h)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                  {w.name || '—'} <span style={{ color: 'var(--text-muted)', fontWeight: 500 }}>({w.code || `#${w.id}`})</span>
                </div>
                {meta && <div style={{ fontSize: 11, color: 'var(--text-muted)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{meta}</div>}
              </div>
              <span style={{ fontSize: 10.5, fontWeight: 800, padding: '2px 8px', borderRadius: 20, color: tone.color, background: tone.bg, whiteSpace: 'nowrap' }}>
                {w.reasonLabel}
              </span>
            </label>
          )
        })}
        {matching.length > shown.length && (
          <button type="button" onClick={() => setLimit(l => l + PAGE)}
            style={{ width: '100%', padding: 9, border: 'none', background: 'var(--bg-input)', color: '#a78bfa', fontSize: 12, fontWeight: 800, cursor: 'pointer' }}>
            Show {Math.min(PAGE, matching.length - shown.length)} more ({matching.length - shown.length} remaining)
          </button>
        )}
      </div>
    </div>
  )
}
