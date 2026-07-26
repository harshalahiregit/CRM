import { useState, useRef, useEffect, useMemo } from 'react'
import { ChevronDown, X, Search, Check } from 'lucide-react'

/**
 * Searchable single-select combobox (SPK-1).
 *
 * Styled to match the native <select> fields (same input-3d shell + the same
 * chevron) so the module has ONE dropdown look — no visual mix of native and
 * custom selects. Adds what a native select can't: type-to-search, a clear
 * button, keyboard navigation, an empty state, and (optionally) creating a new
 * value when the source has no separate management module.
 *
 * Controlled: `value` is a string, `onChange(nextValue)`. Options are plain
 * strings supplied by the parent (loaded from an existing API — never hardcoded).
 */
export default function SearchableSelect({
  value = '',
  onChange,
  options = [],
  placeholder = 'Select…',
  emptyText = 'No options found',
  allowCreate = false,       // let the user keep a typed value the list doesn't contain
  disabled = false,
  loading = false,
}) {
  const [open, setOpen]   = useState(false)
  const [query, setQuery] = useState('')
  const [hi, setHi]       = useState(0)   // highlighted index
  const boxRef  = useRef(null)
  const listRef = useRef(null)

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase()
    const base = options.filter(o => o && (!q || o.toLowerCase().includes(q)))
    return base
  }, [options, query])

  // When creating is allowed and the typed query isn't already an option, offer it.
  const typed = query.trim()
  const showCreate = allowCreate && typed && !options.some(o => o.toLowerCase() === typed.toLowerCase())
  const rows = showCreate ? [...filtered, { __create: typed }] : filtered

  useEffect(() => {
    const onDoc = (e) => { if (boxRef.current && !boxRef.current.contains(e.target)) close() }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [])

  const openMenu = () => { if (disabled) return; setOpen(true); setQuery(''); setHi(0) }
  const close    = () => { setOpen(false); setQuery('') }
  const pick     = (row) => { onChange?.(row.__create ?? row); close() }
  const clear    = (e) => { e.stopPropagation(); onChange?.('') }

  const onKey = (e) => {
    if (!open && (e.key === 'ArrowDown' || e.key === 'Enter')) { e.preventDefault(); openMenu(); return }
    if (!open) return
    if (e.key === 'ArrowDown') { e.preventDefault(); setHi(h => Math.min(h + 1, rows.length - 1)) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setHi(h => Math.max(h - 1, 0)) }
    else if (e.key === 'Enter') { e.preventDefault(); if (rows[hi]) pick(rows[hi]) }
    else if (e.key === 'Escape') { e.preventDefault(); close() }
  }

  useEffect(() => { setHi(0) }, [query])
  useEffect(() => {
    if (open && listRef.current) {
      const el = listRef.current.children[hi]
      el?.scrollIntoView({ block: 'nearest' })
    }
  }, [hi, open])

  return (
    <div ref={boxRef} style={{ position: 'relative' }}>
      {/* Trigger — looks exactly like a native select field */}
      <div className="input-3d text-sm" onClick={openMenu} onKeyDown={onKey} tabIndex={disabled ? -1 : 0} role="combobox"
        aria-expanded={open} aria-haspopup="listbox"
        style={{ display: 'flex', alignItems: 'center', gap: 6, cursor: disabled ? 'not-allowed' : 'pointer', paddingRight: 8, opacity: disabled ? 0.6 : 1 }}>
        <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: value ? 'var(--text-h)' : 'var(--text-muted)' }}>
          {value || placeholder}
        </span>
        {value && !disabled && (
          <button type="button" onClick={clear} title="Clear" tabIndex={-1}
            style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', padding: 0, display: 'flex' }}>
            <X size={14} />
          </button>
        )}
        <ChevronDown size={16} style={{ color: '#9ca3af', flexShrink: 0, transform: open ? 'rotate(180deg)' : 'none', transition: 'transform .15s' }} />
      </div>

      {/* Dropdown */}
      {open && (
        <div style={{ position: 'absolute', zIndex: 50, top: 'calc(100% + 4px)', left: 0, right: 0,
          background: 'var(--bg-card, var(--bg-input))', border: '1px solid var(--border)', borderRadius: 12,
          boxShadow: '0 12px 32px rgba(0,0,0,0.25)', overflow: 'hidden' }}>
          {/* Search box */}
          <div style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 10px', borderBottom: '1px solid var(--border)' }}>
            <Search size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
            <input autoFocus value={query} onChange={e => setQuery(e.target.value)} onKeyDown={onKey}
              placeholder="Search…"
              style={{ flex: 1, background: 'none', border: 'none', outline: 'none', color: 'var(--text-h)', fontSize: 13 }} />
          </div>
          {/* Options */}
          <div ref={listRef} role="listbox" style={{ maxHeight: 220, overflowY: 'auto', padding: 4 }}>
            {loading ? (
              <p style={{ padding: '10px 12px', fontSize: 12, color: 'var(--text-muted)', margin: 0 }}>Loading…</p>
            ) : rows.length === 0 ? (
              <p style={{ padding: '10px 12px', fontSize: 12, color: 'var(--text-muted)', margin: 0 }}>{emptyText}</p>
            ) : rows.map((row, i) => {
              const isCreate = !!row.__create
              const label = isCreate ? `Add “${row.__create}”` : row
              const selected = !isCreate && row === value
              return (
                <div key={isCreate ? '__create' : row} role="option" aria-selected={selected}
                  onMouseEnter={() => setHi(i)} onClick={() => pick(row)}
                  style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 10px', borderRadius: 8, cursor: 'pointer',
                    background: i === hi ? 'rgba(124,58,237,0.12)' : 'transparent',
                    color: isCreate ? '#a78bfa' : 'var(--text-h)', fontSize: 13, fontWeight: selected ? 700 : 500 }}>
                  <span style={{ flex: 1, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{label}</span>
                  {selected && <Check size={14} style={{ color: '#a78bfa' }} />}
                </div>
              )
            })}
          </div>
        </div>
      )}
    </div>
  )
}
