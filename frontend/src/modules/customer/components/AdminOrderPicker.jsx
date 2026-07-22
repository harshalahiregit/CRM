import { useState, useMemo } from 'react'
import { Search, X, ChevronUp, ChevronDown } from 'lucide-react'

/**
 * Ordered account-handler selector (meeting 1.3): type-ahead search over staff
 * + an ordered list with fallback badges (Primary → 2nd → 3rd …), reorder
 * arrows and remove. `value` is an ordered array of user ids.
 */
const ORDINALS = ['Primary', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th']

export default function AdminOrderPicker({ staff = [], value = [], onChange }) {
  const [query, setQuery] = useState('')
  const [open, setOpen] = useState(false)

  const byId = useMemo(() => Object.fromEntries(staff.map(s => [s.id, s])), [staff])
  const picked = value.map(id => byId[id]).filter(Boolean)

  const matches = useMemo(() => {
    const q = query.trim().toLowerCase()
    return staff
      .filter(s => !value.includes(s.id))
      .filter(s => !q || s.name?.toLowerCase().includes(q) || s.email?.toLowerCase().includes(q))
      .slice(0, 8)
  }, [staff, value, query])

  const add = (id) => { onChange([...value, id]); setQuery(''); setOpen(false) }
  const remove = (id) => onChange(value.filter(v => v !== id))
  const move = (idx, dir) => {
    const next = [...value]
    const j = idx + dir
    if (j < 0 || j >= next.length) return
    ;[next[idx], next[j]] = [next[j], next[idx]]
    onChange(next)
  }

  return (
    <div className="space-y-3">
      {/* Type-ahead search */}
      <div className="relative">
        <Search size={13} className="absolute left-3.5 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
        <input
          className="input-3d text-sm pl-9"
          placeholder="Search staff by name or email…"
          value={query}
          onChange={e => { setQuery(e.target.value); setOpen(true) }}
          onFocus={() => setOpen(true)}
          onBlur={() => setTimeout(() => setOpen(false), 150)}
        />
        {open && matches.length > 0 && (
          <div className="absolute z-20 left-0 right-0 mt-1 rounded-xl p-1.5 shadow-2xl max-h-56 overflow-y-auto"
            style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            {matches.map(s => (
              <button key={s.id} type="button" onMouseDown={e => e.preventDefault()} onClick={() => add(s.id)}
                className="w-full flex items-center justify-between px-3 py-2 rounded-lg text-left hover:bg-[rgba(124,58,237,0.06)]">
                <span className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{s.name}</span>
                <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{s.email}</span>
              </button>
            ))}
          </div>
        )}
      </div>

      {/* Ordered handler list */}
      {picked.length === 0 ? (
        <p className="text-xs py-3 text-center rounded-xl" style={{ color: 'var(--text-muted)', background: 'var(--bg-input)', border: '1px dashed var(--border)' }}>
          No account handlers yet — search above to add. The first is the primary contact; the rest are fallbacks in order.
        </p>
      ) : (
        <div className="space-y-1.5">
          {picked.map((s, i) => (
            <div key={s.id} className="flex items-center gap-3 px-3 py-2 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
              <span className="text-[10px] font-black px-2 py-0.5 rounded-md whitespace-nowrap"
                style={i === 0
                  ? { background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff' }
                  : { background: 'var(--bg-card)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                {ORDINALS[i] || `${i + 1}th`}
              </span>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-semibold truncate" style={{ color: 'var(--text-h)' }}>{s.name}</p>
                <p className="text-[11px] truncate" style={{ color: 'var(--text-muted)' }}>{s.email}</p>
              </div>
              <div className="flex items-center gap-0.5">
                <button type="button" onClick={() => move(i, -1)} disabled={i === 0} className="p-1 rounded-lg disabled:opacity-25 hover:bg-[rgba(124,58,237,0.08)]"><ChevronUp size={13} style={{ color: 'var(--text-muted)' }} /></button>
                <button type="button" onClick={() => move(i, +1)} disabled={i === picked.length - 1} className="p-1 rounded-lg disabled:opacity-25 hover:bg-[rgba(124,58,237,0.08)]"><ChevronDown size={13} style={{ color: 'var(--text-muted)' }} /></button>
                <button type="button" onClick={() => remove(s.id)} className="p-1 rounded-lg hover:bg-[rgba(239,68,68,0.08)]"><X size={13} style={{ color: '#f87171' }} /></button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
