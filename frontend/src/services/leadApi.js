// Leads CRUD + actions — /api/sales/leads/*
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.error || err?.response?.data?.message || 'Something went wrong'
  throw new Error(msg)
}

export const leadApi = {
  list: (params = {}) =>
    api.get('/sales/leads', { params }).then(r => r.data).catch(handleErr),

  get: (id) =>
    api.get(`/sales/leads/${id}`).then(r => r.data).catch(handleErr),

  create: (data) =>
    api.post('/sales/leads', data).then(r => r.data).catch(handleErr),

  update: (id, data) =>
    api.put(`/sales/leads/${id}`, data).then(r => r.data).catch(handleErr),

  delete: (id) =>
    api.delete(`/sales/leads/${id}`).then(r => r.data).catch(handleErr),

  kanban: () =>
    api.get('/sales/leads/kanban').then(r => r.data).catch(handleErr),

  summary: () =>
    api.get('/sales/leads/summary').then(r => r.data).catch(handleErr),

  updateStatus: (id, statusId) =>
    api.patch(`/sales/leads/${id}/status`, { status_id: statusId }).then(r => r.data).catch(handleErr),

  assign: (id, userId) =>
    api.patch(`/sales/leads/${id}/assign`, { assigned_to: userId }).then(r => r.data).catch(handleErr),

  markLost: (id) =>
    api.patch(`/sales/leads/${id}/lost`).then(r => r.data).catch(handleErr),

  markJunk: (id) =>
    api.patch(`/sales/leads/${id}/junk`).then(r => r.data).catch(handleErr),

  restore: (id) =>
    api.patch(`/sales/leads/${id}/restore`).then(r => r.data).catch(handleErr),

  convert: (id, data = {}) =>
    api.post(`/sales/leads/${id}/convert`, data).then(r => r.data).catch(handleErr),

  addNote: (id, data) =>
    api.post(`/sales/leads/${id}/notes`, data).then(r => r.data).catch(handleErr),

  submitQuestionnaire: (id, data) =>
    api.post(`/sales/leads/${id}/questionnaire-response`, data).then(r => r.data).catch(handleErr),

  bulkAction: (data) =>
    api.post('/sales/leads/bulk', data).then(r => r.data).catch(handleErr),
}

export default leadApi
