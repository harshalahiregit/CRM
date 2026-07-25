// Tasks CRUD + actions — /api/sales/tasks/*
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.error || err?.response?.data?.message || 'Something went wrong'
  throw new Error(msg)
}

export const taskApi = {
  list: (params = {}) =>
    api.get('/sales/tasks', { params }).then(r => r.data).catch(handleErr),

  kanban: () =>
    api.get('/sales/tasks/kanban').then(r => r.data).catch(handleErr),

  get: (id) =>
    api.get(`/sales/tasks/${id}`).then(r => r.data).catch(handleErr),

  create: (data) =>
    api.post('/sales/tasks', data).then(r => r.data).catch(handleErr),

  update: (id, data) =>
    api.put(`/sales/tasks/${id}`, data).then(r => r.data).catch(handleErr),

  delete: (id) =>
    api.delete(`/sales/tasks/${id}`).then(r => r.data).catch(handleErr),

  updateStatus: (id, statusId) =>
    api.patch(`/sales/tasks/${id}/status`, { status_id: statusId }).then(r => r.data).catch(handleErr),

  assign: (id, assignees) =>
    api.patch(`/sales/tasks/${id}/assign`, { assignees }).then(r => r.data).catch(handleErr),

  updateProgress: (id, progress) =>
    api.patch(`/sales/tasks/${id}/progress`, { progress }).then(r => r.data).catch(handleErr),

  reorder: (order) =>
    api.post('/sales/tasks/reorder', { order }).then(r => r.data).catch(handleErr),

  addChecklistItem: (id, description) =>
    api.post(`/sales/tasks/${id}/checklist`, { description }).then(r => r.data).catch(handleErr),

  toggleChecklistItem: (itemId) =>
    api.patch(`/sales/task-checklist/${itemId}`).then(r => r.data).catch(handleErr),

  deleteChecklistItem: (itemId) =>
    api.delete(`/sales/task-checklist/${itemId}`).then(r => r.data).catch(handleErr),

  addComment: (id, content) =>
    api.post(`/sales/tasks/${id}/comments`, { content }).then(r => r.data).catch(handleErr),

  deleteComment: (commentId) =>
    api.delete(`/sales/task-comments/${commentId}`).then(r => r.data).catch(handleErr),

  // Statuses
  statuses: () =>
    api.get('/sales/task-statuses').then(r => r.data).catch(handleErr),
}

export default taskApi
