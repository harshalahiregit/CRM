import { useState, useRef, useEffect, useMemo } from 'react'
import { ChevronDown, X, Search, Check } from 'lucide-react'

/**
 * Searchable MULTI-select — chips for what's chosen, type-to-search for the rest.
 *
 * Deliberately separate from ui/SearchableSelect rather than a `multiple` flag on
 * it: that component is controlled by a single string and is used across HR, and
 * threading an array through it would change the contract at every call site.
 * This one is controlled by an array of ids and shares its look, so the two sit
 * side by side without the module growing a second dropdown style.
 *
 * options: [{ id, label, sublabel? }] — ids, not bare strings, because two
 * vendors can legitimately share a name and a string list cannot tell them apart.
 *
 * value: array of ids.  onChange(nextIds).
 *
 * `primaryHint` marks the first chip. The caller decides what "first" means; here
 * it only affects the badge, so the component never silently reorders a set the
 * caller is treating as ordered.
 */
export default function MultiSearchSelect({
  value = [],
  onChange,
  options = [],
  placeholder = 'Search and select…',
  emptyText = 'No matches',
  disabled = false,
  loading = false,
  primaryHint = null,          // e.g. 'Primary' — labels the first selected chip
  max = null,
}) {
  const [open, setOpen]   = useState(false)
  const [query, setQuery] = useState('')
  const boxRef   = useRef(null)
  const inputRef = useRef(null)

  const byId = useMemo(() => new Map(options.map(o => [String(o.id), o])), [options])
  const selected = value.map(id => byId.get(String(id))).filter(Boolean)

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase()
    return options.filter(o => {
      if (value.some(v => String(v) === String(o.id))) return false   // already chosen
      if (!q) return true
      return `${o.label} ${o.sublabel || ''}`.toLowerCase().includes(q)
    })
  }, [options, query, value])

  useEffect(() => {
    const onDoc = (e) => { if (boxRef.current && !boxRef.current.contains(e.target)) { setOpen(false); setQuery('') } }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [])

  const atMax = max !== null && value.length >= max

  const add = (id) => {
    if (atMax) return
    onChange([...value, String(id)])
    setQuery('')
    // Stay open: choosing several in a row is the point of a multi-select.
    inputRef.current?.focus()
  }

  const remove = (id) => onChange(value.filter(v => String(v) !== String(id)))

  const onKeyDown = (e) => {
    if (e.key === 'Enter' && filtered.length) { e.preventDefault(); add(filtered[0].id) }
    // Backspace on an empty box removes the last chip — standard chip-input feel.
    else if (e.key === 'Backspace' && !query && value.length) remove(value[value.length - 1])
    else if (e.key === 'Escape') { setOpen(false); setQuery('') }
  }

  return (
    <div ref={boxRef} style={{ position: 'relative' }}>
      {/* Control: chips + the toggle */}
      <div
        onClick={() => { if (!disabled) { setOpen(true); inputRef.current?.focus() } }}
        style={{
          width: '100%', minHeight: 44, padding: '7px 34px 7px 10px', borderRadius: 12,
          background: 'var(--bg-input)', border: '1px solid var(--border)',
          display: 'flex', flexWrap: 'wrap', gap: 6, alignItems: 'center',
          cursor: disabled ? 'not-allowed' : 'text', opacity: disabled ? 0.6 : 1,
        }}
      >
        {selected.length === 0 && (
          <span style={{ fontSize: 13.5, color: 'var(--text-muted)' }}>
            {loading ? 'Loading…' : placeholder}
          </span>
        )}

        {selected.map((o, i) => (
          <span key={o.id}
            style={{
              display: 'inline-flex', alignItems: 'center', gap: 6, padding: '3px 8px',
              borderRadius: 8, fontSize: 12, fontWeight: 700,
              background: 'rgba(124,58,237,0.12)', color: '#a78bfa',
              border: '1px solid rgba(124,58,237,0.3)',
            }}>
            {o.label}
            {primaryHint && i === 0 && (
              <span style={{ fontSize: 9, fontWeight: 800, opacity: 0.8, textTransform: 'uppercase' }}>
                {primaryHint}
              </span>
            )}
            <button type="button" aria-label={`Remove ${o.label}`}
              onClick={(e) => { e.stopPropagation(); remove(o.id) }}
              style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'inherit', display: 'flex' }}>
              <X size={11} />
            </button>
          </span>
        ))}

        <ChevronDown size={15}
          style={{ position: 'absolute', right: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)', pointerEvents: 'none' }} />
      </div>

      {atMax && (
        <p style={{ fontSize: 10.5, color: 'var(--text-muted)', margin: '5px 0 0' }}>
          Maximum {max} selected.
        </p>
      )}

      {open && !disabled && (
        <div style={{
          position: 'absolute', zIndex: 40, left: 0, right: 0, marginTop: 5,
          background: 'var(--bg-card, #fff)', border: '1px solid var(--border)',
          borderRadius: 12, boxShadow: '0 12px 34px rgba(0,0,0,0.16)', overflow: 'hidden',
        }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 11px', borderBottom: '1px solid var(--border)' }}>
            <Search size={14} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
            <input
              ref={inputRef} autoFocus value={query}
              onChange={e => setQuery(e.target.value)} onKeyDown={onKeyDown}
              placeholder="Search…"
              style={{ flex: 1, border: 'none', outline: 'none', background: 'transparent', fontSize: 13.5, color: 'var(--text-h)' }}
            />
          </div>

          <div style={{ maxHeight: 220, overflowY: 'auto' }}>
            {filtered.length === 0 ? (
              <p style={{ padding: '12px 13px', margin: 0, fontSize: 12.5, color: 'var(--text-muted)' }}>
                {value.length && !query ? 'All options selected' : emptyText}
              </p>
            ) : filtered.map(o => (
              <button key={o.id} type="button" onClick={() => add(o.id)} disabled={atMax}
                style={{
                  width: '100%', textAlign: 'left', padding: '9px 13px', border: 'none',
                  background: 'transparent', cursor: atMax ? 'not-allowed' : 'pointer',
                  display: 'flex', alignItems: 'center', gap: 9, fontSize: 13, color: 'var(--text-h)',
                }}
                onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
                onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                <Check size={13} style={{ opacity: 0, flexShrink: 0 }} />
                <span style={{ minWidth: 0 }}>
                  <span style={{ display: 'block' }}>{o.label}</span>
                  {o.sublabel && (
                    <span style={{ display: 'block', fontSize: 11, color: 'var(--text-muted)' }}>{o.sublabel}</span>
                  )}
                </span>
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
