/**
 * SLABadge (Phase 7c) — one consistent priority/urgency chip reused across the
 * ticket list, ticket detail and My Work, instead of inline per-component spans.
 * Colours come from the Phase 7a semantic tokens.
 */
const PRIORITY = {
  urgent: { label: 'Urgent', color: 'var(--color-danger-500)',  bg: 'rgba(239,68,68,0.15)' },
  high:   { label: 'High',   color: 'var(--color-danger-500)',  bg: 'rgba(239,68,68,0.10)' },
  medium: { label: 'Medium', color: 'var(--color-warning-500)', bg: 'rgba(245,158,11,0.12)' },
  low:    { label: 'Low',    color: 'var(--color-success-500)', bg: 'rgba(16,185,129,0.12)' },
}

export default function SLABadge({ priority = 'low', dueDate = null, className = '' }) {
  const p = PRIORITY[priority] || { label: priority, color: 'var(--text-muted)', bg: 'var(--border)' }
  const overdue = dueDate && new Date(dueDate) < new Date()

  return (
    <span className={`inline-flex items-center gap-1 text-[10px] font-bold capitalize px-2 py-0.5 rounded-lg ${className}`}
      style={{ background: p.bg, color: p.color }}>
      {p.label}
      {overdue && <span style={{ color: 'var(--color-danger-500)' }}>· overdue</span>}
    </span>
  )
}
