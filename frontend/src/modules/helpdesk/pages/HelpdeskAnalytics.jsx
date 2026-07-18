import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  LifeBuoy, DoorOpen, Timer, CheckCircle2, Trophy,
  TrendingUp, AlertTriangle, Users, Clock, RotateCcw, PieChart, ArrowUpRight
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
/* Warning tone for the "reopened" negative-signal tiles */
const WARN = '#f59e0b'

/* ── Relative time (e.g. "3h ago") ────────────────────────────── */
function relTime(input) {
  if (!input) return ''
  const then = new Date(input).getTime()
  if (Number.isNaN(then)) return ''
  const diff = Math.round((Date.now() - then) / 1000) // seconds
  if (diff < 45) return 'just now'
  const mins = Math.round(diff / 60)
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.round(mins / 60)
  if (hrs < 24) return `${hrs}h ago`
  const days = Math.round(hrs / 24)
  if (days < 30) return `${days}d ago`
  const months = Math.round(days / 30)
  if (months < 12) return `${months}mo ago`
  return `${Math.round(months / 12)}y ago`
}

/* ── Status pill ──────────────────────────────────────────────── */
function StatusPill({ status }) {
  const c = STATUS_COLOR[status] || '#94a3b8'
  return (
    <span
      className="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize whitespace-nowrap"
      style={{ background: `${c}18`, color: c, border: `1px solid ${c}33` }}
    >
      <span className="w-1.5 h-1.5 rounded-full" style={{ background: c }} />
      {(status || 'unknown').replace(/-/g, ' ')}
    </span>
  )
}

/* ── Donut / ring chart (inline SVG, stacked stroke-dasharray) ──
 * Math: one <circle> per segment sharing radius r, so circumference
 * C = 2πr. Each segment draws an arc of length (fraction · C) via
 * strokeDasharray=`${len} ${C-len}`. Segments are stacked by rotating
 * the start point: strokeDashoffset = -accumulated, where `accumulated`
 * grows by each segment's len (a small GAP is subtracted so a 2px surface
 * gap sits between fills). rotate(-90) starts the ring at 12 o'clock. */
