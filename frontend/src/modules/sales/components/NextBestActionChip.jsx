import { Lightbulb } from 'lucide-react'
import { useApiQuery } from '@/hooks/useApiQuery'
import { insightApi } from '@/services/insightApi'

/**
 * Suggestion chip for a lead. The suggestion is a heuristic computed
 * server-side (SalesInsightService::nextBestAction) from status, ownership,
 * activity recency and reminder state — nothing is generated client-side.
 */
export default function NextBestActionChip({ leadId }) {
  const { data, isLoading, error } = useApiQuery(
    ['next-best-action', leadId],
    () => insightApi.nextBestAction(leadId),
    { enabled: !!leadId },
  )

  if (isLoading || error || !data?.message) return null

  return (
    <div
      className="flex items-start gap-2 px-3 py-2 rounded-xl"
      style={{ background: 'rgba(245,158,11,0.10)', border: '1px solid rgba(245,158,11,0.25)' }}
    >
      <Lightbulb size={13} style={{ color: '#f59e0b', marginTop: 1, flexShrink: 0 }} />
      <div>
        <p className="text-[10px] font-bold uppercase tracking-wide" style={{ color: '#f59e0b' }}>Next best action</p>
        <p className="text-xs mt-0.5" style={{ color: 'var(--text-body)' }}>{data.message}</p>
      </div>
    </div>
  )
}
