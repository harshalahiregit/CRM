import { createPortal } from 'react-dom'

/**
 * Review comment #21 — "Edit employee section — fix positioning after pop up".
 *
 * Same fix, and the same reason, as Drawer: rendered through a portal to <body>
 * so the fixed-position backdrop resolves against the viewport.
 *
 * The bug this exists to prevent: HR pages are wrapped in
 * `animate-[tiltIn_0.35s_ease_forwards]`, and `forwards` means the element KEEPS
 * the animation's end transform (`perspective(600px) rotateX(0)`) permanently.
 * Any ancestor with a transform becomes the containing block for
 * `position: fixed` descendants — so `.modal-backdrop`'s `fixed inset-0` covered
 * the page's content box rather than the screen. On a long employee list that box
 * is thousands of pixels tall, so the "centered" modal landed far below the fold
 * and the backdrop stopped short of the header and sidebar.
 *
 * Markup matches the inline version it replaces (`.modal-backdrop` >
 * `.modal-box`), so styling, animation and click-outside-to-close are unchanged
 * — only the DOM parent moves.
 */
// closeOnBackdrop defaults to FALSE: a stray click on the dim area used to unmount
// the modal and silently discard whatever was typed. Opt in for read-only content.
export default function Modal({ open, onClose, children, className = '', style, closeOnBackdrop = false }) {
  if (!open) return null

  return createPortal(
    <div className="modal-backdrop" onClick={closeOnBackdrop ? onClose : undefined}>
      <div className={`modal-box ${className}`.trim()} onClick={e => e.stopPropagation()} style={style}>
        {children}
      </div>
    </div>,
    document.body,
  )
}
