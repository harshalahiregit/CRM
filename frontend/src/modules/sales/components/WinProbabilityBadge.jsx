/**
 * Win-probability pill. Purely presentational — the value is computed
 * server-side by SalesInsightService from data the CRM already stores
 * (conversion_chance + lead_score + temperature). No model calls.
 *
 * Pass `probability` directly, or `lead` to derive the same blend locally
 * for list/kanban rows (avoids an API call per card).
 */
const BANDS = {
  High:   { bg: 'rgba(16,185,129,0.15)', color: '#10b981' },
  Medium: { bg: 'rgba(245,158,11,0.15)', color: '#f59e0b' },
  Low:    { bg: 'rgba(100,116,139,0.15)', color: '#94a3b8' },
}

// Mirrors SalesInsightService::winProbability — keep the two in sync.
export function deriveWinProbability(lead) {
  if (!lead) return 0
  if (lead.lost || lead.junk) return 0
  const chance = Number(lead.conversion_chance || 0)
  const score  = Number(lead.lead_score || 0)
  let p = Math.round(chance * 0.7 + score * 0.3)
  if (lead.lead_temperature === 'Hot') p += 5
  if (lead.lead_temperature === 'Cold') p -= 5
  return Math.max(0, Math.min(100, p))
}

const bandOf = p => (p >= 70 ? 'High' : p >= 40 ? 'Medium' : 'Low')

export default function WinProbabilityBadge({ probability, lead, showLabel = true }) {
  const p = probability != null ? probability : deriveWinProbability(lead)
  const c = BANDS[bandOf(p)]

  return (
    <span
      className="text-[10px] font-bold px-2 py-0.5 rounded-md whitespace-nowrap"
      style={{ background: c.bg, color: c.color }}
      title={`Win probability: ${p}% (${bandOf(p)})`}
    >
      {showLabel ? `Win ${p}%` : `${p}%`}
    </span>
  )
}
