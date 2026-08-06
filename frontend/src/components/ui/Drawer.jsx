import { createPortal } from 'react-dom'
import { X } from 'lucide-react'

/**
 * Centered modal drawer. Rendered through a portal to <body> so its
 * fixed-position backdrop always covers the full viewport (including the top
 * bar) — otherwise a transformed/`will-change` ancestor (e.g. a `.card-3d` or
 * an `animate-fade-in` page wrapper) traps `position:fixed` to that ancestor's
 * box and the header is left un-blurred.
 *
 * The backdrop does NOT dismiss the drawer. These drawers are almost always
 * data-entry forms, and a stray click on the dim area unmounted the whole thing,
 * silently discarding everything typed with no warning and no way back. Closing
 * is deliberate only: the X, or a Cancel button in the footer. Pass
 * closeOnBackdrop for the rare read-only viewer where click-away is welcome.
 */
export default function Drawer({ open, onClose, title, width = 'min(580px, 95vw)', children, footer, closeOnBackdrop = false }) {
  if (!open) return null

  return createPortal(
    <>
      <div className="drawer-backdrop" onClick={closeOnBackdrop ? onClose : undefined} />
      <div className="drawer-panel" style={{ width }}>
        <div className="drawer-header">
          <h2 className="text-lg font-black" style={{ color: 'var(--text-h)' }}>{title}</h2>
          <button onClick={onClose} className="btn-icon">
            <X size={18} />
          </button>
        </div>

        <div className="drawer-body">{children}</div>

        {footer && <div className="drawer-footer">{footer}</div>}
      </div>
    </>,
    document.body,
  )
}
