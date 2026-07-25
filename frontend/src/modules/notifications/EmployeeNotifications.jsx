import { useState, useEffect } from 'react'
import { Bell, Inbox, AlarmClock } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { priorityStyle, timeAgo, TYPE_LABEL } from './ui'

/**
 * Read-only Employee Profile notifications panel — Latest, Pending and Reminder
 * history for the employee's linked user account. Purely presentational; no writes.
 */
export default function EmployeeNotifications({ employeeId }) {
  const [data, setData] = useState({ latest: [], pending: [], reminders: [] })
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!employeeId) return
    let alive = true
    hrApi.notifications.forEmployee(employeeId)
      .then(d => { if (alive) setData(d) })
      .catch(() => {})
      .finally(() => { if (alive) setLoading(false) })
    return () => { alive = false }
  }, [employeeId])

  const List = ({ icon: Icon, title, items, empty }) => (
    <div className="rounded-xl p-3" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
      <p className="text-[11px] font-bold uppercase mb-2 flex items-center gap-1.5" style={{ color: 'var(--text-muted)', letterSpacing: '0.04em' }}><Icon size={12} style={{ color: '#a78bfa' }} /> {title}</p>
      {items.length === 0 ? <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{empty}</p> : (
        <div className="space-y-1.5">
          {items.map(n => {
            const ps = priorityStyle(n.priority)
            return (
              <div key={n.id} className="flex items-start gap-2">
                <span className="w-1.5 h-1.5 rounded-full mt-1.5 flex-shrink-0" style={{ background: ps.dot }} />
                <div className="min-w-0 flex-1">
                  <p className="text-xs font-semibold truncate" style={{ color: 'var(--text-h)' }}>{n.title}</p>
                  <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{n.module} · {TYPE_LABEL[n.notification_type] || n.notification_type} · {timeAgo(n.created_at)}</p>
                </div>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )

  return (
    <div>
      <p className="text-[11px] font-bold uppercase mb-2 flex items-center gap-1.5" style={{ color: 'var(--text-muted)', letterSpacing: '0.04em' }}><Bell size={12} /> Notifications</p>
      {loading ? <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Loading…</p> : (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
          <List icon={Inbox} title="Latest" items={data.latest} empty="No notifications yet." />
          <List icon={Bell} title="Pending" items={data.pending} empty="Nothing pending." />
          <List icon={AlarmClock} title="Reminder History" items={data.reminders} empty="No reminders." />
        </div>
      )}
    </div>
  )
}
