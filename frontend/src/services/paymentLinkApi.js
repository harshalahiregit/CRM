// Payment Links CRUD — /api/sales/payment-links/*
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.error || err?.response?.data?.message || 'Something went wrong'
  throw new Error(msg)
}

export const paymentLinkApi = {
  list: (params = {}) =>
    api.get('/sales/payment-links', { params }).then(r => r.data).catch(handleErr),

  create: (data) =>
    api.post('/sales/payment-links', data).then(r => r.data).catch(handleErr),

  markPaid: (id, data = {}) =>
    api.patch(`/sales/payment-links/${id}/mark-paid`, data).then(r => r.data).catch(handleErr),

  cancel: (id) =>
    api.patch(`/sales/payment-links/${id}/cancel`).then(r => r.data).catch(handleErr),

  delete: (id) =>
    api.delete(`/sales/payment-links/${id}`).then(r => r.data).catch(handleErr),
}

export default paymentLinkApi
