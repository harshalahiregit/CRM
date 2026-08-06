// Polls panel for one context (task / ticket / project). Fetches the polls,
// renders each PollCard, and wires vote / close / delete. The create modal is
// lifted to the parent surface (so the composer's Poll button and this panel's
// "New poll" button share it) and passed in via `onNew`.
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { BarChart3, Plus } from 'lucide-react'
import { pollApi } from '@/services/pollApi'
import PollCard from './PollCard'

export default function PollList({ contextType, contextId, accent = 'var(--color-primary-500)', onNew, title = 'Polls' }) {
  const qc = useQueryClient()
  const key = ['polls', contextType, contextId]

  const { data: polls = [], isLoading } = useQuery({
    queryKey: key,
    queryFn: () => pollApi.list(contextType, contextId),
    enabled: !!contextId,
  })

  const bust = () => qc.invalidateQueries({ queryKey: key })
  const voteM = useMutation({ mutationFn: ({ id, optionIds }) => pollApi.vote(id, optionIds), onSuccess: bust })
  const closeM = useMutation({ mutationFn: (id) => pollApi.close(id), onSuccess: bust })
  const delM = useMutation({ mutationFn: (id) => pollApi.remove(id), onSuccess: bust })
  const busy = voteM.isPending || closeM.isPending || delM.isPending

  // Nothing to show and no way to add? Render nothing so quiet surfaces stay clean.
  if (!isLoading && polls.length === 0 && !onNew) return null

  return (
    <div>
      <div className="flex items-center gap-2 mb-2">
        <BarChart3 size={14} style={{ color: accent }} />
        <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>{title}</span>
        {polls.length > 0 && <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{polls.length}</span>}
        {onNew && (
          <button onClick={onNew} className="ml-auto flex items-center gap-1 text-[11px] font-bold" style={{ color: accent }}>
            <Plus size={12} /> New poll
          </button>
        )}
      </div>

      {isLoading ? (
        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Loading polls…</p>
      ) : polls.length === 0 ? (
        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>No polls yet.</p>
      ) : (
        <div className="space-y-2">
          {polls.map(p => (
            <PollCard key={p.id} poll={p} accent={accent} busy={busy}
              onVote={(optionIds) => voteM.mutate({ id: p.id, optionIds })}
              onClose={() => closeM.mutate(p.id)}
              onDelete={() => delM.mutate(p.id)} />
          ))}
        </div>
      )}
    </div>
  )
}
