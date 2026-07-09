import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { leadApi } from '@/services/leadApi'

export function useLeads(params = {}) {
  return useQuery({
    queryKey: ['leads', params],
    queryFn: () => leadApi.list(params),
  })
}

export function useLead(id) {
  return useQuery({
    queryKey: ['leads', id],
    queryFn: () => leadApi.get(id),
    enabled: !!id,
  })
}

export function useLeadKanban() {
  return useQuery({
    queryKey: ['leads', 'kanban'],
    queryFn: () => leadApi.kanban(),
  })
}

export function useLeadSummary() {
  return useQuery({
    queryKey: ['leads', 'summary'],
    queryFn: () => leadApi.summary(),
  })
}

function useInvalidateLeads() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: ['leads'] })
}

export function useCreateLead() {
  const invalidate = useInvalidateLeads()
  return useMutation({
    mutationFn: (data) => leadApi.create(data),
    onSuccess: invalidate,
  })
}

export function useUpdateLead() {
  const invalidate = useInvalidateLeads()
  return useMutation({
    mutationFn: ({ id, data }) => leadApi.update(id, data),
    onSuccess: invalidate,
  })
}

export function useDeleteLead() {
  const invalidate = useInvalidateLeads()
  return useMutation({
    mutationFn: (id) => leadApi.delete(id),
    onSuccess: invalidate,
  })
}

export function useUpdateLeadStatus() {
  const invalidate = useInvalidateLeads()
  return useMutation({
    mutationFn: ({ id, statusId }) => leadApi.updateStatus(id, statusId),
    onSuccess: invalidate,
  })
}

export function useAssignLead() {
  const invalidate = useInvalidateLeads()
  return useMutation({
    mutationFn: ({ id, userId }) => leadApi.assign(id, userId),
    onSuccess: invalidate,
  })
}

export function useConvertLead() {
  const invalidate = useInvalidateLeads()
  return useMutation({
    mutationFn: ({ id, data }) => leadApi.convert(id, data),
    onSuccess: invalidate,
  })
}

export function useBulkLeadAction() {
  const invalidate = useInvalidateLeads()
  return useMutation({
    mutationFn: (data) => leadApi.bulkAction(data),
    onSuccess: invalidate,
  })
}
