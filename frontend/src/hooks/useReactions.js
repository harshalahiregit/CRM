// Batch reactions for a whole thread in ONE request, plus an optimistic toggle.
// A thread passes the ids of its messages; each message row then reads its own
// summary from the returned map and calls toggle(id, emoji).
import { useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { reactionApi } from '@/services/reactionApi'

export function useReactions(subjectType, ids = []) {
  const qc = useQueryClient()
  // Stable key regardless of id order, so re-renders don't refetch needlessly.
  const idKey = useMemo(() => [...ids].map(Number).sort((a, b) => a - b).join(','), [ids])

  const { data: map = {} } = useQuery({
    queryKey: ['reactions', subjectType, idKey],
    queryFn: () => reactionApi.list(subjectType, idKey ? idKey.split(',').map(Number) : []),
    enabled: !!idKey,
  })

  const toggleM = useMutation({
    mutationFn: ({ id, emoji }) => reactionApi.toggle(subjectType, id, emoji),
    onSuccess: (summary, { id }) => {
      // Splice this one message's fresh summary into the cached thread map.
      qc.setQueryData(['reactions', subjectType, idKey], (prev = {}) => ({ ...prev, [id]: summary }))
    },
  })

  return {
    summaryFor: (id) => map[id] || [],
    toggle: (id, emoji) => toggleM.mutate({ id, emoji }),
  }
}

export default useReactions
