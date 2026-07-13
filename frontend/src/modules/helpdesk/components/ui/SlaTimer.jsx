import { Clock, AlertTriangle, CheckCircle2, PauseCircle } from 'lucide-react'

/**
 * SLA timer — renders the backend-computed `ticket.sla` snapshot.
 *   compact : one chip showing the most-urgent of response/resolution (grid).
 *   full    : both timers labelled (ticket workspace).
 * States: ok | at_risk | breached | met | paused.
 */

const ST = {
  ok:       { c: 'var(--color-success-500)', label: 'On track', icon: Clock },
  at_risk:  { c: 'var(--color-warning-500)', label: 'Due soon', icon: AlertTriangle },
  breached: { c: 'var(--color-danger-500)',  label: 'Breached', icon: AlertTriangle },
  met:      { c: 'var(--color-success-500)', label: 'Met',      icon: CheckCircle2 },
  paused:   { c: 'var(--text-muted)',        label: 'Paused',   icon: PauseCircle },
}
const RANK = { breached: 0, at_risk: 1, paused: 2, ok: 3, met: 4 }

function rel(due) {
  const ms = new Date(due).getTime() - Date.now()
  const abs = Math.abs(ms)
  const h = Math.floor(abs / 3600000)
  const m = Math.floor((abs % 3600000) / 60000)
  const d = Math.floor(h / 24)
  const label = d > 0 ? `${d}d ${h % 24}h` : h > 0 ? `${h}h ${m}m` : `${m}m`
  return ms >= 0 ? `in ${label}` : `${label} overdue`
}

function Chip({ state, due, prefix }) {
  const cfg = ST[state] || ST.ok
  const Icon = cfg.icon
  return (
    <span className="inline-flex items-center gap-1 text-[11px] font-bold px-2 py-0.5 rounded-lg"
      style={{ background: `color-mix(in srgb, ${cfg.c} 14%, transparent)`, color: cfg.c }}
      title={`${prefix ? prefix + ' — ' : ''}${cfg.label}${state !== 'met' && state !== 'paused' && due ? ` (${rel(due)})` : ''}`}>
      <Icon size={11} />
      {prefix ? `${prefix}: ` : ''}{cfg.label}
      {state !== 'met' && state !== 'paused' && due && <span style={{ opacity: 0.75, fontWeight: 600 }}>· {rel(due)}</span>}
    </span>
  )
}

export default function SlaTimer({ sla, compact = false }) {
  if (!sla?.tracked) return compact ? <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>—</span> : null
  const timers = [
    sla.response && { key: 'response', prefix: 'First response', ...sla.response },
    sla.resolution && { key: 'resolution', prefix: 'Resolution', ...sla.resolution },
  ].filter(Boolean)
  if (timers.length === 0) return compact ? <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>—</span> : null

  if (compact) {
    // Show the single most-urgent timer.
    const worst = [...timers].sort((a, b) => (RANK[a.state] ?? 9) - (RANK[b.state] ?? 9))[0]
    return <Chip state={worst.state} due={worst.due} />
  }

  return (
    <div className="flex flex-col gap-1.5">
      {timers.map(t => <Chip key={t.key} state={t.state} due={t.due} prefix={t.prefix} />)}
    </div>
  )
}
