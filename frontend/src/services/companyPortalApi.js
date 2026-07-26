/**
 * External Company Portal API.
 *
 * Authenticated endpoints use the shared client (company users have a real
 * login; bearer token + 401→/auth/login is correct). Registration is public but
 * returns 201/422 (never 401), so the shared client is safe there too. Every
 * endpoint resolves the company from the token server-side — no company id is
 * ever passed.
 */
import api from '@/lib/api'

const BASE = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'

export const companyPortalApi = {
  // Public self-registration
  register: (payload) => api.post('/auth/register/company', payload).then(r => r.data),

  // Authenticated portal
  me:        () => api.get('/company-portal/me').then(r => r.data),
  dashboard: () => api.get('/company-portal/dashboard').then(r => r.data),
  meta:      () => api.get('/company-portal/meta').then(r => r.data),
  logoBlob:  () => api.get('/company-portal/logo', { responseType: 'blob' }).then(r => r.data),

  // Company administration (Sprint 5)
  adminProfile:       ()        => api.get('/company-portal/admin/profile').then(r => r.data),
  updateProfile:      (data)    => api.put('/company-portal/admin/profile', data).then(r => r.data),
  uploadLogo:         (file)    => { const fd = new FormData(); fd.append('logo', file); return api.post('/company-portal/admin/logo', fd).then(r => r.data) },
  updateBranding:     (data)    => api.put('/company-portal/admin/branding', data).then(r => r.data),
  updateNotifications:(data)    => api.put('/company-portal/admin/notifications', data).then(r => r.data),
  users:              ()        => api.get('/company-portal/admin/users').then(r => r.data),
  createUser:         (data)    => api.post('/company-portal/admin/users', data).then(r => r.data),
  assignRole:         (uid, role)   => api.post(`/company-portal/admin/users/${uid}/role`, { role }).then(r => r.data),
  setUserStatus:      (uid, active) => api.post(`/company-portal/admin/users/${uid}/status`, { active }).then(r => r.data),
  resetUserPassword:  (uid)     => api.post(`/company-portal/admin/users/${uid}/reset-password`).then(r => r.data),
  removeUser:         (uid)     => api.delete(`/company-portal/admin/users/${uid}`).then(r => r.data),
  reports:            ()        => api.get('/company-portal/admin/reports').then(r => r.data),
  activity:           (page = 1)=> api.get('/company-portal/admin/activity', { params: { page } }).then(r => r.data),
  security:           ()        => api.get('/company-portal/admin/security').then(r => r.data),
  changePassword:     (data)    => api.post('/company-portal/admin/change-password', data).then(r => r.data),

  // Hiring requests
  requests:       (params = {}) => api.get('/company-portal/hiring-requests', { params }).then(r => r.data),
  getRequest:     (id)          => api.get(`/company-portal/hiring-requests/${id}`).then(r => r.data),
  createRequest:  (data)        => api.post('/company-portal/hiring-requests', data).then(r => r.data),
  updateRequest:  (id, data)    => api.put(`/company-portal/hiring-requests/${id}`, data).then(r => r.data),
  submitRequest:  (id)          => api.post(`/company-portal/hiring-requests/${id}/submit`).then(r => r.data),

  // Messages
  messages:    (id)       => api.get(`/company-portal/hiring-requests/${id}/messages`).then(r => r.data),
  postMessage: (id, body) => api.post(`/company-portal/hiring-requests/${id}/messages`, { body }).then(r => r.data),

  // Candidates (Sprint 3)
  candidates:       (id, params = {}) => api.get(`/company-portal/hiring-requests/${id}/candidates`, { params }).then(r => r.data),
  candidate:        (id, sid)   => api.get(`/company-portal/hiring-requests/${id}/candidates/${sid}`).then(r => r.data),
  reviewCandidate:  (id, sid, decision, comment = '') => api.post(`/company-portal/hiring-requests/${id}/candidates/${sid}/review`, { decision, comment }).then(r => r.data),
  candidateResumeBlob: (id, sid) => api.get(`/company-portal/hiring-requests/${id}/candidates/${sid}/resume`, { params: { disposition: 'attachment' }, responseType: 'blob' }).then(r => r.data),
  candidateResumeUrl:  (id, sid) => `${BASE}/company-portal/hiring-requests/${id}/candidates/${sid}/resume`,
  candidateComments:   (id, sid) => api.get(`/company-portal/hiring-requests/${id}/candidates/${sid}/comments`).then(r => r.data),
  postCandidateComment:(id, sid, body) => api.post(`/company-portal/hiring-requests/${id}/candidates/${sid}/comments`, { body }).then(r => r.data),

  // Interviews (Sprint 4)
  interviews:      (id)              => api.get(`/company-portal/hiring-requests/${id}/interviews`).then(r => r.data),
  interviewAction: (id, iid, data)  => api.post(`/company-portal/hiring-requests/${id}/interviews/${iid}/action`, data).then(r => r.data),
  interviewInvite: (id, iid)        => api.get(`/company-portal/hiring-requests/${id}/interviews/${iid}/invite`, { responseType: 'blob' }).then(r => r.data),

  // Offers (Sprint 4)
  offers:          (id)              => api.get(`/company-portal/hiring-requests/${id}/offers`).then(r => r.data),
  offerDecision:   (id, oid, decision, comment = '') => api.post(`/company-portal/hiring-requests/${id}/offers/${oid}/decision`, { decision, comment }).then(r => r.data),
  offerDownload:   (id, oid)        => api.get(`/company-portal/hiring-requests/${id}/offers/${oid}/download`, { responseType: 'blob' }).then(r => r.data),

  // Documents
  documents:      (id)          => api.get(`/company-portal/hiring-requests/${id}/documents`).then(r => r.data),
  uploadDocument: (id, type, file) => {
    const fd = new FormData(); fd.append('type', type); fd.append('file', file)
    return api.post(`/company-portal/hiring-requests/${id}/documents`, fd).then(r => r.data)
  },
  deleteDocument:   (id, docId) => api.delete(`/company-portal/hiring-requests/${id}/documents/${docId}`).then(r => r.data),
  documentBlob:     (id, docId) => api.get(`/company-portal/hiring-requests/${id}/documents/${docId}/download`, { responseType: 'blob' }).then(r => r.data),
}

export default companyPortalApi
