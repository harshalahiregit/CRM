import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { hrApi } from '@/services/hrApi'

export function useCandidates(params = {}) {
  return useQuery({
    queryKey: ['candidates', params],
    queryFn: () => hrApi.candidates.list(params),
  })
}

export function useCandidate(id) {
  return useQuery({
    queryKey: ['candidates', id],
    queryFn: () => hrApi.candidates.get(id),
    enabled: !!id,
  })
}

function useInvalidateCandidates() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: ['candidates'] })
}

export function useCreateCandidate() {
  const invalidate = useInvalidateCandidates()
  return useMutation({
    mutationFn: (data) => hrApi.candidates.create(data),
    onSuccess: invalidate,
  })
}

export function useUpdateCandidate() {
  const invalidate = useInvalidateCandidates()
  return useMutation({
    mutationFn: ({ id, data }) => hrApi.candidates.update(id, data),
    onSuccess: invalidate,
  })
}

export function useUpdateCandidateStage() {
  const invalidate = useInvalidateCandidates()
  return useMutation({
    mutationFn: ({ id, stage }) => hrApi.candidates.updateStage(id, stage),
    onSuccess: invalidate,
  })
}

export function useUpdateCandidateDecision() {
  const invalidate = useInvalidateCandidates()
  return useMutation({
    mutationFn: ({ id, decision }) => hrApi.candidates.updateDecision(id, decision),
    onSuccess: invalidate,
  })
}

export function useDeleteCandidate() {
  const invalidate = useInvalidateCandidates()
  return useMutation({
    mutationFn: (id) => hrApi.candidates.delete(id),
    onSuccess: invalidate,
  })
}
