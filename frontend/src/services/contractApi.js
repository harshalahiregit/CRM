// Contracts — /api/sales/contracts/*
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.error || err?.response?.data?.message || 'Something went wrong'
  throw new Error(msg)
}

export const contractApi = {
  list: (params = {}) =>
    api.get('/sales/contracts', { params }).then(r => r.data).catch(handleErr),

  expiring: (days = 30) =>
    api.get('/sales/contracts/expiring', { params: { days } }).then(r => r.data).catch(handleErr),

  get: (id) =>
    api.get(`/sales/contracts/${id}`).then(r => r.data).catch(handleErr),

  create: (data) =>
    api.post('/sales/contracts', data).then(r => r.data).catch(handleErr),

  update: (id, data) =>
    api.put(`/sales/contracts/${id}`, data).then(r => r.data).catch(handleErr),

  delete: (id) =>
    api.delete(`/sales/contracts/${id}`).then(r => r.data).catch(handleErr),

  updateStatus: (id, status) =>
    api.patch(`/sales/contracts/${id}/status`, { status }).then(r => r.data).catch(handleErr),

  renew: (id, data = {}) =>
    api.post(`/sales/contracts/${id}/renew`, data).then(r => r.data).catch(handleErr),

  sign: (id, data) =>
    api.post(`/sales/contracts/${id}/sign`, data).then(r => r.data).catch(handleErr),

  renewals: (id) =>
    api.get(`/sales/contracts/${id}/renewals`).then(r => r.data).catch(handleErr),

  send: (id, data) =>
    api.post(`/sales/contracts/${id}/send`, data).then(r => r.data).catch(handleErr),

  comments: {
    add: (id, body) => api.post(`/sales/contracts/${id}/comments`, { body }).then(r => r.data).catch(handleErr),
    remove: (id, commentId) => api.delete(`/sales/contracts/${id}/comments/${commentId}`).then(r => r.data).catch(handleErr),
  },

  downloadPdf: async (id, filename) => {
    const res = await api.get(`/sales/contracts/${id}/pdf`, { responseType: 'blob' }).catch(handleErr)
    const url = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    const link = document.createElement('a')
    link.href = url
    link.download = filename || `contract-${id}.pdf`
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  },

  types: () =>
    api.get('/sales/contract-types').then(r => r.data).catch(handleErr),

  createType: (name) =>
    api.post('/sales/contract-types', { name }).then(r => r.data).catch(handleErr),
}

export default contractApi
