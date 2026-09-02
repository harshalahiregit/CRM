import { useState, useEffect, useRef } from 'react'
import { useNavigate, useLocation } from 'react-router-dom'
import { Bell, CheckCheck } from 'lucide-react'
import { portalLink, portalBase } from './portalLink'

/**
 * In-app (bell) notification dropdown for BOTH vendor portals. Driven by the
 * shared `feed` (see useNotificationFeed) so the bell and the on-screen toaster
 * share ONE 30s poll. The red dot shows only when there is an unread item.
 */
export default function PortalNotificationBell({ feed }) {
  const navigate = useNavigate()
  const location = useLocation()
  const [open, setOpen] = useState(false)
  const wrapRef = useRef(null)

  const items = feed?.items ?? []
  const unread = feed?.unread_count ?? 0

  // Close on outside click.
  useEffect(() => {
    if (!open) return
    const onDown = (e) => { if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false) }
    document.addEventListener('mousedown', onDown)
    return () => document.removeEventListener('mousedown', onDown)
  }, [open])

  const toggle = () => { const next = !open; setOpen(next); if (next) feed?.refetch?.() }

  const openItem = (n) => {
    if (!n.is_read) feed?.markRead?.(n.id)
    setOpen(false)
    const to = portalLink(n.link, portalBase(location.pathname))
    if (to) navigate(to)
  }

  const markAll = () => feed?.markAllRead?.()

  return (
    <div ref={wrapRef} style={{ position: 'relative' }}>
      <button className="portal-icon-btn" title="Notifications" onClick={toggle} aria-label="Notifications">
        <Bell size={16} />
        {unread > 0 && <span className="notif-dot" />}
      </button>

      {open && (
        <div
          role="menu"
          style={{
            position: 'absolute', right: 0, top: 'calc(100% + 8px)', width: 340, maxWidth: '90vw',
            background: 'var(--bg-card, #fff)', border: '1px solid var(--border, #e5e7eb)',
            borderRadius: 12, boxShadow: 'var(--portal-shadow-md, 0 4px 24px rgba(0,0,0,0.14))',
            zIndex: 60, overflow: 'hidden',
          }}
        >
          <div style={{
            display: 'flex', alignItems: 'center', justifyContent: 'space-between',
            padding: '10px 14px', borderBottom: '1px solid var(--border, #e5e7eb)',
          }}>
            <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h, #111)' }}>
              Notifications{unread > 0 ? ` · ${unread} new` : ''}
            </span>
            {unread > 0 && (
              <button
                onClick={markAll}
                style={{
                  display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11.5, fontWeight: 600,
                  color: 'var(--portal-purple, #7C3AED)', background: 'none', border: 'none', cursor: 'pointer',
                }}
              >
                <CheckCheck size={13} /> Mark all read
              </button>
            )}
          </div>

          <div style={{ maxHeight: 380, overflowY: 'auto' }}>
            {items.length === 0 ? (
              <div style={{ padding: '28px 14px', textAlign: 'center', fontSize: 12.5, color: 'var(--text-muted, #8a8f98)' }}>
                You're all caught up.
              </div>
            ) : (
              items.map(n => (
                <button
                  key={n.id}
                  onClick={() => openItem(n)}
                  style={{
                    display: 'block', width: '100%', textAlign: 'left', cursor: 'pointer',
                    padding: '11px 14px', border: 'none', borderBottom: '1px solid var(--border, #eee)',
                    background: n.is_read ? 'transparent' : 'var(--portal-purple-glow, rgba(124,58,237,0.10))',
                  }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    {!n.is_read && <span style={{ width: 7, height: 7, borderRadius: '50%', background: 'var(--portal-purple, #7C3AED)', flexShrink: 0 }} />}
                    <span style={{ fontSize: 12.5, fontWeight: n.is_read ? 500 : 700, color: 'var(--text-h, #111)' }}>
                      {n.title}
                    </span>
                  </div>
                  {n.message && (
                    <div style={{ fontSize: 11.5, color: 'var(--text-muted, #8a8f98)', marginTop: 3, lineHeight: 1.4 }}>
                      {n.message}
                    </div>
                  )}
                  <div style={{ fontSize: 10.5, color: 'var(--text-muted, #9aa0a6)', marginTop: 4 }}>
                    {timeAgo(n.created_at)}
                  </div>
                </button>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  )
}

function timeAgo(ts) {
  if (!ts) return ''
  const d = new Date(ts)
  const s = Math.floor((Date.now() - d.getTime()) / 1000)
  if (s < 60) return 'just now'
  const m = Math.floor(s / 60); if (m < 60) return `${m}m ago`
  const h = Math.floor(m / 60); if (h < 24) return `${h}h ago`
  const days = Math.floor(h / 24); if (days < 7) return `${days}d ago`
  return d.toLocaleDateString()
}
