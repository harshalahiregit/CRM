import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { Bell, AlertTriangle, Clock, CalendarDays, Inbox, ChevronRight } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { priorityStyle, timeAgo } from './ui'

/**
 * Reusable dashboard widget for the Central Notification Engine. Any module
 * dashboard can drop this in — it shows the caller's notification pulse (Unread,
 * Today's, Critical, Overdue) plus recent activity, and links to the Notification
 * Center. Self-contained, read-only, tenant-scoped via the API.
 */
export default function NotificationWidget({ compact = false }) {
  const navigate = useNavigate()
  const [stats, setStats] = useState({ unread: 0, read: 0, critical: 0, overdue: 0, today: 0 })
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let alive = true
    Promise.all([hrApi.notifications.stats(), hrApi.notifications.bell(compact ? 4 : 6)])
      .then(([s, b]) => { if (!alive) return; setStats(s); setItems(b.items || []) })
      .catch(() => {})
      .finally(() => { if (alive) setLoading(false) })
    return () => { alive = false }
  }, [compact])

  const go = () => navigate('/app/hr/settings/notifications')
  const CARDS = [
    { l: 'Unread', v: stats.unread, c: '#7C3AED', icon: Inbox },
    { l: "Today's", v: stats.today, c: '#3b82f6', icon: CalendarDays },
    { l: 'Critical', v: stats.critical, c: '#ef4444', icon: AlertTriangle },
    { l: 'Overdue', v: stats.overdue, c: '#f59e0b', icon: Clock },
  ]

  return (
    <div className="card-3d" style={{ padding: '18px' }}>
      <div className="flex items-center justify-between mb-4">
        <h3 className="font-black flex items-center gap-2 text-sm" style={{ color: 'var(--text-h)' }}><Bell size={16} style={{ color: '#a78bfa' }} /> Notifications</h3>
        <button onClick={go} className="flex items-center gap-1 text-[11px] font-bold" style={{ color: '#a78bfa' }}>Open Center <ChevronRight size={13} /></button>
      </div>

      <div className={`grid ${compact ? 'grid-cols-4' : 'grid-cols-2 md:grid-cols-4'} gap-3 mb-4`}>
        {CARDS.map(k => (
          <button key={k.l} onClick={go} className="rounded-xl p-3 text-left transition-transform hover:scale-[1.02]" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <div className="flex items-center gap-1.5 mb-1"><k.icon size={12} style={{ color: k.c }} /><span className="text-[10px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>{k.l}</span></div>
            <p className="text-2xl font-black" style={{ color: k.c }}>{k.v}</p>
          </button>
        ))}
      </div>

      {!compact && (
        <div>
          <p className="label-caps mb-2">Recent Activity</p>
          {loading ? <p className="text-xs py-4 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</p>
            : items.length === 0 ? <p className="text-xs py-4 text-center" style={{ color: 'var(--text-muted)' }}>No recent notifications.</p>
            : (
              <div className="space-y-1.5">
                {items.map(n => {
                  const ps = priorityStyle(n.priority)
                  return (
                    <button key={n.id} onClick={go} className="w-full flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-left transition-colors" style={{ background: n.is_read ? 'transparent' : 'rgba(124,58,237,0.04)' }}>
                      <span className="w-2 h-2 rounded-full flex-shrink-0" style={{ background: ps.dot }} />
                      <div className="min-w-0 flex-1"><p className="text-xs font-semibold truncate" style={{ color: 'var(--text-h)' }}>{n.title}</p><p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{n.module} · {timeAgo(n.created_at)}</p></div>
                    </button>
                  )
                })}
              </div>
            )}
        </div>
      )}
    </div>
  )
}
