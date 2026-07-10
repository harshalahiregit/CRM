import { useQuery } from '@tanstack/react-query'
import { LifeBuoy, DoorOpen, Timer, CheckCircle2, Trophy } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

const KPI = ({ label, value, sub, icon: Icon, gradient, shadow }) => (
  <div className="kpi-3d relative overflow-hidden">
    <div className="absolute -top-4 -right-4 w-20 h-20 rounded-full opacity-[0.07]" style={{ background: gradient }} />
    <div className="flex items-start justify-between relative z-10">
      <div
        className="w-11 h-11 rounded-2xl flex items-center justify-center"
        style={{ background: gradient, boxShadow: `0 6px 18px ${shadow}40, inset 0 1px 0 rgba(255,255,255,0.25)` }}
      >
        <Icon size={20} className="text-white" />
      </div>
    </div>
    <p className="text-2xl font-black mt-3 relative z-10" style={{ color: 'var(--text-h)', letterSpacing: '-0.03em' }}>{value}</p>
    <p className="text-sm font-medium relative z-10" style={{ color: 'var(--text-muted)' }}>{label}</p>
    {sub && <p className="text-xs mt-1 relative z-10" style={{ color: 'var(--text-muted)' }}>{sub}</p>}
  </div>
)

const STATUS_COLOR = { open: '#3b82f6', 'in-progress': '#fbbf24', closed: '#10b981' }
const PRIORITY_COLOR = { urgent: '#ef4444', high: '#f87171', medium: '#fbbf24', low: '#10b981' }

export default function HelpdeskAnalytics() {
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['helpdesk-analytics'],
    queryFn: helpdeskApi.analytics,
    refetchInterval: 30000,
  })

  if (isLoading) {
    return (
      <div className="space-y-6 animate-fade-in">
        <div className="skeleton h-12 w-64 rounded-xl" style={{ background: 'var(--border)' }} />
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          {[1, 2, 3, 4].map(i => <div key={i} className="skeleton h-32 rounded-2xl" style={{ background: 'var(--border)' }} />)}
        </div>
      </div>
    )
  }

  if (isError) {
    return (
      <div className="p-6 rounded-2xl border" style={{ borderColor: 'rgba(239,68,68,0.3)', background: 'rgba(239,68,68,0.06)' }}>
        <p className="font-semibold text-red-400">Couldn’t load analytics</p>
        <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>{error?.message}</p>
      </div>
    )
  }

  const a = data || {}
  const byAssignee = a.by_assignee || a.resolved_by_assignee || []
  const byStatus = a.by_status || []
  const byPriority = a.by_priority || []
  const maxTotal = Math.max(...byAssignee.map(r => r.total ?? r.resolved ?? 0), 1)
  const maxStatus = Math.max(...byStatus.map(s => s.count), 1)

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      <div>
        <p className="label-caps mb-1">Helpdesk & Support</p>
        <h1 className="font-black" style={{ fontSize: 'clamp(1.4rem,2.5vw,1.9rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
          Support <span className="text-gradient">Analytics</span>
        </h1>
        <p className="text-sm mt-0.5" style={{ color: 'var(--text-muted)' }}>Manager dashboard · live metrics</p>
      </div>

      {/* KPI row */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KPI label="Total Tickets" value={a.total ?? 0} icon={LifeBuoy}
          gradient="linear-gradient(145deg,#22d3ee,#0891b2)" shadow="#06b6d4" />
        <KPI label="Open Rate" value={`${a.open_rate ?? 0}%`} sub={`${a.unresolved ?? 0} unresolved`} icon={DoorOpen}
          gradient="linear-gradient(145deg,#60a5fa,#2563eb)" shadow="#3b82f6" />
        <KPI label="Avg. Closing Time" value={`${a.avg_closing_hours ?? 0}h`} sub={`${a.closed ?? 0} closed`} icon={Timer}
          gradient="linear-gradient(145deg,#fbbf24,#d97706)" shadow="#f59e0b" />
        <KPI label="Resolved" value={a.closed ?? 0} icon={CheckCircle2}
          gradient="linear-gradient(145deg,#34d399,#10b981)" shadow="#10b981" />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        {/* Resolved by assignee */}
        <section className="rounded-2xl border p-5" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
          <div className="flex items-center gap-2 mb-4">
            <Trophy size={16} style={{ color: '#22d3ee' }} />
            <h2 className="font-bold" style={{ color: 'var(--text-h)' }}>Tickets by Assignee</h2>
          </div>
          {byAssignee.length === 0 ? (
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No assigned tickets yet.</p>
          ) : (
            <div className="space-y-3">
              {byAssignee.map(row => (
                <div key={row.assignee_id ?? 'unassigned'}>
                  <div className="flex justify-between text-xs mb-1" style={{ color: 'var(--text-muted)' }}>
                    <span className="font-medium" style={{ color: 'var(--text-h)' }}>{row.name}</span>
                    <span>
                      {row.total ?? row.resolved} tickets
                      {row.avg_close_hours != null && <span className="ml-2 opacity-70">· avg {row.avg_close_hours}h close</span>}
                    </span>
                  </div>
                  <div className="h-2.5 rounded-full overflow-hidden flex" style={{ background: 'var(--border)' }}>
                    {/* full bar = total, filled segment = resolved */}
                    <div className="h-full" style={{ width: `${((row.total ?? row.resolved) / maxTotal) * 100}%`, background: 'rgba(34,211,238,0.25)' }}>
                      <div className="h-full rounded-l-full" style={{ width: `${row.total ? (row.resolved / row.total) * 100 : 0}%`, background: 'linear-gradient(90deg,#22d3ee,#0891b2)' }} />
                    </div>
                  </div>
                </div>
              ))}
              <p className="text-[10px] pt-1" style={{ color: 'var(--text-muted)' }}>Solid = resolved · translucent = still open</p>
            </div>
          )}
        </section>

        {/* Status + priority breakdown */}
        <section className="rounded-2xl border p-5" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
          <h2 className="font-bold mb-4" style={{ color: 'var(--text-h)' }}>Tickets by Status</h2>
          <div className="space-y-3 mb-6">
            {byStatus.map(s => (
              <div key={s.status}>
                <div className="flex justify-between text-xs mb-1" style={{ color: 'var(--text-muted)' }}>
                  <span className="capitalize font-medium" style={{ color: 'var(--text-h)' }}>{s.status}</span>
                  <span>{s.count}</span>
                </div>
                <div className="h-2 rounded-full overflow-hidden" style={{ background: 'var(--border)' }}>
                  <div className="h-full rounded-full" style={{ width: `${(s.count / maxStatus) * 100}%`, background: STATUS_COLOR[s.status] || '#94a3b8' }} />
                </div>
              </div>
            ))}
          </div>

          <h3 className="text-xs font-semibold uppercase tracking-wide mb-3" style={{ color: 'var(--text-muted)' }}>By Priority</h3>
          <div className="flex gap-2">
            {byPriority.map(p => (
              <div key={p.priority} className="flex-1 rounded-xl px-3 py-2.5 text-center"
                style={{ background: `${PRIORITY_COLOR[p.priority]}1a`, border: `1px solid ${PRIORITY_COLOR[p.priority]}33` }}>
                <p className="text-lg font-black" style={{ color: PRIORITY_COLOR[p.priority] }}>{p.count}</p>
                <p className="text-[10px] uppercase font-semibold capitalize" style={{ color: 'var(--text-muted)' }}>{p.priority}</p>
              </div>
            ))}
          </div>
        </section>
      </div>
    </div>
  )
}
