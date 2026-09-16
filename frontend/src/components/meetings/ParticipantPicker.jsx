import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { Plus, Search, X, Check } from 'lucide-react'

/**
 * Pick meeting participants from the category directory.
 *
 * This replaces a native <select> with <optgroup>s. That select was the whole
 * directory — admin, staff, manager, HR, doctor, customer, vendor — inside an OS
 * dropdown of fixed height: you could not search it, you could not see the
 * categories while scrolling, and adding four people meant opening it four
 * times. On a long tenant list it was unusable, which is what was reported.
 *
 * What this does instead:
 *  - one scrolling panel with its own max-height, so the list scrolls rather
 *    than the modal behind it,
 *  - a search box that filters across every category at once (name, e-mail and
 *    designation), because people look for "Anjali", not for "Finance",
 *  - sticky category headings, so you always know whose section you are in,
 *  - stays open after a pick, since meetings have several participants, and
 *    shows a tick against the ones already on the list rather than letting you
 *    add them twice.
 *
 * Closing follows the house rule: the ✕ or Escape, never a backdrop click, so a
 * mis-click while scrolling a long list cannot discard the work.
 *
 * ── Why the panel is portalled to <body> ────────────────────────────────
 * The meeting form is laid out in `.card-3d`, which sets `will-change:
 * transform` and `transform-style: preserve-3d`. Both create a containing block
 * AND a stacking context, so an absolutely-positioned child is trapped inside
 * the card: it cannot escape the card's bounds and no z-index will lift it above
 * a neighbouring card. The panel rendered clipped and half-behind the card next
 * to it.
 *
 * A portal to <body> with FIXED coordinates measured from the button escapes
 * both. The position is recomputed on scroll and resize, because fixed
 * coordinates do not follow an element that moves.
 */
