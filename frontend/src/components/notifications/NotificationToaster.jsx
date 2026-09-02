import { useEffect, useRef, useState } from 'react'
import { Bell, X } from 'lucide-react'

/**
 * Persistent on-screen pop-ups for in-app notifications. Presentational + a
 * little local bookkeeping — the data (polled list) and the actions are injected
 * so the SAME component serves the main app and both vendor portals.
 *
 * Behaviour (by design):
 *  - Only notifications that arrive AFTER the page has loaded pop up; the
 *    existing backlog stays in the bell (no flood on login). The baseline is the
 *    highest id present on the first loaded fetch.
 *  - A toast does NOT auto-dismiss. It stays until the user reacts — View (open
 *    + mark read) or Dismiss (mark read). Then it disappears.
 *  - The visible stack is capped so a burst never covers the screen; the rest
 *    remain unread in the bell and surface as earlier ones are cleared.
 */
const CAP = 3

export default function NotificationToaster({ items = [], loaded = false, scope = 'app', onView, onDismiss }) {
  const baselineRef = useRef(null)
  const [handled, setHandled] = useState(() => new Set())

  // Establish the baseline once, from the first LOADED fetch, so the backlog
  // that already existed when the page opened never pops.
  useEffect(() => {
    if (baselineRef.current !== null || !loaded) return
    baselineRef.current = items.reduce((max, n) => Math.max(max, n?.id || 0), 0)
  }, [loaded, items])

  if (baselineRef.current === null) return null

  const baseline = baselineRef.current
  const visible = items
    .filter(n => n && !n.is_read && n.id > baseline && !handled.has(n.id))
    .slice(0, CAP)

  const markHandled = (id) => setHandled(prev => new Set(prev).add(id))
  const view = (n) => { markHandled(n.id); onView?.(n) }
  const dismiss = (n) => { markHandled(n.id); onDismiss?.(n) }

  if (visible.length === 0) return null

  return (
    <div
      aria-live="polite"
      style={{
        position: 'fixed', right: 14, bottom: 14, zIndex: 9999,
        display: 'flex', flexDirection: 'column', gap: 8,
        width: 'min(300px, calc(100vw - 28px))', pointerEvents: 'none',
      }}
    >
      {visible.map(n => (
        <div
          key={n.id}
          role="alert"
          onClick={() => view(n)}
          title="Open"
          style={{
            pointerEvents: 'auto', cursor: 'pointer',
            background: 'var(--bg-card, #ffffff)',
            border: '1px solid var(--border, #e5e7eb)',
            borderLeft: '3px solid #7C3AED',
            borderRadius: 10,
            boxShadow: '0 6px 20px rgba(0,0,0,0.16)',
            padding: '8px 9px 8px 11px',
            animation: 'notifToastIn .2s ease-out',
          }}
        >
          <div style={{ display: 'flex', gap: 8, alignItems: 'flex-start' }}>
            <span style={{
              width: 20, height: 20, borderRadius: 6, flexShrink: 0, marginTop: 1,
              display: 'flex', alignItems: 'center', justifyContent: 'center',
              background: 'rgba(124,58,237,0.12)',
            }}>
              <Bell size={11} style={{ color: '#7C3AED' }} />
            </span>

            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-h, #111827)', lineHeight: 1.3 }}>
                {n.title}
              </div>
              {n.message && (
                <div style={{
                  fontSize: 11, color: 'var(--text-muted, #6b7280)', marginTop: 1, lineHeight: 1.35,
                  overflow: 'hidden', textOverflow: 'ellipsis',
                  display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical',
                }}>
                  {n.message}
                </div>
              )}
            </div>

            <button
              onClick={(e) => { e.stopPropagation(); dismiss(n) }}
              aria-label="Dismiss notification"
              style={{
                background: 'transparent', border: 'none', cursor: 'pointer',
                color: 'var(--text-muted, #9ca3af)', padding: 1, lineHeight: 0, flexShrink: 0,
              }}
            >
              <X size={13} />
            </button>
          </div>
        </div>
      ))}
      <style>{`@keyframes notifToastIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}`}</style>
    </div>
  )
}
