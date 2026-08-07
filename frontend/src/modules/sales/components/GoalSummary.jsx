import { useNavigate } from 'react-router-dom'
import { Target, Users, ArrowRight } from 'lucide-react'
import { useLeadGoals } from '@/hooks/useLeadGoals'
import { useMoneyFmt } from '@/components/ui/Money'

/**
 * Active lead-goal progress, as a compact band.
 *
 * Lives here rather than inline on a page because it is shown in more than one
 * place (the Leads list and the Sales dashboard) and those copies would
 * otherwise drift — the dashboard already had its own version that showed only
 * the count target and silently ignored value-based goals.
 *
 * Renders nothing at all when there are no active goals: an empty "Goals" card
 * on every page would be noise for a workspace that doesn't use them.
 *
 * A goal can carry a count target, a value target, or both, so each is shown
 * only when set — LeadGoals treats them independently and this must agree.
 */
const TYPE_LABEL = { monthly: 'Monthly', quarterly: 'Quarterly', yearly: 'Yearly' }

const pctOf = (achieved, target) =>
  target ? Math.min(100, Math.round(((achieved || 0) / target) * 100)) : null

export default function GoalSummary({ limit = 3, compact = false }) {
  const navigate = useNavigate()
  const money = useMoneyFmt()
  const { data: goals = [], isLoading } = useLeadGoals({ active_only: true })

  if (isLoading || !goals.length) return null

  const shown = goals.slice(0, limit)
  const hidden = goals.length - shown.length

  return (
    <div className="card-3d" style={{ padding: compact ? '16px' : '20px' }}>
      <div className="flex items-center justify-between mb-3">
        <div className="flex items-center gap-2">
          <Target size={15} style={{ color: 'var(--accent)' }} />
          <h2 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Goal progress</h2>
          <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
            {goals.length} active
          </span>
        </div>
        <button onClick={() => navigate('/app/sales/lead-goals')}
          className="text-xs font-semibold flex items-center gap-1" style={{ color: 'var(--accent)' }}>
          Manage <ArrowRight size={11} />
        </button>
      </div>

      <div className={compact ? 'space-y-3' : 'grid gap-4 sm:grid-cols-2 lg:grid-cols-3'}>
        {shown.map(goal => {
          const countPct = pctOf(goal.achieved_count, goal.target_count)
          const valuePct = pctOf(goal.achieved_value, goal.target_value)

          return (
            <div key={goal.id} className={compact ? '' : 'p-3 rounded-xl'}
              style={compact ? undefined : { background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
              <div className="flex items-center gap-2 mb-2 flex-wrap">
                <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg"
                  style={{ background: 'rgba(124,58,237,0.1)', color: 'var(--accent)' }}>
                  {TYPE_LABEL[goal.type] || goal.type}
                </span>
                <span className="inline-flex items-center gap-1 text-[11px] truncate" style={{ color: 'var(--text-muted)' }}>
                  <Users size={10} /> {goal.user?.name || 'Team-wide'}
                </span>
              </div>

              {countPct !== null && (
                <Bar
                  label="Leads"
                  detail={`${goal.achieved_count || 0} / ${goal.target_count}`}
                  pct={countPct}
                />
              )}
              {valuePct !== null && (
                <Bar
                  label="Value"
                  detail={`${money(goal.achieved_value)} / ${money(goal.target_value)}`}
                  pct={valuePct}
                  className={countPct !== null ? 'mt-2' : ''}
                />
              )}
              {countPct === null && valuePct === null && (
                <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>No target set on this goal.</p>
              )}
            </div>
          )
        })}
      </div>

      {hidden > 0 && (
        <button onClick={() => navigate('/app/sales/lead-goals')}
          className="text-[11px] mt-3 font-semibold" style={{ color: 'var(--accent)' }}>
          +{hidden} more goal{hidden === 1 ? '' : 's'}
        </button>
      )}
    </div>
  )
}

/**
 * One progress line. The percentage is written out as text next to the bar, so
 * progress is never conveyed by bar length alone.
 */
function Bar({ label, detail, pct, className = '' }) {
  // Hitting the target should read differently from being on the way there.
  const done = pct >= 100
  return (
    <div className={className}>
      <div className="flex justify-between items-baseline text-[11px] mb-1 gap-2">
        <span className="truncate" style={{ color: 'var(--text-muted)' }}>{label} · {detail}</span>
        <span className="font-bold flex-shrink-0"
          style={{ color: done ? 'var(--color-success-500)' : 'var(--text-h)' }}>{pct}%</span>
      </div>
      <div className="h-1.5 rounded-full overflow-hidden" style={{ background: 'var(--border)' }}>
        <div className="h-full rounded-full" style={{
          width: `${pct}%`,
          background: done
            ? 'linear-gradient(90deg,#34d399,var(--color-success-500))'
            : 'linear-gradient(90deg,#a78bfa,#7C3AED)',
        }} />
      </div>
    </div>
  )
}
