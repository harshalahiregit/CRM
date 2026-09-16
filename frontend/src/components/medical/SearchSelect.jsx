import { useEffect, useRef, useState } from 'react'
import { Search, X, ChevronDown, Check } from 'lucide-react'
import { S } from './MedicalBits'

/**
 * A picker for a list too long to be a dropdown.
 *
 * A native `<select>` is fine for six options and useless for a thousand: there
 * is no way to search it, the browser renders every option, and a server that
 * caps the list at some round number produces a menu that silently stops — the
 * thousand-and-first vendor simply does not exist as far as the doctor can tell,
 * with nothing on screen admitting it.
 *
 * So: type to search, results come from the server, and the count of what is
 * NOT being shown is stated rather than hidden. Clearing the box is a first-
 * class action, because with search as an optional filter "all of them" is a
 * real answer and not an accident.
 */
export default function SearchSelect({
  value,
  label,
  placeholder = 'Search…',
  emptyLabel = 'All',
  onSearch,
  onSelect,
  options = [],
  meta,
  loading,
}) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const boxRef = useRef(null)
  const debounce = useRef(null)

  // A click anywhere else closes it — the usual expectation for a menu, and
  // the reason this needs a ref at all.
  useEffect(() => {
    if (!open) return
    const away = (e) => { if (boxRef.current && !boxRef.current.contains(e.target)) setOpen(false) }
    document.addEventListener('mousedown', away)
    return () => document.removeEventListener('mousedown', away)
  }, [open])

  // The server hears about typing once it stops. A request per keystroke over a
  // thousand rows is a list that flickers and a database that works for nothing.
  useEffect(() => {
    if (!onSearch || !open) return
    clearTimeout(debounce.current)
    debounce.current = setTimeout(() => onSearch(query), 250)
    return () => clearTimeout(debounce.current)
  }, [query, open]) // eslint-disable-line react-hooks/exhaustive-deps

  const selected = options.find(o => String(o.id) === String(value))

  const choose = (option) => {
    onSelect(option ? String(option.id) : '')
    setOpen(false)
    setQuery('')
  }

  return (
    <div ref={boxRef} style={{ position: 'relative' }}>
      {label && <label style={S.label}>{label}</label>}

      <button type="button" onClick={() => setOpen(o => !o)}
        style={{
          ...S.select, width: '100%', minHeight: 44, textAlign: 'left',
          display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer',
        }}>
        <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: selected ? 'var(--text-h)' : 'var(--text-muted)' }}>
          {selected ? `${selected.name}${selected.code ? ` · ${selected.code}` : ''}` : emptyLabel}
        </span>
        {selected && (
          <span role="button" tabIndex={0} aria-label="Clear"
            onClick={e => { e.stopPropagation(); choose(null) }}
            onKeyDown={e => { if (e.key === 'Enter') { e.stopPropagation(); choose(null) } }}
            style={{ display: 'flex', color: 'var(--text-muted)' }}>
            <X size={14} />
          </span>
        )}
        <ChevronDown size={15} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
      </button>

      {open && (
        <div style={{
          position: 'absolute', zIndex: 40, top: 'calc(100% + 4px)', left: 0, right: 0,
          borderRadius: 12, border: '1px solid var(--border)', background: 'var(--bg-card)',
          boxShadow: '0 18px 40px -12px rgba(0,0,0,.55)', overflow: 'hidden',
        }}>
          <div style={{ position: 'relative', padding: 8, borderBottom: '1px solid var(--border)' }}>
            <Search size={14} style={{ position: 'absolute', left: 18, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
            <input autoFocus value={query} onChange={e => setQuery(e.target.value)} placeholder={placeholder}
              style={{ ...S.input, paddingLeft: 32, minHeight: 40 }} />
          </div>

          <div style={{ maxHeight: 260, overflowY: 'auto' }}>
            <button type="button" onClick={() => choose(null)}
              style={{ ...ROW, color: !value ? '#a78bfa' : 'var(--text-muted)', fontWeight: !value ? 800 : 600 }}>
              {!value && <Check size={14} />} {emptyLabel}
            </button>

            {loading ? (
              <p style={NOTE}>Searching…</p>
            ) : options.length === 0 ? (
              <p style={NOTE}>{query ? `Nothing matches “${query}”.` : 'Nothing to show.'}</p>
            ) : options.map(o => (
              <button key={o.id} type="button" onClick={() => choose(o)}
                style={{ ...ROW, color: String(o.id) === String(value) ? '#a78bfa' : 'var(--text-h)' }}>
                {String(o.id) === String(value) && <Check size={14} />}
                <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                  {o.name}
                </span>
                {o.code && <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>{o.code}</span>}
              </button>
            ))}
          </div>

          {/* Said plainly, rather than letting the list stop and look complete. */}
          {meta?.truncated && (
            <div style={{ padding: '8px 12px', borderTop: '1px solid var(--border)', fontSize: 11, color: 'var(--text-muted)' }}>
              Showing {meta.showing} of {meta.total.toLocaleString()} — type to narrow it down.
            </div>
          )}
        </div>
      )}
    </div>
  )
}

const ROW = {
  display: 'flex', alignItems: 'center', gap: 8, width: '100%',
  minHeight: 42, padding: '9px 12px', border: 'none', background: 'transparent',
  cursor: 'pointer', fontSize: 12.5, textAlign: 'left',
}

const NOTE = { padding: '14px 12px', margin: 0, fontSize: 12, color: 'var(--text-muted)' }
