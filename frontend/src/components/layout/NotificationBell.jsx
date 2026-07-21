import { useState, useRef, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Bell, CheckCheck, Ticket, RefreshCw, MessageSquare, Inbox, TicketPlus, CheckSquare, AtSign, Eye, Activity, AlarmClock, FolderKanban, UserPlus, TrendingDown, PackageX, PackageCheck, Truck, Ban, CalendarClock, GitBranch } from 'lucide-react'
import { notificationApi } from '@/services/notificationApi'

/**
 * Header bell. Replaces the old decorative bell, whose badge dot was hard-coded
 * so it always looked like you had alerts.
 *
 * Polls on an interval rather than websockets — the CRM has no realtime channel,
 * and a 60s poll is plenty for ticket assignment/reply notices.
 */

const ICONS = {
  'ticket.created':          { icon: TicketPlus,     color: 'var(--color-success-500)' },
  'ticket.assigned':         { icon: Ticket,         color: 'var(--color-primary-500)' },
  'ticket.reopened':         { icon: RefreshCw,      color: 'var(--color-warning-500)' },
  'ticket.customer_replied': { icon: MessageSquare,  color: 'var(--color-support-500)' },
  'task.assigned':           { icon: CheckSquare,    color: 'var(--color-primary-500)' },
  'task.follower_added':     { icon: Eye,            color: 'var(--text-muted)' },
  'task.commented':          { icon: MessageSquare,  color: 'var(--color-support-500)' },
  'task.mentioned':          { icon: AtSign,         color: 'var(--color-warning-500)' },
  'task.status_changed':     { icon: Activity,       color: 'var(--color-info-500)' },
  'task.due_soon':           { icon: AlarmClock,     color: 'var(--color-warning-500)' },
  'task.overdue':            { icon: AlarmClock,     color: 'var(--color-danger-500)' },
  'task.reminder':           { icon: AlarmClock,     color: 'var(--color-primary-500)' },
  'project.member_added':    { icon: UserPlus,       color: 'var(--color-primary-500)' },
  'project.status_changed':  { icon: FolderKanban,   color: 'var(--color-info-500)' },
  'project.due_soon':        { icon: AlarmClock,     color: 'var(--color-warning-500)' },
  'project.overdue':         { icon: AlarmClock,     color: 'var(--color-danger-500)' },
  // Subtasks. "Assigned to you" is the loud one — it's work landing on your
  // plate; the other two are things happening inside work you already own.
  'task.subtask_assigned':   { icon: GitBranch,      color: 'var(--color-primary-500)' },
  'task.subtask_added':      { icon: GitBranch,      color: 'var(--text-muted)' },
  'task.subtask_completed':  { icon: CheckCheck,     color: 'var(--color-success-500)' },
  // Inventory. Stock alerts are deliberately louder than document activity:
  // "we've run out" needs a reaction, "a receipt was posted" is just news.
  'inventory.low_stock':       { icon: TrendingDown,   color: 'var(--color-warning-500)' },
  'inventory.out_of_stock':    { icon: PackageX,       color: 'var(--color-danger-500)' },
  'inventory.expiring':        { icon: CalendarClock,  color: 'var(--color-warning-500)' },
  'inventory.voucher_posted':  { icon: PackageCheck,   color: 'var(--color-success-500)' },
  'inventory.voucher_cancelled': { icon: Ban,          color: 'var(--color-danger-500)' },
  'inventory.transfer_incoming': { icon: Truck,        color: 'var(--color-info-500)' },
}

const timeAgo = (ts) => {
  const s = Math.floor((Date.now() - new Date(ts).getTime()) / 1000)
  if (s < 60) return 'just now'
  const m = Math.floor(s / 60); if (m < 60) return `${m}m ago`
  const h = Math.floor(m / 60); if (h < 24) return `${h}h ago`
  const d = Math.floor(h / 24); if (d < 7) return `${d}d ago`
  return new Date(ts).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' })
}

