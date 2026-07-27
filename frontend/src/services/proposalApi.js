// Proposal CRUD — /api/sales/proposals/*
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

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

  submit: (id, email) =>
    api.post(`/sales/proposals/${id}/submit`, email).then(r => r.data).catch(handleErr),

  saveAsTemplate: (id, data) =>
    api.post(`/sales/proposals/${id}/save-as-template`, data).then(r => r.data).catch(handleErr),

  generateQR: (id) =>
    api.post(`/sales/proposals/${id}/generate-qr`).then(r => r.data).catch(handleErr),

  convertToEstimate: (id) =>
    api.post(`/sales/proposals/${id}/convert-to-estimate`).then(r => r.data).catch(handleErr),

  convertToInvoice: (id) =>
    api.post(`/sales/proposals/${id}/convert-to-invoice`).then(r => r.data).catch(handleErr),

  // PDF endpoint requires the auth token, so a plain <a href> won't work —
  // fetch as a blob and trigger the download via a temporary object URL.
  downloadPdf: async (id, filename) => {
    const res = await api.get(`/sales/proposals/${id}/pdf`, { responseType: 'blob' }).catch(handleErr)
    const url = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    const link = document.createElement('a')
    link.href = url
    link.download = filename || `proposal-${id}.pdf`
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  },
}

export default proposalApi