export default function ParticipantPicker({ categories = [], chosen = [], onPick, inputStyle }) {
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const [box, setBox] = useState(null)   // where to draw the panel, in viewport coords
  const btnRef = useRef(null)
  const searchRef = useRef(null)

  const PANEL_W = 330
  const PANEL_H = 380

  /** Measure the button and decide whether the panel hangs below or above it. */
  const place = () => {
    const el = btnRef.current
    if (!el) return
    const r = el.getBoundingClientRect()
    const below = window.innerHeight - r.bottom
    const flip = below < PANEL_H && r.top > below   // not enough room under it
    setBox({
      // Right-aligned to the button, then clamped so it can never sit off-screen
      // on a narrow window.
      left: Math.max(8, Math.min(r.right - PANEL_W, window.innerWidth - PANEL_W - 8)),
      top: flip ? undefined : r.bottom + 6,
      bottom: flip ? window.innerHeight - r.top + 6 : undefined,
      maxH: Math.max(200, (flip ? r.top : below) - 16),
    })
  }

  useLayoutEffect(() => {
    if (!open) return
    place()
    // Fixed coordinates do not follow an element that moves, so re-measure while
    // the panel is up. Capture phase catches scrolling inside the form, not just
    // the window.
    const on = () => place()
    window.addEventListener('scroll', on, true)
    window.addEventListener('resize', on)
    return () => {
      window.removeEventListener('scroll', on, true)
      window.removeEventListener('resize', on)
    }
  }, [open])

  // Escape closes. Bound only while open, so it cannot swallow the key from the
  // meeting form behind it.
  useEffect(() => {
    if (!open) return
    const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); setOpen(false) } }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open])

  useEffect(() => { if (open) searchRef.current?.focus() }, [open])

  /** Is this person already a participant? By identity, else by address. */
  const isChosen = (p) => chosen.some(c =>
    (p.user_id && String(c.user_id) === String(p.user_id)) ||
    (p.email && c.email && c.email.toLowerCase() === p.email.toLowerCase()))

  const groups = useMemo(() => {
    const term = q.trim().toLowerCase()
    return categories
      .map(c => ({
        ...c,
        people: (c.people || []).filter(p => !term || [p.name, p.email, p.designation]
          .some(f => (f || '').toLowerCase().includes(term))),
      }))
      // Empty categories are dropped rather than shown as bare headings — a
      // tenant with no customers yet should not see a "Customer" heading.
      .filter(c => c.people.length > 0)
  }, [categories, q])

  const total = groups.reduce((n, c) => n + c.people.length, 0)

  if (!categories.some(c => (c.people || []).length > 0)) return null

  return (
    <>
      <button
        ref={btnRef}
        type="button"
        onClick={() => setOpen(o => !o)}
        style={{ ...inputStyle, width: 'auto', fontSize: 12, padding: '5px 10px', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 6 }}>
        <Plus size={13} /> Add participant
      </button>

      {open && box && createPortal(
        <div
          role="dialog"
          aria-label="Add participant"
          style={{
            position: 'fixed',
            left: box.left, top: box.top, bottom: box.bottom,
            // Above the app shell and any card, but below a real modal.
            zIndex: 3000,
            width: PANEL_W, maxWidth: 'calc(100vw - 16px)',
            background: 'var(--bg-card)', border: '1px solid var(--border)',
            borderRadius: 12, boxShadow: '0 12px 32px rgba(0,0,0,.28)',
            display: 'flex', flexDirection: 'column', overflow: 'hidden',
          }}>

          <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 10px', borderBottom: '1px solid var(--border)' }}>
            <Search size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
            <input
              ref={searchRef}
              value={q}
              onChange={e => setQ(e.target.value)}
              placeholder="Search people…"
              style={{ flex: 1, minWidth: 0, background: 'transparent', border: 'none', outline: 'none', color: 'var(--text-h)', fontSize: 13 }} />
            <button
              type="button"
              onClick={() => setOpen(false)}
              aria-label="Close"
              style={{ background: 'transparent', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', display: 'flex', padding: 2 }}>
              <X size={14} />
            </button>
          </div>

          {/* The scrolling region. Its own max-height, measured against the
              space actually available, so the list scrolls and the form behind
              it stays where it was. */}
          <div style={{ maxHeight: Math.min(290, box.maxH), overflowY: 'auto' }}>
            {total === 0 ? (
              <p style={{ padding: '18px 12px', margin: 0, textAlign: 'center', fontSize: 12.5, color: 'var(--text-muted)' }}>
                Nobody matches “{q}”.
              </p>
            ) : groups.map(c => (
              <div key={c.key}>
                <div style={{
                  position: 'sticky', top: 0, zIndex: 1,
                  padding: '6px 12px', background: 'var(--bg-input)',
                  borderBottom: '1px solid var(--border)',
                  fontSize: 10.5, fontWeight: 800, letterSpacing: '.06em',
                  textTransform: 'uppercase', color: 'var(--text-muted)',
                }}>
                  {c.label} <span style={{ opacity: .7 }}>({c.people.length})</span>
                </div>

                {c.people.map(p => {
                  const already = isChosen(p)
                  return (
                    <button
                      key={p.id}
                      type="button"
                      disabled={already}
                      onClick={() => onPick(p)}
                      title={already ? 'Already a participant' : undefined}
                      style={{
                        width: '100%', textAlign: 'left', display: 'flex', alignItems: 'center', gap: 8,
                        padding: '8px 12px', background: 'transparent', border: 'none',
                        borderBottom: '1px solid var(--border)',
                        cursor: already ? 'default' : 'pointer', opacity: already ? .5 : 1,
                      }}
                      onMouseEnter={e => { if (!already) e.currentTarget.style.background = 'var(--bg-input)' }}
                      onMouseLeave={e => { e.currentTarget.style.background = 'transparent' }}>
                      <span style={{ flex: 1, minWidth: 0 }}>
                        <span style={{ display: 'block', fontSize: 12.5, fontWeight: 600, color: 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                          {p.name || '—'}
                        </span>
                        {(p.designation || p.email) && (
                          <span style={{ display: 'block', fontSize: 11, color: 'var(--text-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                            {p.designation || p.email}
                          </span>
                        )}
                      </span>
                      {already && <Check size={13} style={{ color: '#10b981', flexShrink: 0 }} />}
                    </button>
                  )
                })}
              </div>
            ))}
          </div>

          {/* Stays open on purpose — a meeting has several participants, and
              reopening the list for each one is what made the old picker slow. */}
          <div style={{ padding: '7px 12px', borderTop: '1px solid var(--border)', fontSize: 11, color: 'var(--text-muted)' }}>
            Pick as many as you need · Esc to close
          </div>
        </div>,
        document.body,
      )}
    </>
  )
}
