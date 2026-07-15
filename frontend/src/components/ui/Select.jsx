import { useState, useRef, useEffect, useCallback } from 'react'
import { createPortal } from 'react-dom'
import { ChevronDown, Check } from 'lucide-react'

/**
 * Themeable dropdown. A native <select> renders its option list with the OS
 * chrome (white background, black text) which looks broken on the dark theme and
 * can't be styled — this replaces it with a token-driven popover so the list
 * matches the app in both light and dark.
 *
 * options: [{ value, label, dot? }]  — `dot` paints a small colour chip (status/priority).
 */
export default function Select({
  value,
  onChange,
  options = [],
  placeholder = 'Select…',
  disabled = false,
  size = 'md',           // 'sm' | 'md'
  className = '',
  style = {},
  buttonStyle = {},      // escape hatch: e.g. the grid's coloured status pill
  hideChevron = false,
  align = 'left',        // 'left' | 'right' — which edge the popover aligns to
  ariaLabel,
}) {
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(-1)
  const [pos, setPos] = useState(null)
  const rootRef = useRef(null)
  const listRef = useRef(null)

  const selected = options.find(o => String(o.value) === String(value))
  const pad = size === 'sm' ? '6px 10px' : '9px 12px'
  const fs = size === 'sm' ? 12 : 13.5

  /**
   * The popover is portalled to <body> with fixed positioning: these selects sit
   * inside cards that use `overflow:hidden` for their rounded corners, which
   * would otherwise clip the list (the last options got cut off). Flips above the
   * control when there isn't room below.
   */
  const place = useCallback(() => {
    const el = rootRef.current
    if (!el) return
    const r = el.getBoundingClientRect()
    const below = window.innerHeight - r.bottom
    const flip = below < 240 && r.top > below
    setPos({
      left: align === 'right' ? undefined : r.left,
      right: align === 'right' ? window.innerWidth - r.right : undefined,
      top: flip ? undefined : r.bottom + 4,
      bottom: flip ? window.innerHeight - r.top + 4 : undefined,
      minWidth: r.width,
      maxHeight: Math.max(160, (flip ? r.top : below) - 12),
    })
  }, [align])

  useEffect(() => {
    if (!open) return
    place()
    const onDown = (e) => {
      if (rootRef.current?.contains(e.target) || listRef.current?.contains(e.target)) return
      setOpen(false)
    }
    const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); setOpen(false) } }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    window.addEventListener('resize', place)
    // capture-phase so we reposition even when an ancestor (card/table) scrolls
    window.addEventListener('scroll', place, true)
    return () => {
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
      window.removeEventListener('resize', place)
      window.removeEventListener('scroll', place, true)
    }
  }, [open, place])

  // Keep the highlighted row in view while arrowing.
  useEffect(() => {
    if (!open || active < 0) return
    listRef.current?.children[active]?.scrollIntoView({ block: 'nearest' })
  }, [active, open])

  const pick = (opt) => { onChange?.(opt.value); setOpen(false) }

  const onKeyDown = (e) => {
    if (disabled) return
    if (!open && (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown')) {
      e.preventDefault(); setOpen(true)
      setActive(Math.max(0, options.findIndex(o => String(o.value) === String(value))))
      return
    }
    if (!open) return
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive(i => Math.min(options.length - 1, i + 1)) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(i => Math.max(0, i - 1)) }
    else if (e.key === 'Enter') { e.preventDefault(); if (options[active]) pick(options[active]) }
  }

  return (
    <div ref={rootRef} className={`relative ${className}`} style={style}>
      <button
        type="button"
        disabled={disabled}
        aria-label={ariaLabel}
        aria-haspopup="listbox"
        aria-expanded={open}
        onClick={() => !disabled && setOpen(o => !o)}
        onKeyDown={onKeyDown}
        className="w-full flex items-center gap-2 rounded-xl outline-none transition-colors"
        style={{
          padding: pad,
          fontSize: fs,
          fontWeight: 600,
          background: 'var(--bg-input)',
          border: `1px solid ${open ? 'var(--color-support-500)' : 'var(--border)'}`,
          color: selected ? 'var(--text-h)' : 'var(--text-muted)',
          cursor: disabled ? 'not-allowed' : 'pointer',
          opacity: disabled ? 0.5 : 1,
          textAlign: 'left',
          ...buttonStyle,
        }}
      >
        {selected?.dot && (
          <span style={{ width: 8, height: 8, borderRadius: '50%', background: selected.dot, flexShrink: 0 }} />
        )}
        <span className="flex-1 truncate">{selected ? selected.label : placeholder}</span>
        {!hideChevron && (
          <ChevronDown size={14} style={{ color: 'currentColor', opacity: 0.6, flexShrink: 0, transform: open ? 'rotate(180deg)' : 'none', transition: 'transform .15s' }} />
        )}
      </button>

      {open && pos && createPortal(
        <ul
          ref={listRef}
          role="listbox"
          className="fixed z-[80] py-1 rounded-xl overflow-auto"
          style={{
            left: pos.left,
            right: pos.right,
            top: pos.top,
            bottom: pos.bottom,
            minWidth: pos.minWidth,
            width: 'max-content',
            maxWidth: 300,
            maxHeight: pos.maxHeight,
            background: 'var(--bg-card)',
            border: '1px solid var(--border)',
            boxShadow: 'var(--shadow-card-3d)',
          }}
        >
          {options.length === 0 && (
            <li style={{ padding: '10px 12px', fontSize: 12, color: 'var(--text-muted)' }}>No options</li>
          )}
          {options.map((o, i) => {
            const isSel = String(o.value) === String(value)
            return (
              <li key={String(o.value)} role="option" aria-selected={isSel}>
                <button
                  type="button"
                  onMouseEnter={() => setActive(i)}
                  onClick={() => pick(o)}
                  className="w-full flex items-center gap-2 text-left transition-colors"
                  style={{
                    padding: '8px 12px',
                    fontSize: fs,
                    fontWeight: isSel ? 700 : 500,
                    color: isSel ? 'var(--color-support-500)' : 'var(--text-body)',
                    background: active === i ? 'color-mix(in srgb, var(--color-support-500) 10%, transparent)' : 'transparent',
                  }}
                >
                  {o.dot && <span style={{ width: 8, height: 8, borderRadius: '50%', background: o.dot, flexShrink: 0 }} />}
                  <span className="flex-1 truncate">{o.label}</span>
                  {isSel && <Check size={13} style={{ flexShrink: 0 }} />}
                </button>
              </li>
            )
          })}
        </ul>,
        document.body
      )}
    </div>
  )
}
