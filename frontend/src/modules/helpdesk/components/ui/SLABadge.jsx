/**
 * SLABadge — one consistent priority + SLA-urgency chip reused across the
 * ticket grid, ticket workspace and My Work.
 *
 * Priority colour comes from the semantic tokens. When a due date is present it
 * also renders the SDS's four SLA states, derived from time-to-due:
 *   Healthy  → comfortably before due
 *   Warning  → due within 24h
 *   Critical → due within 2h
 *   Breached → past due
 */
const PRIORITY = {
  urgent: { label: 'Urgent', color: 'var(--color-danger-500)' },
  high:   { label: 'High',   color: 'var(--color-danger-500)' },
  medium: { label: 'Medium', color: 'var(--color-warning-500)' },
  low:    { label: 'Low',    color: 'var(--color-success-500)' },
}

const SLA = {
  healthy:  { label: 'On track', color: 'var(--color-success-500)' },
  warning:  { label: 'Due soon', color: 'var(--color-warning-500)' },
  critical: { label: 'Critical', color: '#f97316' },
  breached: { label: 'Breached', color: 'var(--color-danger-500)' },
}

function slaState(dueDate) {
  if (!dueDate) return null
  const ms = new Date(dueDate).getTime() - Date.now()
  if (ms < 0) return 'breached'
  if (ms < 2 * 3600e3) return 'critical'
  if (ms < 24 * 3600e3) return 'warning'
  return 'healthy'
}

export default function SLABadge({ priority = 'low', dueDate = null, showSla = true, className = '' }) {
  const p = PRIORITY[priority] || { label: priority, color: 'var(--text-muted)' }
  const state = showSla ? slaState(dueDate) : null
  const s = state && SLA[state]

  return (
    <span className={`inline-flex items-center gap-1.5 ${className}`}>
      <span className="inline-flex items-center gap-1 text-[10px] font-bold capitalize px-2 py-0.5 rounded-lg"
        style={{ background: `color-mix(in srgb, ${p.color} 14%, transparent)`, color: p.color }}>
        {p.label}
      </span>
      {s && (
        <span className="inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded-lg"
          title={`SLA: ${s.label}`}
          style={{ background: `color-mix(in srgb, ${s.color} 14%, transparent)`, color: s.color }}>
          <span className="w-1.5 h-1.5 rounded-full" style={{ background: s.color }} />
          {s.label}
        </span>
      )}
    </span>
  )
}
