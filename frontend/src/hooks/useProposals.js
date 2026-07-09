import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { proposalApi } from '@/services/proposalApi'

export function useProposals(params = {}) {
  return useQuery({
    queryKey: ['proposals', params],
    queryFn: () => proposalApi.list(params),
  })
}

export function useProposal(id) {
  return useQuery({
    queryKey: ['proposals', id],
    queryFn: () => proposalApi.get(id),
    enabled: !!id,
  })
}

function useInvalidateProposals() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: ['proposals'] })
}

export function useCreateProposal() {
  const invalidate = useInvalidateProposals()
  return useMutation({
    mutationFn: (data) => proposalApi.create(data),
    onSuccess: invalidate,
  })
}

export function useUpdateProposal() {
  const invalidate = useInvalidateProposals()
  return useMutation({
    mutationFn: ({ id, data }) => proposalApi.update(id, data),
    onSuccess: invalidate,
  })
}

export function useDeleteProposal() {
  const invalidate = useInvalidateProposals()
  return useMutation({
    mutationFn: (id) => proposalApi.delete(id),
    onSuccess: invalidate,
  })
}

export function useSendProposal() {
  const invalidate = useInvalidateProposals()
  return useMutation({
    mutationFn: (id) => proposalApi.send(id),
    onSuccess: invalidate,
  })
}

export function useUpdateProposalStatus() {
  const invalidate = useInvalidateProposals()
  return useMutation({
    mutationFn: ({ id, status }) => proposalApi.updateStatus(id, status),
    onSuccess: invalidate,
  })
}
