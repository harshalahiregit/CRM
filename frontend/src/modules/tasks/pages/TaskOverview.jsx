import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { ArrowLeft, Activity, CheckCircle2, AlarmClock, CalendarDays, Download } from 'lucide-react'
import { taskApi, TASK_STATUS, TASK_PRIORITY, TASK_ACCENT, fmtDuration } from '@/services/taskApi'
import { exportCsv, stampedName } from '@/lib/exportCsv'

/**
 * Tasks Overview — the detailed analytics page behind the list's KPI row.
 *
 * Charts are CSS bars, matching how HelpdeskAnalytics already does it: no chart
 * library is installed, and adding one for four bar charts isn't worth the
 * dependency or the theming fight.
 */

export default function TaskOverview() {
  const navigate = useNavigate()
  const { data: stats } = useQuery({ queryKey: ['task-stats'], queryFn: taskApi.stats })
  const { data: tasks = [], isLoading } = useQuery({ queryKey: ['tasks', {}], queryFn: () => taskApi.list() })
  const { data: staff = [] } = useQuery({ queryKey: ['task-staff'], queryFn: taskApi.staff })

  const byStatus = useMemo(() => Object.entries(TASK_STATUS).map(([k, m]) => ({
    key: k, label: m.label, color: m.color, count: tasks.filter(t => t.status === k).length,
  })), [tasks])

  const byPriority = useMemo(() => Object.entries(TASK_PRIORITY).map(([k, c]) => ({
    key: k, label: k[0].toUpperCase() + k.slice(1), color: c, count: tasks.filter(t => t.priority === k).length,
  })), [tasks])

  const byAssignee = useMemo(() => {
    const map = new Map()
    for (const t of tasks) {
      for (const a of t.assignees || []) {
        const name = a.user?.name
        if (!name) continue
        const cur = map.get(name) || { name, total: 0, done: 0 }
        cur.total++
        if (t.status === 'complete') cur.done++
        map.set(name, cur)
      }
    }
    return [...map.values()].sort((a, b) => b.total - a.total).slice(0, 10)
  }, [tasks])

  const byLink = useMemo(() => {
    const map = new Map()
    for (const t of tasks) {
      const k = t.rel_type || 'standalone'
      map.set(k, (map.get(k) || 0) + 1)
    }
    return [...map.entries()].map(([k, count]) => ({ key: k, count })).sort((a, b) => b.count - a.count)
  }, [tasks])

  const closeRate = tasks.length ? Math.round((byStatus.find(s => s.key === 'complete')?.count / tasks.length) * 100) : 0
  const unassigned = tasks.filter(t => !(t.assignees || []).length).length

  const doExport = () => exportCsv(stampedName('tasks-overview'), byAssignee, [
    { key: 'name', label: 'Assignee' },
    { key: 'total', label: 'Total Tasks' },
    { key: 'done', label: 'Completed' },
    { key: 'rate', label: 'Completion %', value: r => r.total ? Math.round((r.done / r.total) * 100) : 0 },
  ])

  if (isLoading) return <div className="rounded-2xl animate-pulse" style={{ height: 300, background: 'var(--bg-card)' }} />

  return (
    <div className="max-w-5xl">
      <button onClick={() => navigate('/app/tasks')} className="flex items-center gap-1.5 text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={13} /> Tasks
      </button>

      <div className="flex items-center gap-2 mb-4">
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Tasks Overview</h1>
        <button onClick={doExport} disabled={!byAssignee.length}
          className="ml-auto flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl disabled:opacity-40"
          style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
          <Download size={13} /> Export
        </button>
      </div>

      <div className="grid gap-2 mb-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))' }}>
        <Kpi icon={Activity} label="Active" value={stats?.active} color="var(--color-info-500)" />
        <Kpi icon={CheckCircle2} label="Completed" value={stats?.completed} color="var(--color-success-500)" />
        <Kpi icon={AlarmClock} label="Overdue" value={stats?.overdue} color="var(--color-danger-500)" />
        <Kpi icon={CalendarDays} label="Due Today" value={stats?.today} color="var(--color-warning-500)" />
        <Kpi icon={CheckCircle2} label="Close Rate" value={`${closeRate}%`} color={TASK_ACCENT} />
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <Card title="By status">
          {byStatus.map(s => <Bar key={s.key} label={s.label} value={s.count} max={tasks.length} color={s.color} />)}
        </Card>

        <Card title="By priority">
          {byPriority.map(p => <Bar key={p.key} label={p.label} value={p.count} max={tasks.length} color={p.color} />)}
        </Card>

        <Card title="By assignee" subtitle={unassigned ? `${unassigned} unassigned` : null}>
          {byAssignee.length === 0 && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Nobody is assigned any tasks.</p>}
          {byAssignee.map(a => (
            <div key={a.name} className="mb-2.5">
              <div className="flex items-center gap-2 mb-1">
                <span className="text-[11px] font-semibold truncate" style={{ color: 'var(--text-h)' }}>{a.name}</span>
                <span className="ml-auto text-[10px] tabular-nums" style={{ color: 'var(--text-muted)' }}>{a.done}/{a.total}</span>
              </div>
              {/* Nested fill: total in muted, completed portion in green. */}
              <div className="h-1.5 rounded-full overflow-hidden relative" style={{ background: 'var(--bg-input)' }}>
                <div className="h-full rounded-full absolute inset-y-0 left-0" style={{ width: pct(a.total, byAssignee[0].total), background: `color-mix(in srgb, ${TASK_ACCENT} 45%, transparent)` }} />
                <div className="h-full rounded-full absolute inset-y-0 left-0" style={{ width: pct(a.done, byAssignee[0].total), background: 'var(--color-success-500)' }} />
              </div>
            </div>
          ))}
        </Card>

        <Card title="By linked type">
          {byLink.map(l => (
            <Bar key={l.key} label={l.key.replace(/_/g, ' ')} value={l.count} max={tasks.length} color={TASK_ACCENT} />
          ))}
        </Card>
      </div>
    </div>
  )
}

const pct = (v, max) => `${max > 0 ? Math.round((v / max) * 100) : 0}%`

function Kpi({ icon: Icon, label, value, color }) {
  return (
    <div className="rounded-2xl p-3" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div className="flex items-center gap-1.5 mb-1">
        <Icon size={11} style={{ color }} />
        <span className="text-[10px] font-bold uppercase tracking-wide truncate" style={{ color: 'var(--text-muted)' }}>{label}</span>
      </div>
      <p className="text-xl font-black" style={{ color: 'var(--text-h)' }}>{value ?? 0}</p>
    </div>
  )
}

function Card({ title, subtitle, children }) {
  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <h2 className="font-bold text-xs mb-3 flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
        {title}
        {subtitle && <span className="font-normal" style={{ color: 'var(--text-muted)' }}>· {subtitle}</span>}
      </h2>
      {children}
    </section>
  )
}

function Bar({ label, value, max, color }) {
  return (
    <div className="mb-2.5">
      <div className="flex items-center gap-2 mb-1">
        <span className="text-[11px] font-semibold capitalize" style={{ color: 'var(--text-h)' }}>{label}</span>
        <span className="ml-auto text-[10px] tabular-nums" style={{ color: 'var(--text-muted)' }}>{value}</span>
      </div>
      <div className="h-1.5 rounded-full overflow-hidden" style={{ background: 'var(--bg-input)' }}>
        <div className="h-full rounded-full transition-all" style={{ width: pct(value, max), background: color }} />
      </div>
    </div>
  )
}
