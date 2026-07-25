// Tasks — /api/tasks/* (owner: Shivam)
import api from '@/lib/api'

const handleErr = (err) => {
  const data = err?.response?.data
  // Surface the specific field error (e.g. "File too large") instead of the
  // generic "Validation failed", so the real reason reaches the user.
  const fieldErr = data?.errors && Object.values(data.errors)?.[0]?.[0]
  const msg = fieldErr || data?.message || data?.error || 'Something went wrong'
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
  // Persists a kanban column order after a drag; also applies cross-column moves.
  reorder: (status, ordered_ids) => api.post('/tasks/reorder', { status, ordered_ids }).then(unwrap).catch(handleErr),
  staff: () => api.get('/tasks/staff').then(unwrap).catch(handleErr),
  stats: () => api.get('/tasks/stats').then(unwrap).catch(handleErr),
  // One request for N tasks — not N parallel single-item calls.
  bulk: (action, task_ids, value = null) => api.post('/tasks/bulk', { action, task_ids, value }).then(unwrap).catch(handleErr),

  // Step 4 sub-features
  assignees: (id, user_ids) => api.post(`/tasks/${id}/assignees`, { user_ids }).then(unwrap).catch(handleErr),
  followers: (id, user_ids) => api.post(`/tasks/${id}/followers`, { user_ids }).then(unwrap).catch(handleErr),
  checklist: (id) => api.get(`/tasks/${id}/checklist`).then(unwrap).catch(handleErr),
  addChecklist: (id, description) => api.post(`/tasks/${id}/checklist`, { description }).then(unwrap).catch(handleErr),
  toggleChecklist: (itemId) => api.patch(`/tasks/checklist/${itemId}/toggle`).then(unwrap).catch(handleErr),
  // Subtasks — the recursive tree. `tree` returns every level in one response
  // (the server carries root_id, so depth costs nothing), each node with its own
  // rolled-up progress. `move` re-parents; parent_id null pops it to the top.
  tree: (id) => api.get(`/tasks/${id}/tree`).then(unwrap).catch(handleErr),
  addSubtask: (id, data) => api.post(`/tasks/${id}/subtasks`, data).then(unwrap).catch(handleErr),
  move: (id, parent_id) => api.patch(`/tasks/${id}/move`, { parent_id }).then(unwrap).catch(handleErr),

  // Notification / email switches (read: any staff, write: admin).
  settings: () => api.get('/tasks/settings').then(unwrap).catch(handleErr),
  saveSettings: (settings) => api.put('/tasks/settings', { settings }).then(unwrap).catch(handleErr),

  comments: (id) => api.get(`/tasks/${id}/comments`).then(unwrap).catch(handleErr),
  // Post a comment; when files are supplied, send multipart so they attach to it.
  // Overriding Content-Type lets axios set the multipart boundary itself — the
  // instance default (application/json) would otherwise break the file upload.
  addComment: (id, content, files = []) => {
    if (!files || files.length === 0) {
      return api.post(`/tasks/${id}/comments`, { content }).then(unwrap).catch(handleErr)
    }
    const fd = new FormData()
    fd.append('content', content ?? '')
    Array.from(files).forEach(f => fd.append('files[]', f))
    return api.post(`/tasks/${id}/comments`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then(unwrap).catch(handleErr)
  },
  startTimer: (id, note) => api.post(`/tasks/${id}/timer/start`, { note }).then(unwrap).catch(handleErr),
  stopTimer: (id) => api.post(`/tasks/${id}/timer/stop`).then(unwrap).catch(handleErr),
  totalTime: (id) => api.get(`/tasks/${id}/total-time`).then(unwrap).catch(handleErr),
  billable: (params = {}) => api.get('/tasks/billable', { params }).then(unwrap).catch(handleErr),

  copy: (id, opts = {}) => api.post(`/tasks/${id}/copy`, opts).then(unwrap).catch(handleErr),

  // Attachments — files live on a private disk; downloads are authenticated,
  // so they're fetched as a blob rather than linked to directly.
  files: (id) => api.get(`/tasks/${id}/files`).then(unwrap).catch(handleErr),
  uploadFiles: (id, fileList) => {
    const fd = new FormData()
    Array.from(fileList).forEach(f => fd.append('files[]', f))
    // Override Content-Type so axios sets the multipart boundary (the instance
    // default application/json would make the backend see zero files).
    return api.post(`/tasks/${id}/files`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then(unwrap).catch(handleErr)
  },
  deleteFile: (id, fileId) => api.delete(`/tasks/${id}/files/${fileId}`).then(unwrap).catch(handleErr),
  downloadFile: async (id, fileId, filename) => {
    const res = await api.get(`/tasks/${id}/files/${fileId}/download`, { responseType: 'blob' }).catch(handleErr)
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url; a.download = filename || 'download'
    document.body.appendChild(a); a.click(); a.remove()
    URL.revokeObjectURL(url)
  },

  // Reminders
  reminders: (id) => api.get(`/tasks/${id}/reminders`).then(unwrap).catch(handleErr),
  addReminder: (id, data) => api.post(`/tasks/${id}/reminders`, data).then(unwrap).catch(handleErr),
  deleteReminder: (id, rid) => api.delete(`/tasks/${id}/reminders/${rid}`).then(unwrap).catch(handleErr),

  // Reusable checklist templates
  templates: () => api.get('/tasks/templates').then(unwrap).catch(handleErr),
  createTemplate: (data) => api.post('/tasks/templates', data).then(unwrap).catch(handleErr),
  deleteTemplate: (tid) => api.delete(`/tasks/templates/${tid}`).then(unwrap).catch(handleErr),
  applyTemplate: (id, template_id) => api.post(`/tasks/${id}/checklist/apply-template`, { template_id }).then(unwrap).catch(handleErr),
  saveChecklistAsTemplate: (id, name) => api.post(`/tasks/${id}/checklist/save-template`, { name }).then(unwrap).catch(handleErr),
}

export const RECURRING_TYPES = [
  { value: 'day', label: 'Day' }, { value: 'week', label: 'Week' },
  { value: 'month', label: 'Month' }, { value: 'year', label: 'Year' },
]

/** Checklist completion for the TASK PROGRESS column. Null when there's no checklist. */
export const taskProgress = (t) => {
  const total = t?.checklist_items_count ?? 0
  if (!total) return null
  return { done: t.checklist_done_count ?? 0, total, pct: Math.round(((t.checklist_done_count ?? 0) / total) * 100) }
}

export const fmtBytes = (b = 0) => {
  if (b < 1024) return `${b} B`
  if (b < 1024 * 1024) return `${(b / 1024).toFixed(0)} KB`
  return `${(b / 1024 / 1024).toFixed(1)} MB`
}

// Shared status metadata (list + kanban). Token-driven so both themes work.
export const TASK_STATUS = {
  not_started:       { label: 'Not Started',       color: 'var(--text-muted)' },
  in_progress:       { label: 'In Progress',       color: 'var(--color-info-500)' },
  testing:           { label: 'Testing',           color: 'var(--color-warning-500)' },
  awaiting_feedback: { label: 'Awaiting Feedback', color: 'var(--color-primary-400)' },
  complete:          { label: 'Complete',          color: 'var(--color-success-500)' },
}
export const TASK_PRIORITY = {
  urgent: 'var(--color-danger-500)',
  high:   'var(--color-warning-600)',
  medium: 'var(--color-warning-500)',
  low:    'var(--color-success-500)',
}

/** Module accent — Tasks rides the app's primary purple (was a hardcoded pink). */
export const TASK_ACCENT = 'var(--color-primary-500)'

export const REL_TYPES = [
  { value: 'standalone', label: 'Standalone' },
  { value: 'project',    label: 'Project' },
  { value: 'ticket',     label: 'Ticket' },
  { value: 'customer',   label: 'Customer' },
]

/** Human label for a task's link, using the server-resolved rel_label. */
export const relLabel = (t) =>
  !t || t.rel_type === 'standalone' || !t.rel_id
    ? null
    : t.rel_label || `${t.rel_type} #${t.rel_id}`

export const fmtDuration = (secs = 0) => {
  const s = Math.max(0, Math.floor(secs))
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  return h ? `${h}h ${m}m` : `${m}m`
}

export default taskApi
