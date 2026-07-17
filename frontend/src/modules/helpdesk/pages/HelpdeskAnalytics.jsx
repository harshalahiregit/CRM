import { useQuery } from '@tanstack/react-query'
import {
  LifeBuoy, DoorOpen, Timer, CheckCircle2, Trophy,
  TrendingUp, AlertTriangle, Users, Clock
} from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

/* ── KPI Card ──────────────────────────────────────────────── */
function KPICard({ label, value, sub, icon: Icon, gradient, shadow }) {
  return (
    <div
      className="relative overflow-hidden rounded-2xl p-5 transition-all duration-300 hover:-translate-y-1"
      style={{
        background: 'var(--bg-card)',
        border: '1px solid var(--border)',
        boxShadow: 'var(--shadow-card)',
      }}
    >
      {/* Decorative orb */}
      <div
        className="absolute -top-6 -right-6 w-24 h-24 rounded-full opacity-[0.06] pointer-events-none"
        style={{ background: gradient }}
      />
      <div className="relative z-10 flex items-start justify-between mb-3">
        <div
          className="w-11 h-11 rounded-2xl flex items-center justify-center"
          style={{
            background: gradient,
            boxShadow: `0 6px 20px ${shadow}50`,
          }}
        >
          <Icon size={20} className="text-white" />
        </div>
        <div
          className="w-1.5 h-1.5 rounded-full mt-1.5"
          style={{ background: shadow, boxShadow: `0 0 8px ${shadow}` }}
        />
      </div>
      <p
        className="text-2xl font-black relative z-10"
        style={{ color: 'var(--text-h)', letterSpacing: '-0.03em' }}
      >
        {value}
      </p>
      <p className="text-sm font-medium mt-0.5 relative z-10" style={{ color: 'var(--text-muted)' }}>
        {label}
      </p>
      {sub && (
        <p className="text-xs mt-1 relative z-10 font-medium" style={{ color: 'var(--text-muted)', opacity: 0.75 }}>
          {sub}
        </p>
      )}
    </div>
  )
}

/* ── Animated Bar ────────────────────────────────────────────── */
function Bar({ pct, color, bg = 'var(--border)', height = 8, track = true, label, value }) {
  return (
    <div>
      {(label || value) && (
        <div className="flex justify-between text-xs mb-1.5" style={{ color: 'var(--text-muted)' }}>
          <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{label}</span>
          <span>{value}</span>
        </div>
      )}
      <div
        className="overflow-hidden rounded-full"
        style={{ height, background: bg }}
      >
        <div
          className="h-full rounded-full transition-all duration-700"
          style={{
            width: `${Math.max(0, Math.min(100, pct))}%`,
            background: color,
          }}
        />
      </div>
    </div>
  )
}

/* ── Status color map ─────────────────────────────────────────── */
const STATUS_COLOR = {
  open:          '#3b82f6',
  'in-progress': '#f59e0b',
  closed:        '#10b981',
  pending:       '#a78bfa',
}
const PRIORITY_COLOR = {
  urgent: '#ef4444',
  high:   '#f87171',
  medium: '#f59e0b',
  low:    '#10b981',
}

