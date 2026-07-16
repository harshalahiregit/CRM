import { useState, useRef, useMemo } from 'react'
import { X, Tag as TagIcon } from 'lucide-react'

/**
 * Tag chips + type-to-add input, with suggestions from the workspace's existing
 * tags. Shared rather than per-module: this codebase already grew three separate
 * tag implementations (ticket pivot, KB JSON, lead CSV) and didn't need a fourth.
 *
 * Tags are plain strings here — the server resolves them to rows and invents a
 * colour, so you never have to create a tag before using it.
 *
 * value: string[]   suggestions: [{ id, name, color }]
 */
export default function TagInput({
  value = [],
  onChange,
  suggestions = [],
  max = 15,
  placeholder = 'Add tag…',
  accent = 'var(--color-primary-500)',
}) {
  const [draft, setDraft] = useState('')
  const [focused, setFocused] = useState(false)
  const ref = useRef(null)

  const colorFor = useMemo(() => {
    const map = Object.fromEntries(suggestions.map(s => [s.name.toLowerCase(), s.color]))
    return (name) => map[name.toLowerCase()] || accent
  }, [suggestions, accent])

  const has = (name) => value.some(v => v.toLowerCase() === name.toLowerCase())

  const add = (raw) => {
    const name = raw.trim()
    // Case-insensitive: the server treats "Urgent" and "urgent" as one tag, so
    // the input must not let you stage both.
    if (!name || has(name) || value.length >= max) return
    onChange([...value, name])
    setDraft('')
  }

  const remove = (name) => onChange(value.filter(v => v !== name))

  const open = focused && draft.trim().length > 0
  const matches = open
    ? suggestions.filter(s => s.name.toLowerCase().includes(draft.trim().toLowerCase()) && !has(s.name)).slice(0, 6)
    : []
  const isNew = open && !suggestions.some(s => s.name.toLowerCase() === draft.trim().toLowerCase())

  const onKeyDown = (e) => {
    if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add(draft) }
    // Backspace on an empty input removes the last chip — standard chip-input feel.
    else if (e.key === 'Backspace' && !draft && value.length) remove(value[value.length - 1])
    else if (e.key === 'Escape') { setDraft(''); ref.current?.blur() }
  }

  return (
    <div className="relative">
      <div
        onClick={() => ref.current?.focus()}
        className="flex flex-wrap items-center gap-1.5 rounded-xl cursor-text"
        style={{ padding: '7px 9px', minHeight: 38, background: 'var(--bg-input)', border: '1px solid var(--border)' }}
      >
        {value.map(name => (
          <span key={name} className="flex items-center gap-1 text-[11px] font-bold px-2 py-1 rounded-lg"
            style={{ background: `color-mix(in srgb, ${colorFor(name)} 16%, transparent)`, color: colorFor(name) }}>
            {name}
            <button type="button" onClick={e => { e.stopPropagation(); remove(name) }} className="hover:opacity-60" aria-label={`Remove ${name}`}>
              <X size={10} />
            </button>
          </span>
        ))}

        {value.length < max && (
          <input
            ref={ref}
            value={draft}
            onChange={e => setDraft(e.target.value)}
            onKeyDown={onKeyDown}
            onFocus={() => setFocused(true)}
            // Delay so a click on a suggestion lands before the list unmounts.
            onBlur={() => setTimeout(() => setFocused(false), 120)}
            placeholder={value.length ? '' : placeholder}
            className="flex-1 bg-transparent outline-none"
            style={{ fontSize: 12.5, minWidth: 80, color: 'var(--text-h)' }}
          />
        )}
      </div>

      {value.length >= max && (
        <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>Maximum {max} tags.</p>
      )}

      {open && (matches.length > 0 || isNew) && (
        <ul className="absolute left-0 right-0 mt-1 rounded-xl overflow-hidden z-30"
          style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}>
          {matches.map(s => (
            <li key={s.id}>
              <button type="button" onMouseDown={e => e.preventDefault()} onClick={() => add(s.name)}
                className="w-full flex items-center gap-2 text-left px-3 py-2"
                style={{ fontSize: 12.5, color: 'var(--text-h)' }}
                onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
                onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                <span className="w-2 h-2 rounded-full" style={{ background: s.color }} />
                {s.name}
              </button>
            </li>
          ))}
          {isNew && (
            <li>
              <button type="button" onMouseDown={e => e.preventDefault()} onClick={() => add(draft)}
                className="w-full flex items-center gap-2 text-left px-3 py-2"
                style={{ fontSize: 12.5, color: 'var(--text-muted)', borderTop: matches.length ? '1px solid var(--border)' : 'none' }}
                onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
                onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                <TagIcon size={11} />
                Create “<span style={{ color: 'var(--text-h)', fontWeight: 700 }}>{draft.trim()}</span>”
              </button>
            </li>
          )}
        </ul>
      )}
    </div>
  )
}

/** Read-only chip row — for list/table cells where tags aren't editable. */
export function TagChips({ tags = [], max = 3, size = 'sm' }) {
  if (!tags.length) return null
  const shown = tags.slice(0, max)
  const rest = tags.length - shown.length
  const fs = size === 'sm' ? 9 : 10.5

  return (
    <span className="inline-flex items-center gap-1 flex-wrap">
      {shown.map(t => (
        <span key={t.id ?? t.name} className="px-1.5 py-0.5 rounded-md font-bold"
          style={{ fontSize: fs, background: `color-mix(in srgb, ${t.color || 'var(--text-muted)'} 15%, transparent)`, color: t.color || 'var(--text-muted)' }}>
          {t.name}
        </span>
      ))}
      {rest > 0 && (
        <span className="px-1 rounded-md" style={{ fontSize: fs, color: 'var(--text-muted)' }}>+{rest}</span>
      )}
    </span>
  )
}
