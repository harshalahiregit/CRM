// Projects — /api/projects/* (owner: Shivam)
// Backend wraps responses in { status, message, data }; we unwrap to `data`.
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

const unwrap = (r) => r.data?.data ?? r.data

export const projectApi = {
  // Project Settings catalog: full tab list (+ which are implemented), customer
  // permission toggles, contact-notification modes, default tab set. Drives both
  // the 2-tab create form and the workspace tab bar so options never drift.
  meta: () => api.get('/projects/meta').then(unwrap).catch(handleErr),
  list: (params = {}) => api.get('/projects', { params }).then(unwrap).catch(handleErr),
  get: (id) => api.get(`/projects/${id}`).then(unwrap).catch(handleErr),
  create: (data) => api.post('/projects', data).then(unwrap).catch(handleErr),
  update: (id, data) => api.put(`/projects/${id}`, data).then(unwrap).catch(handleErr),
  remove: (id) => api.delete(`/projects/${id}`).then(unwrap).catch(handleErr),
  setStatus: (id, status) => api.patch(`/projects/${id}/status`, { status }).then(unwrap).catch(handleErr),
  progress: (id) => api.get(`/projects/${id}/progress`).then(unwrap).catch(handleErr),
  copy: (id, opts = {}) => api.post(`/projects/${id}/copy`, opts).then(unwrap).catch(handleErr),
  // Pinning is per-user — it floats the project up YOUR list only.
  pin: (id) => api.post(`/projects/${id}/pin`).then(unwrap).catch(handleErr),
  staff: () => api.get('/projects/staff').then(unwrap).catch(handleErr),
  // Resolved through CustomerServiceContract — replaces typing a raw customer id.
  customers: () => api.get('/projects/customers').then(unwrap).catch(handleErr),

  // Step 2 (members / milestones / files)
  members: (id, user_ids) => api.post(`/projects/${id}/members`, { user_ids }).then(unwrap).catch(handleErr),
  milestones: (id) => api.get(`/projects/${id}/milestones`).then(unwrap).catch(handleErr),
  createMilestone: (id, data) => api.post(`/projects/${id}/milestones`, data).then(unwrap).catch(handleErr),
  updateMilestone: (mid, data) => api.put(`/projects/milestones/${mid}`, data).then(unwrap).catch(handleErr),
  deleteMilestone: (mid) => api.delete(`/projects/milestones/${mid}`).then(unwrap).catch(handleErr),
  files: (id) => api.get(`/projects/${id}/files`).then(unwrap).catch(handleErr),
  uploadFile: (id, formData) => api.post(`/projects/${id}/files`, formData, { headers: { 'Content-Type': 'multipart/form-data' } }).then(unwrap).catch(handleErr),

  deleteFile: (id, fileId) => api.delete(`/projects/${id}/files/${fileId}`).then(unwrap).catch(handleErr),
  downloadFile: async (id, fileId, filename) => {
    const res = await api.get(`/projects/${id}/files/${fileId}/download`, { responseType: 'blob' }).catch(handleErr)
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url; a.download = filename || 'download'
    document.body.appendChild(a); a.click(); a.remove()
    URL.revokeObjectURL(url)
  },

  // Step 5 integration — tickets linked to a project
  tickets: (id) => api.get(`/projects/${id}/tickets`).then(unwrap).catch(handleErr),

  // Invoice Project — list drafts / generate one by the project's billing type
  invoices: (id) => api.get(`/projects/${id}/invoices`).then(unwrap).catch(handleErr),
  generateInvoice: (id) => api.post(`/projects/${id}/invoices`).then(unwrap).catch(handleErr),

  // Notes / Activity / Timesheets tabs
  notes: (id) => api.get(`/projects/${id}/notes`).then(unwrap).catch(handleErr),
  addNote: (id, data) => api.post(`/projects/${id}/notes`, data).then(unwrap).catch(handleErr),
  deleteNote: (id, noteId) => api.delete(`/projects/${id}/notes/${noteId}`).then(unwrap).catch(handleErr),
  activity: (id) => api.get(`/projects/${id}/activity`).then(unwrap).catch(handleErr),
  timesheets: (id) => api.get(`/projects/${id}/timesheets`).then(unwrap).catch(handleErr),

  // Meeting tab — returns { meetings, counters: { total, completed, pending } }
  meetings: (id) => api.get(`/projects/${id}/meetings`).then(unwrap).catch(handleErr),
  createMeeting: (id, data) => api.post(`/projects/${id}/meetings`, data).then(unwrap).catch(handleErr),
  updateMeeting: (id, mid, data) => api.put(`/projects/${id}/meetings/${mid}`, data).then(unwrap).catch(handleErr),
  deleteMeeting: (id, mid) => api.delete(`/projects/${id}/meetings/${mid}`).then(unwrap).catch(handleErr),

  // Discussions tab — list carries comment_count + last_activity per row
  discussions: (id) => api.get(`/projects/${id}/discussions`).then(unwrap).catch(handleErr),
  createDiscussion: (id, data) => api.post(`/projects/${id}/discussions`, data).then(unwrap).catch(handleErr),
  deleteDiscussion: (id, did) => api.delete(`/projects/${id}/discussions/${did}`).then(unwrap).catch(handleErr),
  discussionComments: (id, did) => api.get(`/projects/${id}/discussions/${did}/comments`).then(unwrap).catch(handleErr),
  addDiscussionComment: (id, did, content) => api.post(`/projects/${id}/discussions/${did}/comments`, { content }).then(unwrap).catch(handleErr),
}

/** Shared status metadata — token-driven so both themes work. */
export const PROJECT_STATUS = {
  not_started: { label: 'Not Started', color: 'var(--text-muted)' },
  in_progress: { label: 'In Progress', color: 'var(--color-info-500)' },
  on_hold:     { label: 'On Hold',     color: 'var(--color-warning-500)' },
  cancelled:   { label: 'Cancelled',   color: 'var(--color-danger-500)' },
  finished:    { label: 'Finished',    color: 'var(--color-success-500)' },
}

export const BILLING_TYPES = [
  { value: 'fixed',         label: 'Fixed Rate' },
  { value: 'project_hours', label: 'Project Hours' },
  { value: 'task_hours',    label: 'Task Hours' },
]

/** Module accent — Projects rides the app's primary purple. */
export const PROJECT_ACCENT = 'var(--color-primary-500)'

export default projectApi
