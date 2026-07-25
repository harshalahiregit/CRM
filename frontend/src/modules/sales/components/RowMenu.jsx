import { useState } from 'react'
import { createPortal } from 'react-dom'
import { MoreVertical } from 'lucide-react'

/**
 * A row "⋯" actions dropdown that renders via a portal to <body>, so it can
 * never be clipped by the table's overflow or trapped by a transformed
 * ancestor (.card-3d uses will-change:transform, which would otherwise capture
 * position:fixed). Positioned from the trigger button's viewport rect.
 *
 *   <RowMenu>
 *     <button className="row-menu-item" onClick={...}>View</button>
 *     ...
 *   </RowMenu>
 *
 * Any click inside the menu closes it (after the item's own handler runs), and
 * a click anywhere outside also closes it.
 */
export default function RowMenu({ children, width = 176 }) {
  const [open, setOpen] = useState(false)
  const [pos, setPos] = useState(null)

  const toggle = (e) => {
    e.stopPropagation()
    const r = e.currentTarget.getBoundingClientRect()
    setPos({ top: r.bottom + 6, right: window.innerWidth - r.right })
    setOpen(o => !o)
  }

  return (
    <>
      <button
        onClick={toggle}
        className="w-8 h-8 rounded-xl flex items-center justify-center hover:bg-[rgba(124,58,237,0.08)] transition-colors"
        aria-label="Row actions"
      >
        <MoreVertical size={16} style={{ color: 'var(--text-muted)' }} />
      </button>
      {open && createPortal(
        <>
          <div style={{ position: 'fixed', inset: 0, zIndex: 9997 }} onClick={() => setOpen(false)} />
          <div
            className="rounded-2xl p-1.5 shadow-2xl flex flex-col"
            style={{ position: 'fixed', top: pos?.top, right: pos?.right, width, zIndex: 9998, background: 'var(--bg-card)', border: '1px solid var(--border)' }}
            onClick={() => setOpen(false)}
          >
            {children}
          </div>
        </>,
        document.body,
      )}
    </>
  )
}
