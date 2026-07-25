import { Bell, CheckCheck, ExternalLink } from 'lucide-react'
import { useState, useRef, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTheme } from '@/context/ThemeContext'
import hrApi from '@/services/hrApi'
import { priorityStyle, timeAgo } from '@/modules/notifications/ui'

/**
 * Navbar notification bell — real unread count + dropdown of the newest items.
 * Reuses the Central Notification Engine bell API. Polls every 60s. Additive:
 * drops into the Header in place of the old static bell.
 */
export default function NotificationBell() {
  const { isDark } = useTheme()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [unread, setUnread] = useState(0)
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(false)
  const ref = useRef(null)

  const load = useCallback(async () => {
    try {
      const data = await hrApi.notifications.bell(10)
      setUnread(data.unread || 0)
      setItems(data.items || [])
    } catch { /* silent — bell must never break the navbar */ }
  }, [])

  useEffect(() => { load(); const t = setInterval(load, 60000); return () => clearInterval(t) }, [load])

  useEffect(() => {
    const h = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false) }
    document.addEventListener('mousedown', h)
    return () => document.removeEventListener('mousedown', h)
  }, [])

  const toggle = () => { setOpen(o => !o); if (!open) load() }

  const markRead = async (n) => {
    if (!n.is_read) {
      setItems(prev => prev.map(x => x.id === n.id ? { ...x, is_read: true } : x))
      setUnread(u => Math.max(0, u - 1))
      try { await hrApi.notifications.markRead(n.id) } catch { load() }
    }
  }

  const openRecord = async (n) => {
    await markRead(n)
    setOpen(false)
    if (n.action_url) navigate(n.action_url)
  }

  const markAll = async () => {
    setItems(prev => prev.map(x => ({ ...x, is_read: true })))
    setUnread(0)
    try { await hrApi.notifications.markAllRead() } catch { load() }
  }

  const menuBg = isDark ? '#1c1c2e' : '#ffffff'
  const menuBdr = isDark ? 'rgba(124,58,237,0.25)' : 'rgba(124,58,237,0.15)'

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={toggle}
        className="relative w-9 h-9 rounded-xl flex items-center justify-center transition-all duration-200"
        style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}
        aria-label="Notifications"
        onMouseEnter={e => { e.currentTarget.style.borderColor = 'rgba(124,58,237,0.3)'; e.currentTarget.style.color = 'var(--text-h)' }}
        onMouseLeave={e => { e.currentTarget.style.borderColor = 'var(--border)'; e.currentTarget.style.color = 'var(--text-muted)' }}
      >
        <Bell size={17} />
        {unread > 0 && (
          <span
            className="absolute -top-1 -right-1 min-w-[17px] h-[17px] px-1 rounded-full flex items-center justify-center text-[10px] font-black text-white"
            style={{ background: '#ef4444', border: `2px solid ${isDark ? '#0b0b16' : '#f0f0f8'}`, boxShadow: '0 0 6px rgba(239,68,68,0.6)' }}
          >
            {unread > 99 ? '99+' : unread}
          </span>
        )}
      </button>

      {open && (
        <div
          className="absolute right-0 top-12 w-[360px] rounded-2xl overflow-hidden animate-scale-in z-50"
          style={{ background: menuBg, border: `1px solid ${menuBdr}`, boxShadow: '0 20px 60px rgba(0,0,0,0.3), 0 4px 16px rgba(124,58,237,0.15)' }}
        >
          <div className="flex items-center justify-between px-4 py-3" style={{ borderBottom: '1px solid var(--border)' }}>
            <div className="flex items-center gap-2">
              <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>Notifications</span>
              {unread > 0 && (
                <span className="text-[10px] font-bold px-1.5 py-0.5 rounded-md" style={{ background: 'rgba(124,58,237,0.14)', color: '#a78bfa' }}>{unread} new</span>
              )}
            </div>
            {unread > 0 && (
              <button onClick={markAll} className="flex items-center gap-1 text-[11px] font-semibold" style={{ color: '#a78bfa' }}>
                <CheckCheck size={13} /> Mark all read
              </button>
            )}
          </div>

          <div className="max-h-[380px] overflow-y-auto">
            {items.length === 0 ? (
              <div className="px-4 py-10 text-center text-sm" style={{ color: 'var(--text-muted)' }}>
                <Bell size={26} className="mx-auto mb-2 opacity-40" />
                You're all caught up
              </div>
            ) : items.map(n => {
              const ps = priorityStyle(n.priority)
              return (
                <button
                  key={n.id}
                  onClick={() => (n.action_url ? openRecord(n) : markRead(n))}
                  className="w-full text-left px-4 py-3 flex gap-3 transition-colors"
                  style={{ borderBottom: '1px solid var(--border)', background: n.is_read ? 'transparent' : (isDark ? 'rgba(124,58,237,0.05)' : 'rgba(124,58,237,0.03)') }}
                  onMouseEnter={e => e.currentTarget.style.background = isDark ? 'rgba(124,58,237,0.08)' : 'rgba(124,58,237,0.05)'}
                  onMouseLeave={e => e.currentTarget.style.background = n.is_read ? 'transparent' : (isDark ? 'rgba(124,58,237,0.05)' : 'rgba(124,58,237,0.03)')}
                >
                  <span className="w-2 h-2 rounded-full mt-1.5 flex-shrink-0" style={{ background: ps.dot, boxShadow: n.is_read ? 'none' : `0 0 6px ${ps.dot}` }} />
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                      <span className="text-[10px] font-bold uppercase tracking-wide" style={{ color: ps.fg }}>{n.module}</span>
                      <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>· {timeAgo(n.created_at)}</span>
                    </div>
                    <p className="text-xs font-semibold mt-0.5 truncate" style={{ color: 'var(--text-h)' }}>{n.title}</p>
                    {n.message && <p className="text-[11px] mt-0.5 line-clamp-2" style={{ color: 'var(--text-muted)' }}>{n.message}</p>}
                    {n.action_url && (
                      <span className="inline-flex items-center gap-1 text-[10px] font-semibold mt-1" style={{ color: '#a78bfa' }}>
                        <ExternalLink size={10} /> {n.action_label || 'Open'}
                      </span>
                    )}
                  </div>
                </button>
              )
            })}
          </div>

          <button
            onClick={() => { setOpen(false); navigate('/app/hr/settings/notifications') }}
            className="w-full py-3 text-xs font-bold transition-colors"
            style={{ color: '#a78bfa', borderTop: '1px solid var(--border)' }}
            onMouseEnter={e => e.currentTarget.style.background = isDark ? 'rgba(124,58,237,0.08)' : 'rgba(124,58,237,0.05)'}
            onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
          >
            View all notifications
          </button>
        </div>
      )}
    </div>
  )
}
