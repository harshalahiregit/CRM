import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { estimateApi } from '@/services/estimateApi'

export function useEstimates(params = {}) {
  return useQuery({
    queryKey: ['estimates', params],
    queryFn: () => estimateApi.list(params),
  })
}

export function useEstimate(id) {
  return useQuery({
    queryKey: ['estimates', id],
    queryFn: () => estimateApi.get(id),
    enabled: !!id,
  })
}

function useInvalidateEstimates() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: ['estimates'] })
}

export function useCreateEstimate() {
  const invalidate = useInvalidateEstimates()
  return useMutation({
    mutationFn: (data) => estimateApi.create(data),
    onSuccess: invalidate,
  })
}

export function useUpdateEstimate() {
  const invalidate = useInvalidateEstimates()
  return useMutation({
    mutationFn: ({ id, data }) => estimateApi.update(id, data),
    onSuccess: invalidate,
  })
}

export function useDeleteEstimate() {
  const invalidate = useInvalidateEstimates()
  return useMutation({
    mutationFn: (id) => estimateApi.delete(id),
    onSuccess: invalidate,
  })
}

export function useConvertEstimateToInvoice() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }) => estimateApi.convertToInvoice(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['estimates'] })
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
    },
  })
}
