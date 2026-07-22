import { useState, useRef, useEffect } from 'react'
import { createPortal } from 'react-dom'
import { ChevronDown, Check } from 'lucide-react'

/**
 * Multi-select tax picker for a document line (old-CRM `tblitem_tax` model):
 * several named taxes can apply to one line — CGST 9% + SGST 9% for
 * intra-state, or a single IGST 18% for inter-state.
 *
 * value: [{ name, rate }]
 *
 * The menu is portalled to <body> because the line-items table lives inside a
 * `.card-3d`, whose `will-change: transform` would otherwise trap a
 * position:fixed dropdown (same reason RowMenu portals).
 */
const pct = (n) => `${Number(n).toFixed(2)}%`

const MAX_MENU_H = 260

export default function TaxSelect({ value = [], options = [], onChange }) {
  const [open, setOpen] = useState(false)
  const [pos, setPos] = useState(null)
  const btnRef = useRef(null)
  const menuRef = useRef(null)

  const selected = Array.isArray(value) ? value : []
  const isOn = (o) => selected.some(t => t.name === o.name && Number(t.rate) === Number(o.rate))

  const toggle = (o) => {
    const next = isOn(o)
      ? selected.filter(t => !(t.name === o.name && Number(t.rate) === Number(o.rate)))
      : [...selected, { name: o.name, rate: Number(o.rate) }]
    onChange(next)
  }

  /** Place the menu below the field, flipping above when space is tight. */
  const placeMenu = () => {
    const r = btnRef.current?.getBoundingClientRect()
    if (!r) return null
    const below = window.innerHeight - r.bottom
    const flip = below < MAX_MENU_H + 12 && r.top > below
    return {
      top: flip ? Math.max(8, r.top - 4 - MAX_MENU_H) : r.bottom + 4,
      left: r.left,
      width: Math.max(r.width, 210),
    }
  }

  const openMenu = () => { setPos(placeMenu()); setOpen(true) }

  useEffect(() => {
    if (!open) return

    // Scrolling INSIDE the list must not close it — only outer scrolling
    // moves the menu, and it just follows the field rather than closing.
    const onScroll = (e) => {
      if (menuRef.current && e.target instanceof Node && menuRef.current.contains(e.target)) return
      const r = btnRef.current?.getBoundingClientRect()
      if (!r || r.bottom < 0 || r.top > window.innerHeight) return setOpen(false)
      setPos(placeMenu())
    }
    const onDown = (e) => { if (!menuRef.current?.contains(e.target)) setOpen(false) }
    const onKey = (e) => { if (e.key === 'Escape') setOpen(false) }
    const onResize = () => setOpen(false)

    window.addEventListener('scroll', onScroll, true)
    window.addEventListener('resize', onResize)
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      window.removeEventListener('scroll', onScroll, true)
      window.removeEventListener('resize', onResize)
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  const label = selected.length
    ? selected.map(t => pct(t.rate)).join('  ')
    : 'No tax'

  return (
    <>
      <button
        ref={btnRef}
        type="button"
        onClick={(e) => { e.stopPropagation(); open ? setOpen(false) : openMenu() }}
        className="input-3d text-xs flex items-center justify-between gap-1 w-full"
        style={{ padding: '5px 8px', minWidth: 104 }}
        title={selected.length ? selected.map(t => `${t.name} ${pct(t.rate)}`).join(', ') : 'No tax'}
      >
        <span className="truncate" style={{ color: selected.length ? 'var(--text-h)' : 'var(--text-faint)' }}>{label}</span>
        <ChevronDown size={12} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
      </button>

      {open && pos && createPortal(
        <div
          ref={menuRef}
          className="rounded-xl overflow-hidden shadow-2xl"
          style={{
            position: 'fixed', top: pos.top, left: pos.left, minWidth: pos.width, zIndex: 9999,
            background: 'var(--bg-card)', border: '1px solid var(--border)',
            maxHeight: MAX_MENU_H, overflowY: 'auto', overscrollBehavior: 'contain',
          }}
        >
          {!options.length && (
            <p className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-muted)' }}>No tax rates configured.</p>
          )}
          {options.map(o => {
            const on = isOn(o)
            return (
              <button
                key={o.id ?? `${o.name}-${o.rate}`}
                type="button"
                onClick={() => toggle(o)}
                className="w-full flex items-center justify-between gap-3 px-3 py-2 text-xs transition-colors hover:bg-[rgba(124,58,237,0.08)]"
                style={{ background: on ? 'rgba(124,58,237,0.06)' : 'transparent' }}
              >
                <span className="flex items-baseline gap-2 min-w-0">
                  <span className="font-bold" style={{ color: 'var(--text-h)' }}>{pct(o.rate)}</span>
                  <span className="truncate" style={{ color: 'var(--text-muted)' }}>{o.name}</span>
                </span>
                {on && <Check size={13} style={{ color: 'var(--accent)', flexShrink: 0 }} />}
              </button>
            )
          })}
        </div>,
        document.body,
      )}
    </>
  )
}
