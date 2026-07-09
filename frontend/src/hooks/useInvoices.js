import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { invoiceApi } from '@/services/invoiceApi'

export function useInvoices(params = {}) {
  return useQuery({
    queryKey: ['invoices', params],
    queryFn: () => invoiceApi.list(params),
  })
}

export function useInvoice(id) {
  return useQuery({
    queryKey: ['invoices', id],
    queryFn: () => invoiceApi.get(id),
    enabled: !!id,
  })
}

function useInvalidateInvoices() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: ['invoices'] })
}

export function useCreateInvoice() {
  const invalidate = useInvalidateInvoices()
  return useMutation({
    mutationFn: (data) => invoiceApi.create(data),
    onSuccess: invalidate,
  })
}

export function useUpdateInvoice() {
  const invalidate = useInvalidateInvoices()
  return useMutation({
    mutationFn: ({ id, data }) => invoiceApi.update(id, data),
    onSuccess: invalidate,
  })
}

export function useDeleteInvoice() {
  const invalidate = useInvalidateInvoices()
  return useMutation({
    mutationFn: (id) => invoiceApi.delete(id),
    onSuccess: invalidate,
  })
}

export function useSendInvoice() {
  const invalidate = useInvalidateInvoices()
  return useMutation({
    mutationFn: (id) => invoiceApi.send(id),
    onSuccess: invalidate,
  })
}

export function useRecordPayment() {
  const invalidate = useInvalidateInvoices()
  return useMutation({
    mutationFn: ({ id, data }) => invoiceApi.recordPayment(id, data),
    onSuccess: invalidate,
  })
}
