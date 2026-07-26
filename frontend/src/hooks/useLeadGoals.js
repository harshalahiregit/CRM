import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { leadSettingsApi } from '@/services/leadSettingsApi'

export function useLeadGoals(params = {}) {
  return useQuery({
    queryKey: ['lead-goals', params],
    queryFn: () => leadSettingsApi.goals.list(params),
  })
}

function useInvalidateLeadGoals() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: ['lead-goals'] })
}

export function useCreateLeadGoal() {
  const invalidate = useInvalidateLeadGoals()
  return useMutation({
    mutationFn: (data) => leadSettingsApi.goals.create(data),
    onSuccess: invalidate,
  })
}

export function useUpdateLeadGoal() {
  const invalidate = useInvalidateLeadGoals()
  return useMutation({
    mutationFn: ({ id, data }) => leadSettingsApi.goals.update(id, data),
    onSuccess: invalidate,
  })
}

export function useDeleteLeadGoal() {
  const invalidate = useInvalidateLeadGoals()
  return useMutation({
    mutationFn: (id) => leadSettingsApi.goals.delete(id),
    onSuccess: invalidate,
  })
}
