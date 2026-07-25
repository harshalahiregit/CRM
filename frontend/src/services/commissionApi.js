// Commission rules + entries — /api/sales/commission*
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.error || err?.response?.data?.message || 'Something went wrong'
  throw new Error(msg)
}

export const commissionApi = {
  rules: () =>
    api.get('/sales/commission-rules').then(r => r.data).catch(handleErr),
  createRule: (data) =>
    api.post('/sales/commission-rules', data).then(r => r.data).catch(handleErr),
  updateRule: (id, data) =>
    api.put(`/sales/commission-rules/${id}`, data).then(r => r.data).catch(handleErr),
  deleteRule: (id) =>
    api.delete(`/sales/commission-rules/${id}`).then(r => r.data).catch(handleErr),

  entries: (params = {}) =>
    api.get('/sales/commissions', { params }).then(r => r.data).catch(handleErr),
  summary: () =>
    api.get('/sales/commissions/summary').then(r => r.data).catch(handleErr),
  approve: (id) =>
    api.patch(`/sales/commissions/${id}/approve`).then(r => r.data).catch(handleErr),
  reject: (id) =>
    api.patch(`/sales/commissions/${id}/reject`).then(r => r.data).catch(handleErr),
  markPaid: (id) =>
    api.patch(`/sales/commissions/${id}/mark-paid`).then(r => r.data).catch(handleErr),
}

export default commissionApi
