import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Activity, CheckCircle2, AlarmClock, CalendarDays, UserCheck, ArrowRight } from 'lucide-react'
import { taskApi, TASK_ACCENT } from '@/services/taskApi'

/**
 * Live KPI row for the tasks list. Counts come from one aggregate endpoint
 * rather than the loaded page, so they reflect ALL tasks and stay correct while
 * filters narrow the table below.
 *
 * Each card is a filter shortcut — a count you can't act on is just decoration.
 */

const CARDS = [
  { key: 'active',    label: 'Active',    icon: Activity,     color: 'var(--color-info-500)' },
  { key: 'completed', label: 'Completed', icon: CheckCircle2, color: 'var(--color-success-500)' },
  { key: 'overdue',   label: 'Overdue',   icon: AlarmClock,   color: 'var(--color-danger-500)' },
  { key: 'today',     label: 'Due Today', icon: CalendarDays, color: 'var(--color-warning-500)' },
  { key: 'mine',      label: 'My Tasks',  icon: UserCheck,    color: 'var(--color-primary-500)' },
]

export default function TaskKpiCards({ activeCard, onPick, currentUserId }) {
  const navigate = useNavigate()
  const { data: stats, isLoading } = useQuery({
    queryKey: ['task-stats'],
    queryFn: taskApi.stats,
    refetchInterval: 60000,
  })

  return (
    <div className="mb-4">
      <div className="flex items-center gap-2 mb-2">
        <h2 className="text-[10px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Overview</h2>
        <button onClick={() => navigate('/app/tasks/overview')}
          className="ml-auto flex items-center gap-1 text-[10px] font-bold hover:opacity-70"
          style={{ color: TASK_ACCENT }}>
          Tasks Overview <ArrowRight size={10} />
        </button>
      </div>

      <div className="grid gap-2" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(120px, 1fr))' }}>
        {CARDS.map(c => {
          const Icon = c.icon
          const active = activeCard === c.key
          const value = stats?.[c.key]
          return (
            <button key={c.key}
              onClick={() => onPick(active ? null : c.key)}
              disabled={c.key === 'mine' && !currentUserId}
              className="rounded-2xl p-3 text-left transition-all disabled:opacity-50"
              style={{
                background: active ? `color-mix(in srgb, ${c.color} 10%, var(--bg-card))` : 'var(--bg-card)',
                border: `1px solid ${active ? c.color : 'var(--border)'}`,
              }}>
              <div className="flex items-center gap-1.5 mb-1">
                <Icon size={11} style={{ color: c.color }} />
                <span className="text-[10px] font-bold uppercase tracking-wide truncate" style={{ color: 'var(--text-muted)' }}>{c.label}</span>
              </div>
              {isLoading
                ? <div className="h-6 w-8 rounded animate-pulse" style={{ background: 'var(--bg-input)' }} />
                : <p className="text-xl font-black" style={{ color: active ? c.color : 'var(--text-h)' }}>{value ?? 0}</p>}
            </button>
          )
        })}
      </div>
    </div>
  )
}
