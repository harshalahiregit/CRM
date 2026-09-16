import { useEffect } from 'react'
import { createPortal } from 'react-dom'
import { Search, X } from 'lucide-react'

/**
 * The searchable picker behind the composer's Canned and KB-link buttons.
 *
 * ── Why this is shared ──────────────────────────────────────────────────
 * Both pickers had their own copy of this panel, character for character, so
 * both carried the same bugs and either would have been fixed alone. One
 * component, one behaviour, both callers.
 *
 * ── Centred, not anchored ───────────────────────────────────────────────
 * This used to hang off the button: `bottom-full left-0`, i.e. always upward
 * and always left-aligned. Both halves were wrong somewhere.
 *
 *   - Upward is right in the reply composer, where the toolbar sits at the
 *     bottom. In the New Ticket form the same buttons sit ABOVE the editor, so
 *     the list opened across the Status and tag fields.
 *   - Left-aligned pinned a 320px panel to a right-aligned button, so it ran
 *     off the right edge of the dialog.
 *
 * Anchoring it properly would have meant measuring the button and flipping and
 * clamping on every scroll and resize — which is precisely the machinery that
 * makes a panel twitch. It opens in the CENTRE of the screen instead. There is
 * nothing to measure, so there is nothing to get wrong, and it lands in the
 * same place every time from either composer.
 *
 * ── Stable on purpose ───────────────────────────────────────────────────
 * The geometry is fixed, not derived from content:
 *
 *   - Centring is CSS (flex on a full-screen layer), so a window resize is
 *     handled by the browser and needs no listener and no reflow.
 *   - The panel's HEIGHT is fixed. A panel sized to its results grows and
 *     shrinks on every keystroke, and the row under the cursor moves out from
 *     under it. Filtering from forty matches to one now changes nothing but
 *     the list.
 *   - Nothing repositions on scroll, because nothing is anchored to a thing
 *     that scrolls.
 *
 * ── On dismissal ────────────────────────────────────────────────────────
 * ✕ and Escape only. Clicking the backdrop does nothing, which is the rule the
 * rest of this app's popups follow.
 */
export default function PickerPopover({ open, onClose, placeholder, query, onQuery, children }) {
  useEffect(() => {
    if (!open) return
    const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); onClose() } }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open) return null

  return createPortal(
    // z-index clears the New Ticket dialog, which sits at z-70.
    <div
      className="fixed inset-0 flex items-start justify-center"
      style={{ zIndex: 1000, background: 'rgba(0,0,0,0.45)', paddingTop: '10vh', paddingLeft: 16, paddingRight: 16 }}
    >
      <div
        role="dialog"
        aria-modal="true"
        className="rounded-2xl flex flex-col overflow-hidden"
        style={{
          // Fixed box: the content never decides how big this is.
          width: 'min(560px, 100%)',
          height: 'min(520px, 70vh)',
          background: 'var(--bg-card)',
          border: '1px solid var(--border)',
          boxShadow: 'var(--shadow-card-3d)',
        }}
      >
        <div className="flex items-center gap-2 px-4 py-3 shrink-0" style={{ borderBottom: '1px solid var(--border)' }}>
          <Search size={15} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
          <input
            autoFocus
            value={query}
            onChange={e => onQuery(e.target.value)}
            placeholder={placeholder}
            className="flex-1 min-w-0 bg-transparent outline-none text-sm"
            style={{ color: 'var(--text-h)' }}
          />
          <button type="button" onClick={onClose} aria-label="Close" className="shrink-0 hover:opacity-70">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        {/* Only this scrolls. A filter box you have to scroll back up to reach
            is a filter box people stop using. */}
        <div className="flex-1 overflow-y-auto py-1 min-h-0">{children}</div>

        <div className="px-4 py-2 shrink-0 text-[10.5px] flex items-center justify-between"
          style={{ borderTop: '1px solid var(--border)', color: 'var(--text-muted)' }}>
          <span>Click an item to insert it</span>
          <span>Esc to close</span>
        </div>
      </div>
    </div>,
    document.body,
  )
}

/** The row style both pickers use, so their hover and padding cannot drift. */
export function PickerRow({ onClick, children }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="w-full text-left px-4 py-2.5 transition-colors"
      onMouseEnter={e => { e.currentTarget.style.background = 'var(--bg-input)' }}
      onMouseLeave={e => { e.currentTarget.style.background = 'transparent' }}
    >
      {children}
    </button>
  )
}

/** Shared empty state, so both pickers explain the gap the same way. */
export function PickerEmpty({ children }) {
  return (
    <p className="px-4 py-8 text-xs text-center leading-relaxed" style={{ color: 'var(--text-muted)' }}>
      {children}
    </p>
  )
}

/** The trigger button, identical in both pickers and in the reply toolbar. */
export function PickerTrigger({ onClick, icon: Icon, label, active }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-haspopup="dialog"
      aria-expanded={active}
      className="flex items-center gap-1.5 text-xs font-semibold rounded-lg px-1.5 py-1 -mx-1.5 transition-colors"
      style={{
        color: active ? 'var(--color-primary-500)' : 'var(--text-muted)',
        background: active ? 'color-mix(in srgb, var(--color-primary-500) 12%, transparent)' : 'transparent',
      }}
    >
      <Icon size={14} /> {label}
    </button>
  )
}