/* ── Main Component ──────────────────────────────────────────── */
export default function HelpdeskAnalytics() {
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['helpdesk-analytics'],
    queryFn: helpdeskApi.analytics,
    refetchInterval: 30000,
  })

  if (isLoading) {
    return (
      <div className="space-y-6 animate-fade-in">
        <div className="space-y-1">
          <div className="h-3 w-28 rounded-lg animate-pulse" style={{ background: 'var(--border)' }} />
          <div className="h-8 w-56 rounded-xl animate-pulse" style={{ background: 'var(--border)' }} />
        </div>
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          {[1, 2, 3, 4].map(i => (
            <div
              key={i}
              className="h-32 rounded-2xl animate-pulse"
              style={{ background: 'var(--border)' }}
            />
          ))}
        </div>
        <div className="grid gap-4 lg:grid-cols-2">
          {[1, 2].map(i => (
            <div key={i} className="h-56 rounded-2xl animate-pulse" style={{ background: 'var(--border)' }} />
          ))}
        </div>
      </div>
    )
  }

  if (isError) {
    return (
      <div
        className="p-6 rounded-2xl flex items-start gap-3"
        style={{ border: '1px solid rgba(239,68,68,0.3)', background: 'rgba(239,68,68,0.06)' }}
      >
        <AlertTriangle size={18} style={{ color: '#ef4444', flexShrink: 0, marginTop: 2 }} />
        <div>
          <p className="font-semibold text-red-400">Couldn't load analytics</p>
          <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>{error?.message}</p>
        </div>
      </div>
    )
  }

  const a = data || {}
  const byAssignee = a.by_assignee || a.resolved_by_assignee || []
  const byStatus = a.by_status || []
  const byPriority = a.by_priority || []
  const byDepartment = a.by_department || []
  const maxTotal = Math.max(...byAssignee.map(r => r.total ?? r.resolved ?? 0), 1)
  const maxStatus = Math.max(...byStatus.map(s => s.count), 1)
  const maxDept = Math.max(...byDepartment.map(d => d.count), 1)

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">

      {/* Page title */}
      <div>
        <p className="label-caps mb-1">Helpdesk &amp; Support</p>
        <h1
          className="font-black"
          style={{
            fontSize: 'clamp(1.4rem,2.5vw,1.9rem)',
            color: 'var(--text-h)',
            letterSpacing: '-0.025em',
          }}
        >
          Support <span className="text-gradient">Analytics</span>
        </h1>
        <p className="text-sm mt-0.5" style={{ color: 'var(--text-muted)' }}>
          Live metrics · auto-refreshes every 30 s
        </p>
      </div>

      {/* KPI Row */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KPICard
          label="Total Tickets"
          value={a.total ?? 0}
          icon={LifeBuoy}
          gradient="linear-gradient(145deg,#22d3ee,#0891b2)"
          shadow="#06b6d4"
        />
        <KPICard
          label="Open Rate"
          value={`${a.open_rate ?? 0}%`}
          sub={`${a.unresolved ?? 0} unresolved`}
          icon={DoorOpen}
          gradient="linear-gradient(145deg,#60a5fa,#2563eb)"
          shadow="#3b82f6"
        />
        <KPICard
          label="Avg. Closing Time"
          value={`${a.avg_closing_hours ?? 0}h`}
          sub={`${a.closed ?? 0} closed total`}
          icon={Timer}
          gradient="linear-gradient(145deg,#fbbf24,#d97706)"
          shadow="#f59e0b"
        />
        <KPICard
          label="Resolved"
          value={a.closed ?? 0}
          icon={CheckCircle2}
          gradient="linear-gradient(145deg,#34d399,#059669)"
          shadow="#10b981"
        />
      </div>

      {/* Charts row */}
      <div className="grid gap-4 lg:grid-cols-2">

        {/* Assignee performance */}
        <div
          className="rounded-2xl p-5"
          style={{
            background: 'var(--bg-card)',
            border: '1px solid var(--border)',
            boxShadow: 'var(--shadow-card)',
          }}
        >
          <div className="flex items-center gap-2 mb-5">
            <div
              className="w-8 h-8 rounded-xl flex items-center justify-center"
              style={{ background: 'rgba(34,211,238,0.12)' }}
            >
              <Trophy size={15} style={{ color: '#22d3ee' }} />
            </div>
            <div>
              <h2 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Tickets by Assignee</h2>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Solid = resolved · translucent = open</p>
            </div>
          </div>

          {byAssignee.length === 0 ? (
            <p className="text-sm py-8 text-center" style={{ color: 'var(--text-muted)' }}>
              No assigned tickets yet.
            </p>
          ) : (
            <div className="space-y-4">
              {byAssignee.map(row => {
                const total = row.total ?? row.resolved ?? 0
                const resolved = row.resolved ?? 0
                const pct = (total / maxTotal) * 100
                const resolvedPct = total > 0 ? (resolved / total) * 100 : 0
                return (
                  <div key={row.assignee_id ?? 'unassigned'}>
                    <div className="flex justify-between text-xs mb-1.5" style={{ color: 'var(--text-muted)' }}>
                      <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{row.name}</span>
                      <span className="flex items-center gap-2">
                        <span className="font-medium">{total} tickets</span>
                        {row.avg_close_hours != null && (
                          <span className="opacity-60">avg {row.avg_close_hours}h</span>
                        )}
                      </span>
                    </div>
                    {/* Track: total; Fill: resolved */}
                    <div
                      className="h-2.5 rounded-full overflow-hidden"
                      style={{ background: 'rgba(34,211,238,0.12)' }}
                    >
                      <div
                        className="h-full rounded-full overflow-hidden transition-all duration-700"
                        style={{ width: `${pct}%`, background: 'rgba(34,211,238,0.18)' }}
                      >
                        <div
                          className="h-full rounded-l-full"
                          style={{
                            width: `${resolvedPct}%`,
                            background: 'linear-gradient(90deg,#22d3ee,#0891b2)',
                          }}
                        />
                      </div>
                    </div>
                  </div>
                )
              })}
            </div>
          )}
        </div>

        {/* Status + Priority */}
        <div
          className="rounded-2xl p-5"
          style={{
            background: 'var(--bg-card)',
            border: '1px solid var(--border)',
            boxShadow: 'var(--shadow-card)',
          }}
        >
          {/* Status */}
          <div className="flex items-center gap-2 mb-5">
            <div
              className="w-8 h-8 rounded-xl flex items-center justify-center"
              style={{ background: 'rgba(59,130,246,0.12)' }}
            >
              <TrendingUp size={15} style={{ color: '#3b82f6' }} />
            </div>
            <h2 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Status Breakdown</h2>
          </div>

          <div className="space-y-3 mb-6">
            {byStatus.map(s => (
              <Bar
                key={s.status}
                label={s.status}
                value={`${s.count} tickets`}
                pct={(s.count / maxStatus) * 100}
                color={STATUS_COLOR[s.status] || '#94a3b8'}
              />
            ))}
            {byStatus.length === 0 && (
              <p className="text-sm py-4 text-center" style={{ color: 'var(--text-muted)' }}>No data.</p>
            )}
          </div>

          {/* Priority chips */}
          <div
            className="pt-4"
            style={{ borderTop: '1px solid var(--border)' }}
          >
            <h3
              className="text-xs font-bold uppercase tracking-widest mb-3"
              style={{ color: 'var(--text-muted)' }}
            >
              By Priority
            </h3>
            <div className="grid grid-cols-4 gap-2">
              {byPriority.map(p => (
                <div
                  key={p.priority}
                  className="rounded-xl px-2 py-3 text-center transition-all duration-200 hover:-translate-y-0.5"
                  style={{
                    background: `${PRIORITY_COLOR[p.priority]}15`,
                    border: `1px solid ${PRIORITY_COLOR[p.priority]}30`,
                  }}
                >
                  <p
                    className="text-xl font-black"
                    style={{ color: PRIORITY_COLOR[p.priority], letterSpacing: '-0.02em' }}
                  >
                    {p.count}
                  </p>
                  <p
                    className="text-[10px] uppercase font-bold mt-0.5 capitalize"
                    style={{ color: 'var(--text-muted)' }}
                  >
                    {p.priority}
                  </p>
                </div>
              ))}
              {byPriority.length === 0 && (
                <p
                  className="col-span-4 text-sm text-center py-4"
                  style={{ color: 'var(--text-muted)' }}
                >
                  No tickets yet.
                </p>
              )}
            </div>
          </div>

          {/* By Department — which queue is carrying the load */}
          <div className="pt-4 mt-4" style={{ borderTop: '1px solid var(--border)' }}>
            <h3
              className="text-xs font-bold uppercase tracking-widest mb-3"
              style={{ color: 'var(--text-muted)' }}
            >
              By Department
            </h3>
            <div className="space-y-3">
              {byDepartment.map(d => (
                <Bar
                  key={d.department_id ?? 'none'}
                  label={d.department}
                  value={`${d.count} tickets`}
                  pct={(d.count / maxDept) * 100}
                  color="#0ea5e9"
                />
              ))}
              {byDepartment.length === 0 && (
                <p className="text-sm text-center py-4" style={{ color: 'var(--text-muted)' }}>
                  No departments configured.
                </p>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
