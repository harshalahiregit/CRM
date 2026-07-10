// Projects — /api/projects/* (owner: Shivam)
// Backend wraps responses in { status, message, data }; we unwrap to `data`.
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.message || err?.response?.data?.error || 'Something went wrong'
  throw new Error(msg)
}
const unwrap = (r) => r.data?.data ?? r.data

export const projectApi = {
  list: (params = {}) => api.get('/projects', { params }).then(unwrap).catch(handleErr),
  get: (id) => api.get(`/projects/${id}`).then(unwrap).catch(handleErr),
  create: (data) => api.post('/projects', data).then(unwrap).catch(handleErr),
  update: (id, data) => api.put(`/projects/${id}`, data).then(unwrap).catch(handleErr),
  remove: (id) => api.delete(`/projects/${id}`).then(unwrap).catch(handleErr),
  setStatus: (id, status) => api.patch(`/projects/${id}/status`, { status }).then(unwrap).catch(handleErr),
  progress: (id) => api.get(`/projects/${id}/progress`).then(unwrap).catch(handleErr),

  // Step 2 (members / milestones / files)
  members: (id, user_ids) => api.post(`/projects/${id}/members`, { user_ids }).then(unwrap).catch(handleErr),
  milestones: (id) => api.get(`/projects/${id}/milestones`).then(unwrap).catch(handleErr),
  createMilestone: (id, data) => api.post(`/projects/${id}/milestones`, data).then(unwrap).catch(handleErr),
  updateMilestone: (mid, data) => api.put(`/projects/milestones/${mid}`, data).then(unwrap).catch(handleErr),
  deleteMilestone: (mid) => api.delete(`/projects/milestones/${mid}`).then(unwrap).catch(handleErr),
  files: (id) => api.get(`/projects/${id}/files`).then(unwrap).catch(handleErr),
  uploadFile: (id, formData) => api.post(`/projects/${id}/files`, formData, { headers: { 'Content-Type': 'multipart/form-data' } }).then(unwrap).catch(handleErr),

  // Step 5 integration — tickets linked to a project
  tickets: (id) => api.get(`/projects/${id}/tickets`).then(unwrap).catch(handleErr),
}

export default projectApi