function Donut({ segments, total, size = 168, thickness = 20 }) {
  const r = (size - thickness) / 2
  const C = 2 * Math.PI * r
  const cx = size / 2
  const cy = size / 2
  const GAP = total > 0 && segments.filter(s => s.value > 0).length > 1 ? 3 : 0
  let accumulated = 0
  return (
    <div className="flex items-center justify-center" style={{ width: size, height: size }}>
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} role="img" aria-label="Status distribution">
        {/* track */}
        <circle
          cx={cx} cy={cy} r={r} fill="none"
          stroke="var(--border)" strokeWidth={thickness} opacity={0.5}
        />
        {total > 0 && segments.map(seg => {
          if (!seg.value) return null
          const frac = seg.value / total
          const len = Math.max(frac * C - GAP, 0.5)
          const dashoffset = -accumulated
          accumulated += frac * C
          return (
            <circle
              key={seg.key}
              cx={cx} cy={cy} r={r} fill="none"
              stroke={seg.color} strokeWidth={thickness} strokeLinecap="butt"
              strokeDasharray={`${len} ${C - len}`}
              strokeDashoffset={dashoffset}
              transform={`rotate(-90 ${cx} ${cy})`}
              style={{ transition: 'stroke-dasharray 0.7s ease' }}
            />
          )
        })}
        {/* center label */}
        <text
          x={cx} y={cy - 4} textAnchor="middle"
          style={{ fontSize: 30, fontWeight: 800, fill: 'var(--text-h)', letterSpacing: '-0.03em' }}
        >
          {total}
        </text>
        <text
          x={cx} y={cy + 16} textAnchor="middle"
          style={{ fontSize: 11, fontWeight: 600, fill: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.08em' }}
        >
          Tickets
        </text>
      </svg>
    </div>
  )
}

/* ── Reopen stat tile (warning tone) ──────────────────────────── */
function ReopenTile({ label, value, sub, icon: Icon }) {
  return (
    <div
      className="relative overflow-hidden rounded-2xl p-5"
      style={{
        background: `linear-gradient(145deg, ${WARN}12, ${WARN}05)`,
        border: `1px solid ${WARN}40`,
        boxShadow: 'var(--shadow-card)',
      }}
    >
      <div
        className="absolute -top-6 -right-6 w-24 h-24 rounded-full opacity-[0.10] pointer-events-none"
        style={{ background: WARN }}
      />
      <div className="relative z-10 flex items-start justify-between mb-3">
        <div
          className="w-11 h-11 rounded-2xl flex items-center justify-center"
          style={{ background: `${WARN}22`, boxShadow: `0 6px 20px ${WARN}33` }}
        >
          <Icon size={20} style={{ color: WARN }} />
        </div>
        <span
          className="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full"
          style={{ background: `${WARN}1f`, color: WARN }}
        >
          Watch
        </span>
      </div>
      <p className="text-2xl font-black relative z-10" style={{ color: 'var(--text-h)', letterSpacing: '-0.03em' }}>
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

  /* Reopened-ticket signals */
  const reopenedCount = a.reopened_count || 0
  const reopenRate = a.reopen_rate || 0
  const recentReopened = a.recent_reopened || []

  /* Donut segments from status breakdown (reuse tenant status colours) */
  const donutTotal = byStatus.reduce((sum, s) => sum + (s.count || 0), 0)
  const donutSegments = byStatus.map(s => ({
    key: s.status,
    label: s.status,
    value: s.count || 0,
    color: STATUS_COLOR[s.status] || '#94a3b8',
  }))

  /* Priority proportion meter (single segmented bar) */
  const priorityTotal = byPriority.reduce((sum, p) => sum + (p.count || 0), 0)

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

      {/* Reopen signals + status donut + recently reopened */}
      <div className="grid gap-4 lg:grid-cols-3">

        {/* Status donut */}
        <div
          className="rounded-2xl p-5"
          style={{
            background: 'var(--bg-card)',
            border: '1px solid var(--border)',
            boxShadow: 'var(--shadow-card)',
          }}
        >
          <div className="flex items-center gap-2 mb-4">
            <div
              className="w-8 h-8 rounded-xl flex items-center justify-center"
              style={{ background: 'rgba(59,130,246,0.12)' }}
            >
              <PieChart size={15} style={{ color: '#3b82f6' }} />
            </div>
            <h2 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Status Distribution</h2>
          </div>

          {donutTotal === 0 ? (
            <p className="text-sm py-10 text-center" style={{ color: 'var(--text-muted)' }}>No tickets yet.</p>
          ) : (
            <div className="flex flex-col items-center gap-4">
              <Donut segments={donutSegments} total={donutTotal} />
              {/* Legend */}
              <div className="w-full space-y-1.5">
                {donutSegments.filter(s => s.value > 0).map(s => (
                  <div key={s.key} className="flex items-center gap-2 text-xs">
                    <span className="w-2.5 h-2.5 rounded-sm flex-shrink-0" style={{ background: s.color }} />
                    <span className="capitalize flex-1 truncate" style={{ color: 'var(--text-h)' }}>
                      {(s.label || 'unknown').replace(/-/g, ' ')}
                    </span>
                    <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{s.value}</span>
                    <span style={{ color: 'var(--text-muted)' }}>
                      {Math.round((s.value / donutTotal) * 100)}%
                    </span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        {/* Reopen tiles + recently reopened (spans 2 cols) */}
        <div className="lg:col-span-2 space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <ReopenTile
              label="Reopened"
              value={reopenedCount}
              sub="tickets bounced back"
              icon={RotateCcw}
            />
            <ReopenTile
              label="Reopen Rate"
              value={`${reopenRate}%`}
              sub="of resolved tickets"
              icon={AlertTriangle}
            />
          </div>

          {/* Recently reopened list */}
          <div
            className="rounded-2xl p-5"
            style={{
              background: 'var(--bg-card)',
              border: '1px solid var(--border)',
              boxShadow: 'var(--shadow-card)',
            }}
          >
            <div className="flex items-center gap-2 mb-4">
              <div
                className="w-8 h-8 rounded-xl flex items-center justify-center"
                style={{ background: `${WARN}1f` }}
              >
                <RotateCcw size={15} style={{ color: WARN }} />
              </div>
              <h2 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Recently Reopened</h2>
            </div>

            {recentReopened.length === 0 ? (
              <p className="text-sm py-6 text-center" style={{ color: 'var(--text-muted)' }}>
                No reopened tickets — nice.
              </p>
            ) : (
              <div className="overflow-x-auto -mx-1 px-1">
                <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
                  <tbody>
                    {recentReopened.map(t => (
                      <tr
                        key={t.id}
                        className="transition-colors"
                        style={{ borderTop: '1px solid var(--border)' }}
                      >
                        <td className="py-2.5 pr-3 align-middle whitespace-nowrap">
                          <Link
                            to={`/app/helpdesk/tickets/${t.id}`}
                            className="inline-flex items-center gap-1 font-semibold hover:underline"
                            style={{ color: 'var(--color-support-500, #06b6d4)' }}
                          >
                            #{t.id}
                            <ArrowUpRight size={12} style={{ opacity: 0.6 }} />
                          </Link>
                        </td>
                        <td className="py-2.5 pr-3 align-middle" style={{ maxWidth: 240 }}>
                          <Link
                            to={`/app/helpdesk/tickets/${t.id}`}
                            className="block truncate hover:underline"
                            style={{ color: 'var(--text-h)' }}
                            title={t.subject}
                          >
                            {t.subject || '(no subject)'}
                          </Link>
                        </td>
                        <td className="py-2.5 pr-3 align-middle whitespace-nowrap">
                          <StatusPill status={t.status} />
                        </td>
                        <td className="py-2.5 pr-3 align-middle whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>
                          {t.assignee_name || 'Unassigned'}
                        </td>
                        <td className="py-2.5 align-middle whitespace-nowrap text-right" style={{ color: 'var(--text-muted)' }}>
                          {relTime(t.reopened_at)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
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

            {/* Segmented proportion meter — share of tickets per priority */}
            {priorityTotal > 0 && (
              <div
                className="flex w-full h-2.5 rounded-full overflow-hidden mb-3"
                style={{ background: 'var(--border)', gap: 2 }}
                role="img"
                aria-label="Priority proportions"
              >
                {byPriority.filter(p => p.count > 0).map(p => (
                  <div
                    key={p.priority}
                    className="h-full first:rounded-l-full last:rounded-r-full"
                    style={{
                      width: `${(p.count / priorityTotal) * 100}%`,
                      background: PRIORITY_COLOR[p.priority] || '#94a3b8',
                    }}
                    title={`${p.priority}: ${p.count} (${Math.round((p.count / priorityTotal) * 100)}%)`}
                  />
                ))}
              </div>
            )}

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
