// Tasks — /api/tasks/* (owner: Shivam)
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.message || err?.response?.data?.error || 'Something went wrong'
  throw new Error(msg)
}
const unwrap = (r) => r.data?.data ?? r.data

export const taskApi = {
  list: (params = {}) => api.get('/tasks', { params }).then(unwrap).catch(handleErr),
  get: (id) => api.get(`/tasks/${id}`).then(unwrap).catch(handleErr),
  create: (data) => api.post('/tasks', data).then(unwrap).catch(handleErr),
  update: (id, data) => api.put(`/tasks/${id}`, data).then(unwrap).catch(handleErr),
  remove: (id) => api.delete(`/tasks/${id}`).then(unwrap).catch(handleErr),
  setStatus: (id, status) => api.patch(`/tasks/${id}/status`, { status }).then(unwrap).catch(handleErr),

  // Step 4 sub-features
  assignees: (id, user_ids) => api.post(`/tasks/${id}/assignees`, { user_ids }).then(unwrap).catch(handleErr),
  followers: (id, user_ids) => api.post(`/tasks/${id}/followers`, { user_ids }).then(unwrap).catch(handleErr),
  checklist: (id) => api.get(`/tasks/${id}/checklist`).then(unwrap).catch(handleErr),
  addChecklist: (id, description) => api.post(`/tasks/${id}/checklist`, { description }).then(unwrap).catch(handleErr),
  toggleChecklist: (itemId) => api.patch(`/tasks/checklist/${itemId}/toggle`).then(unwrap).catch(handleErr),
  comments: (id) => api.get(`/tasks/${id}/comments`).then(unwrap).catch(handleErr),
  addComment: (id, content) => api.post(`/tasks/${id}/comments`, { content }).then(unwrap).catch(handleErr),
  startTimer: (id, note) => api.post(`/tasks/${id}/timer/start`, { note }).then(unwrap).catch(handleErr),
  stopTimer: (id) => api.post(`/tasks/${id}/timer/stop`).then(unwrap).catch(handleErr),
  totalTime: (id) => api.get(`/tasks/${id}/total-time`).then(unwrap).catch(handleErr),
  billable: (params = {}) => api.get('/tasks/billable', { params }).then(unwrap).catch(handleErr),
}

// Shared status metadata (list + kanban).
export const TASK_STATUS = {
  not_started:       { label: 'Not Started',  color: '#94a3b8' },
  in_progress:       { label: 'In Progress',  color: '#3b82f6' },
  awaiting_feedback: { label: 'Awaiting Feedback', color: '#a78bfa' },
  testing:           { label: 'Testing',      color: '#fbbf24' },
  complete:          { label: 'Complete',     color: '#10b981' },
}
export const TASK_PRIORITY = {
  urgent: '#ef4444', high: '#f87171', medium: '#fbbf24', low: '#10b981',
}

export default taskApi
