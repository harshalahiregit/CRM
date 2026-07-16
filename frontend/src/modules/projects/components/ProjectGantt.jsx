import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { GanttChartSquare, Flag } from 'lucide-react'
import { taskApi, TASK_STATUS } from '@/services/taskApi'
import { PROJECT_ACCENT } from '@/services/projectApi'
import Select from '@/components/ui/Select'

/**
 * Hand-rolled Gantt — no chart library (none is installed, and this is a bar
 * timeline, not a plotting problem). Each milestone is a lane; its tasks render
 * as bars positioned by start→due against a shared date axis.
 *
 * Tasks with no due date can't be placed on a timeline, so they're surfaced in a
 * separate "unscheduled" note rather than silently dropped.
 */

const DAY = 86400000
const scales = [
  { value: 'week', label: 'Weeks', unit: 7 },
  { value: 'day', label: 'Days', unit: 1 },
  { value: 'month', label: 'Months', unit: 30 },
]

export default function ProjectGantt({ projectId, milestones = [] }) {
  const [scale, setScale] = useState('week')
  const { data: tasks = [], isLoading } = useQuery({
    queryKey: ['tasks', { rel_type: 'project', rel_id: projectId }],
    queryFn: () => taskApi.list({ rel_type: 'project', rel_id: projectId }),
  })

  const scheduled = useMemo(() => tasks.filter(t => t.start_date && t.due_date), [tasks])
  const unscheduled = tasks.length - scheduled.length

  const range = useMemo(() => {
    const dates = []
    for (const t of scheduled) { dates.push(+new Date(t.start_date), +new Date(t.due_date)) }
    for (const m of milestones) { if (m.due_date) dates.push(+new Date(m.due_date)) }
    if (!dates.length) return null
    const min = Math.min(...dates), max = Math.max(...dates)
    // Pad a little so end-bars aren't flush against the edge.
    return { min: min - DAY * 2, max: Math.max(max + DAY * 2, min + DAY * 7) }
  }, [scheduled, milestones])

  if (isLoading) return <div className="rounded-2xl animate-pulse" style={{ height: 200, background: 'var(--bg-card)' }} />

  if (!range) {
    return (
      <section className="rounded-2xl p-8 text-center" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <GanttChartSquare size={22} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          Nothing to chart yet — tasks need a start and due date to appear on the timeline.
        </p>
      </section>
    )
  }

  const span = range.max - range.min
  const pos = (d) => `${((+new Date(d) - range.min) / span) * 100}%`
  const width = (a, b) => `${Math.max(1.5, ((+new Date(b) - +new Date(a)) / span) * 100)}%`

  // Axis ticks at the chosen granularity.
  const ticks = []
  const step = scales.find(s => s.value === scale).unit * DAY
  for (let t = range.min; t <= range.max; t += step) ticks.push(t)

  // Group scheduled tasks by milestone; null milestone = "Unassigned" lane.
  const byMilestone = new Map([[null, []]])
  for (const m of milestones) byMilestone.set(m.id, [])
  for (const t of scheduled) {
    const k = t.milestone_id && byMilestone.has(t.milestone_id) ? t.milestone_id : null
    byMilestone.get(k).push(t)
  }
  const lanes = [...milestones.map(m => ({ milestone: m, tasks: byMilestone.get(m.id) || [] })),
    { milestone: null, tasks: byMilestone.get(null) }]
    .filter(l => l.tasks.length || l.milestone)

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div className="flex items-center gap-2 mb-4">
        <h2 className="font-bold text-xs flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
          <GanttChartSquare size={14} style={{ color: PROJECT_ACCENT }} /> Timeline
        </h2>
        <div className="ml-auto" style={{ width: 110 }}>
          <Select size="sm" value={scale} onChange={setScale} options={scales.map(s => ({ value: s.value, label: s.label }))} />
        </div>
      </div>

      <div className="overflow-x-auto">
        <div style={{ minWidth: 640 }}>
          {/* Axis */}
          <div className="relative h-5 mb-2" style={{ borderBottom: '1px solid var(--border)' }}>
            {ticks.map((t, i) => (
              <span key={i} className="absolute text-[9px] whitespace-nowrap" style={{ left: pos(t), color: 'var(--text-muted)', transform: 'translateX(-2px)' }}>
                {new Date(t).toLocaleDateString('en-IN', scale === 'month' ? { month: 'short' } : { day: '2-digit', month: 'short' })}
              </span>
            ))}
          </div>

          {/* Lanes */}
          <div className="space-y-3">
            {lanes.map((lane, li) => (
              <div key={lane.milestone?.id ?? `unassigned-${li}`}>
                <div className="flex items-center gap-1.5 mb-1">
                  <Flag size={10} style={{ color: lane.milestone?.color || 'var(--text-muted)' }} />
                  <span className="text-[11px] font-bold" style={{ color: 'var(--text-h)' }}>
                    {lane.milestone?.name || 'Unassigned'}
                  </span>
                  {lane.milestone?.due_date && (
                    <span className="text-[9px]" style={{ color: 'var(--text-muted)' }}>
                      due {new Date(lane.milestone.due_date).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' })}
                    </span>
                  )}
                </div>

                {lane.tasks.length === 0 && (
                  <p className="text-[10px] pl-4 pb-1" style={{ color: 'var(--text-muted)', opacity: 0.6 }}>No scheduled tasks.</p>
                )}

                {lane.tasks.map(t => {
                  const color = TASK_STATUS[t.status]?.color || 'var(--text-muted)'
                  return (
                    <div key={t.id} className="relative h-6 mb-1">
                      <div className="absolute inset-y-0 rounded-md flex items-center px-1.5 overflow-hidden"
                        style={{ left: pos(t.start_date), width: width(t.start_date, t.due_date), background: `color-mix(in srgb, ${color} 30%, var(--bg-input))`, border: `1px solid ${color}` }}
                        title={`${t.name} · ${new Date(t.start_date).toLocaleDateString('en-IN')} → ${new Date(t.due_date).toLocaleDateString('en-IN')}`}>
                        <span className="text-[9px] font-semibold truncate" style={{ color: 'var(--text-h)' }}>{t.name}</span>
                      </div>
                    </div>
                  )
                })}
              </div>
            ))}
          </div>
        </div>
      </div>

      {unscheduled > 0 && (
        <p className="text-[10px] mt-3 pt-3" style={{ color: 'var(--text-muted)', borderTop: '1px solid var(--border)' }}>
          {unscheduled} {unscheduled === 1 ? 'task has' : 'tasks have'} no start/due date and aren’t shown on the timeline.
        </p>
      )}
    </section>
  )
}
