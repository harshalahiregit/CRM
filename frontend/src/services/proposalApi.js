// Proposal CRUD — /api/sales/proposals/*
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.error || err?.response?.data?.message || 'Something went wrong'
  throw new Error(msg)
}

export const proposalApi = {
  list: (params = {}) =>
    api.get('/sales/proposals', { params }).then(r => r.data).catch(handleErr),

  get: (id) =>
    api.get(`/sales/proposals/${id}`).then(r => r.data).catch(handleErr),

  create: (data) =>
    api.post('/sales/proposals', data).then(r => r.data).catch(handleErr),

  update: (id, data) =>
    api.put(`/sales/proposals/${id}`, data).then(r => r.data).catch(handleErr),

  delete: (id) =>
    api.delete(`/sales/proposals/${id}`).then(r => r.data).catch(handleErr),

  send: (id) =>
    api.patch(`/sales/proposals/${id}/send`).then(r => r.data).catch(handleErr),

  updateStatus: (id, status) =>
    api.patch(`/sales/proposals/${id}/status`, { status }).then(r => r.data).catch(handleErr),
}

export default proposalApi