export default function NotificationBell({ isDark }) {
  const [open, setOpen] = useState(false)
  const ref = useRef(null)
  const navigate = useNavigate()
  const qc = useQueryClient()

  const { data } = useQuery({
    queryKey: ['notifications'],
    queryFn: notificationApi.list,
    refetchInterval: 60000,
    refetchOnWindowFocus: true,
  })

  const items = data?.items || []
  const unread = data?.unread_count || 0

  const markRead = useMutation({
    mutationFn: (id) => notificationApi.markRead(id),
    onSettled: () => qc.invalidateQueries({ queryKey: ['notifications'] }),
  })
  const markAll = useMutation({
    mutationFn: notificationApi.markAllRead,
    onMutate: async () => {
      await qc.cancelQueries({ queryKey: ['notifications'] })
      const prev = qc.getQueryData(['notifications'])
      qc.setQueryData(['notifications'], (o) => o ? {
        ...o, unread_count: 0, items: (o.items || []).map(i => ({ ...i, is_read: true, read_at: new Date().toISOString() })),
      } : o)
      return { prev }
    },
    onError: (_e, _v, ctx) => ctx?.prev && qc.setQueryData(['notifications'], ctx.prev),
    onSettled: () => qc.invalidateQueries({ queryKey: ['notifications'] }),
  })

  useEffect(() => {
    const h = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false) }
    const k = (e) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', h)
    document.addEventListener('keydown', k)
    return () => { document.removeEventListener('mousedown', h); document.removeEventListener('keydown', k) }
  }, [])

  const openItem = (n) => {
    if (!n.is_read) markRead.mutate(n.id)
    setOpen(false)
    if (n.link) navigate(n.link)
  }

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen(o => !o)}
        className="relative w-9 h-9 rounded-xl flex items-center justify-center transition-all duration-200"
        style={{
          background: open ? 'color-mix(in srgb, var(--color-primary-500) 14%, transparent)' : 'var(--bg-input)',
          border: `1px solid ${open ? 'rgba(124,58,237,0.4)' : 'var(--border)'}`,
          color: open ? 'var(--text-h)' : 'var(--text-muted)',
        }}
        aria-label={unread ? `Notifications (${unread} unread)` : 'Notifications'}
        onMouseEnter={e => { e.currentTarget.style.borderColor = 'rgba(124,58,237,0.3)'; e.currentTarget.style.color = 'var(--text-h)' }}
        onMouseLeave={e => { e.currentTarget.style.borderColor = open ? 'rgba(124,58,237,0.4)' : 'var(--border)'; e.currentTarget.style.color = open ? 'var(--text-h)' : 'var(--text-muted)' }}
      >
        <Bell size={17} />
        {/* Real badge — only when something is actually unread. */}
        {unread > 0 && (
          <span
            className="absolute -top-1 -right-1 min-w-[17px] h-[17px] px-1 rounded-full flex items-center justify-center text-[10px] font-black border-2"
            style={{ background: '#7C3AED', color: '#fff', borderColor: isDark ? '#0b0b16' : '#f0f0f8', boxShadow: '0 0 8px rgba(124,58,237,0.6)' }}
          >
            {unread > 9 ? '9+' : unread}
          </span>
        )}
      </button>

      {open && (
        <div
          className="absolute right-0 mt-2 rounded-2xl overflow-hidden z-[80]"
          style={{ width: 360, maxWidth: '90vw', background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}
        >
          <header className="flex items-center gap-2 px-4 py-3" style={{ borderBottom: '1px solid var(--border)' }}>
            <h3 className="font-bold flex-1" style={{ fontSize: 14, color: 'var(--text-h)' }}>Notifications</h3>
            {unread > 0 && (
              <button
                onClick={() => markAll.mutate()}
                className="flex items-center gap-1 text-[11px] font-bold hover:opacity-70"
                style={{ color: 'var(--color-support-500)' }}
              >
                <CheckCheck size={12} /> Mark all read
              </button>
            )}
          </header>

          <ul className="overflow-auto" style={{ maxHeight: '60vh' }}>
            {items.length === 0 && (
              <li className="flex flex-col items-center gap-2 py-10">
                <span className="w-11 h-11 rounded-2xl flex items-center justify-center" style={{ background: 'var(--bg-input)' }}>
                  <Inbox size={20} style={{ color: 'var(--text-muted)' }} />
                </span>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>You're all caught up.</p>
              </li>
            )}
            {items.map(n => {
              const cfg = ICONS[n.type] || { icon: Bell, color: 'var(--text-muted)' }
              const Icon = cfg.icon
              return (
                <li key={n.id}>
                  <button
                    onClick={() => openItem(n)}
                    className="w-full flex items-start gap-3 px-4 py-3 text-left transition-colors"
                    style={{
                      borderBottom: '1px solid var(--border)',
                      background: n.is_read ? 'transparent' : 'color-mix(in srgb, var(--color-primary-500) 7%, transparent)',
                    }}
                    onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
                    onMouseLeave={e => e.currentTarget.style.background = n.is_read ? 'transparent' : 'color-mix(in srgb, var(--color-primary-500) 7%, transparent)'}
                  >
                    <span className="w-7 h-7 rounded-lg flex items-center justify-center shrink-0 mt-0.5"
                      style={{ background: `color-mix(in srgb, ${cfg.color} 14%, transparent)` }}>
                      <Icon size={13} style={{ color: cfg.color }} />
                    </span>
                    <span className="flex-1 min-w-0">
                      <span className="block text-xs leading-snug" style={{ color: 'var(--text-h)', fontWeight: n.is_read ? 500 : 700 }}>
                        {n.title}
                      </span>
                      {n.message && (
                        <span className="block text-[11px] truncate mt-0.5" style={{ color: 'var(--text-muted)' }}>{n.message}</span>
                      )}
                      <span className="block text-[10px] mt-1" style={{ color: 'var(--text-muted)', opacity: 0.7 }}>{timeAgo(n.created_at)}</span>
                    </span>
                    {!n.is_read && <span className="w-2 h-2 rounded-full shrink-0 mt-1.5" style={{ background: '#7C3AED' }} />}
                  </button>
                </li>
              )
            })}
          </ul>
        </div>
      )}
    </div>
  )
}
