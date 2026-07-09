import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { hrApi } from '@/services/hrApi'

export function useManpowerRequests(params = {}) {
  return useQuery({
    queryKey: ['manpower-requests', params],
    queryFn: () => hrApi.manpower.list(params),
  })
}

export function useManpowerRequest(id) {
  return useQuery({
    queryKey: ['manpower-requests', id],
    queryFn: () => hrApi.manpower.get(id),
    enabled: !!id,
  })
}

function useInvalidateManpowerRequests() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: ['manpower-requests'] })
}

export function useCreateManpowerRequest() {
  const invalidate = useInvalidateManpowerRequests()
  return useMutation({
    mutationFn: (data) => hrApi.manpower.create(data),
    onSuccess: invalidate,
  })
}

export function useUpdateManpowerRequestStatus() {
  const invalidate = useInvalidateManpowerRequests()
  return useMutation({
    mutationFn: ({ id, payload }) => hrApi.manpower.updateStatus(id, payload),
    onSuccess: invalidate,
  })
}

export function useDeleteManpowerRequest() {
  const invalidate = useInvalidateManpowerRequests()
  return useMutation({
    mutationFn: (id) => hrApi.manpower.delete(id),
    onSuccess: invalidate,
  })
}
