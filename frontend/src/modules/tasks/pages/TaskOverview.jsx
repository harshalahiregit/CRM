import { useMemo, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { ArrowLeft, Activity, CheckCircle2, AlarmClock, CalendarDays, Download, Bell } from 'lucide-react'
import { taskApi, TASK_STATUS, TASK_PRIORITY, TASK_ACCENT, fmtDuration } from '@/services/taskApi'
import { exportCsv, stampedName } from '@/lib/exportCsv'
import { useAuth } from '@/context/AuthContext'

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

      <NotificationSettings />
    </div>
  )
}

const pct = (v, max) => `${max > 0 ? Math.round((v / max) * 100) : 0}%`

/**
 * Which task events reach people, and by which channel.
 *
 * Tasks never sent email before subtasks arrived, so switching four new
 * categories on for every workspace with no way to turn them off would be a
 * change nobody asked for landing in everyone's inbox. Readable by anyone,
 * writable by an admin — the server enforces that either way.
 */
function NotificationSettings() {
  const qc = useQueryClient()
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [draft, setDraft] = useState(null)
  const [saved, setSaved] = useState(false)
  const [err, setErr] = useState('')

  const { data: cfg, isLoading } = useQuery({ queryKey: ['task-settings'], queryFn: taskApi.settings })

  // The draft only exists once you've edited something — until then the saved
  // config IS the truth, so a background refetch can't clobber typing.
  const value = (k) => (draft && k in draft ? draft[k] : cfg?.[k])
  const set = (k, v) => { setDraft(d => ({ ...(d || {}), [k]: v })); setSaved(false) }

  const save = useMutation({
    mutationFn: () => taskApi.saveSettings(draft || {}),
    onSuccess: (fresh) => { qc.setQueryData(['task-settings'], fresh); setDraft(null); setSaved(true); setErr('') },
    onError: (e) => setErr(e?.message || 'Could not save those settings.'),
  })

  if (isLoading || !cfg) return null

  const FIELDS = [
    { key: 'notify_assigned',  label: 'Assigned to me', hint: 'A task or subtask lands on your plate' },
    { key: 'notify_activity',  label: 'Status changed, commented, mentioned', hint: 'The running commentary on work you follow' },
    { key: 'notify_completed', label: 'Subtask completed', hint: 'Sent up the tree, with the parent’s new percentage' },
    { key: 'notify_due',       label: 'Due soon / overdue', hint: 'Each subtask has its own deadline, so each gets its own notice' },
    { key: 'email_enabled',    label: 'Send these by email too', hint: 'Off keeps every in-app bell working while nothing leaves the building' },
  ]

  return (
    <section className="rounded-2xl p-4 mt-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div className="flex items-center gap-2 mb-1">
        <Bell size={13} style={{ color: TASK_ACCENT }} />
        <h2 className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>Notifications</h2>
      </div>
      <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
        Who hears about task activity, and how. Alerts reach a task’s assignees and followers plus the
        same people on the task it sits under. Turning a category off silences both the bell and the email.
        {!isAdmin && ' Only an admin can change these.'}
      </p>

      <div className="space-y-3">
        {FIELDS.map(f => (
          <div key={f.key} className="flex flex-wrap items-center gap-3">
            <div className="flex-1" style={{ minWidth: 220 }}>
              <label className="block text-xs font-bold" style={{ color: 'var(--text-h)' }}>{f.label}</label>
              <p className="text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{f.hint}</p>
            </div>
            <label className="flex items-center gap-2 text-xs" style={{ width: 110, color: 'var(--text-body)', cursor: isAdmin ? 'pointer' : 'default' }}>
              <input type="checkbox" disabled={!isAdmin} checked={Boolean(value(f.key))}
                onChange={e => set(f.key, e.target.checked)}
                style={{ accentColor: TASK_ACCENT, width: 15, height: 15 }} />
              {value(f.key) ? 'Enabled' : 'Disabled'}
            </label>
          </div>
        ))}

        <div className="flex flex-wrap items-center gap-3">
          <div className="flex-1" style={{ minWidth: 220 }}>
            <label className="block text-xs font-bold" style={{ color: 'var(--text-h)' }}>Also copy this address</label>
            <p className="text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>Optional — a shared inbox that should see every notice</p>
          </div>
          <input type="text" disabled={!isAdmin} value={value('alert_email_extra') ?? ''} placeholder="pm@company.com"
            onChange={e => set('alert_email_extra', e.target.value === '' ? null : e.target.value)}
            className="rounded-lg outline-none" style={{ width: 200, padding: '7px 10px', fontSize: 12.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
        </div>
      </div>

      {err && <p className="text-[11px] mt-3" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      {isAdmin && (
        <div className="flex items-center gap-2 mt-4 pt-3" style={{ borderTop: '1px solid var(--border)' }}>
          <button onClick={() => save.mutate()} disabled={!draft || save.isPending}
            className="text-xs font-bold px-4 py-2 rounded-xl disabled:opacity-40"
            style={{ background: TASK_ACCENT, color: '#fff' }}>
            {save.isPending ? 'Saving…' : 'Save'}
          </button>
          {draft && <button onClick={() => { setDraft(null); setErr('') }} className="text-xs font-bold" style={{ color: 'var(--text-muted)' }}>Discard</button>}
          {saved && <span className="text-[11px] font-bold" style={{ color: TASK_ACCENT }}>Saved</span>}
        </div>
      )}
    </section>
  )
}

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
