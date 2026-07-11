/**
 * HR Module API Service
 * All HTTP calls to /api/hr/* endpoints
 */

import axios from 'axios'

const BASE = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'

// Create axios instance with auth token
const api = axios.create({ baseURL: BASE })

api.interceptors.request.use(cfg => {
  const token = localStorage.getItem('crm_token') // ✅ FIXED: was 'auth_token'
  if (token) cfg.headers.Authorization = `Bearer ${token}`
  return cfg
})

api.interceptors.response.use(
  res => res,
  err => {
    if (err.response?.status === 401) {
      localStorage.removeItem('crm_token') // ✅ FIXED: was 'auth_token'
      localStorage.removeItem('crm_user')
      localStorage.removeItem('crm_tenant')
      window.location.href = '/auth/login'
    }
    return Promise.reject(err)
  }
)

// ── Dashboard ─────────────────────────────────────────────────────────
export const hrApi = {
  dashboard: {
    get: () => api.get('/hr/dashboard').then(r => r.data),
  },

  // ── Manpower Requests (L1/L2 approval → HR queue → JD → job posting) ──
  manpower: {
    list:            (params = {}) => api.get('/hr/manpower-requests', { params }).then(r => r.data),
    queue:           (params = {}) => api.get('/hr/manpower-requests/queue', { params }).then(r => r.data),
    stats:           ()            => api.get('/hr/manpower-requests/stats').then(r => r.data),
    pendingApprovals:()            => api.get('/hr/manpower-requests/pending-approvals').then(r => r.data),
    get:             (id)          => api.get(`/hr/manpower-requests/${id}`).then(r => r.data),
    create:          (data)        => api.post('/hr/manpower-requests', data).then(r => r.data),
    update:          (id, data)    => api.put(`/hr/manpower-requests/${id}`, data).then(r => r.data),
    delete:          (id)          => api.delete(`/hr/manpower-requests/${id}`).then(r => r.data),
    // Approval workflow
    submit:          (id)              => api.post(`/hr/manpower-requests/${id}/submit`).then(r => r.data),
    approveL1:       (id, remarks = '') => api.post(`/hr/manpower-requests/${id}/approve-l1`, { remarks }).then(r => r.data),
    rejectL1:        (id, remarks)      => api.post(`/hr/manpower-requests/${id}/reject-l1`, { remarks }).then(r => r.data),
    approveL2:       (id, remarks = '') => api.post(`/hr/manpower-requests/${id}/approve-l2`, { remarks }).then(r => r.data),
    rejectL2:        (id, remarks)      => api.post(`/hr/manpower-requests/${id}/reject-l2`, { remarks }).then(r => r.data),
    sendBack:        (id, remarks)      => api.post(`/hr/manpower-requests/${id}/send-back`, { remarks }).then(r => r.data),
    // HR queue actions
    convertToJd:     (id, data = {})    => api.post(`/hr/manpower-requests/${id}/convert-to-jd`, data).then(r => r.data),
    publish:         (id)               => api.post(`/hr/manpower-requests/${id}/publish`).then(r => r.data),
    startHiring:     (id)               => api.post(`/hr/manpower-requests/${id}/start-hiring`).then(r => r.data),
    close:           (id, remarks = '') => api.post(`/hr/manpower-requests/${id}/close`, { remarks }).then(r => r.data),
    assignManager:   (id, manager_id)   => api.patch(`/hr/manpower-requests/${id}/assign-manager`, { manager_id }).then(r => r.data),
  },

  // ── Job Postings — Recruitment Workspace ────────────────────────────
  jobs: {
    list:         (params = {}) => api.get('/hr/jobs', { params }).then(r => r.data),
    stats:        ()            => api.get('/hr/jobs/stats').then(r => r.data),
    bulk:         (action, ids) => api.post('/hr/jobs/bulk', { action, ids }).then(r => r.data),
    get:          (id)          => api.get(`/hr/jobs/${id}`).then(r => r.data),
    create:       (data)        => api.post('/hr/jobs', data).then(r => r.data),
    update:       (id, data)    => api.put(`/hr/jobs/${id}`, data).then(r => r.data),
    updateStatus: (id, status)  => api.patch(`/hr/jobs/${id}/status`, { status }).then(r => r.data),
    updateExternalId: (id, platform, external_id) => api.patch(`/hr/jobs/${id}/external-id`, { platform, external_id }).then(r => r.data),
    delete:       (id)          => api.delete(`/hr/jobs/${id}`).then(r => r.data),
    // Lifecycle actions
    publish:      (id)          => api.post(`/hr/jobs/${id}/publish`).then(r => r.data),
    unpublish:    (id)          => api.post(`/hr/jobs/${id}/unpublish`).then(r => r.data),
    pause:        (id)          => api.post(`/hr/jobs/${id}/pause`).then(r => r.data),
    close:        (id, remarks) => api.post(`/hr/jobs/${id}/close`, { remarks }).then(r => r.data),
    cancel:       (id, remarks) => api.post(`/hr/jobs/${id}/cancel`, { remarks }).then(r => r.data),
    duplicate:    (id)          => api.post(`/hr/jobs/${id}/duplicate`).then(r => r.data),
    // Distribution channels (Career Portal + future LinkedIn/Naukri/Indeed/TrulyTalents)
    channels:        ()             => api.get('/hr/jobs/channels').then(r => r.data),
    publishTo:       (id, channel)  => api.post(`/hr/jobs/${id}/publish-to`, { channel }).then(r => r.data),
    publishChannels: (id, channels) => api.post(`/hr/jobs/${id}/publish-channels`, { channels }).then(r => r.data),
    unpublishFrom:   (id, channel)  => api.delete(`/hr/jobs/${id}/publish-to/${channel}`).then(r => r.data),
  },

  // ── Candidates ──────────────────────────────────────────────────────
  candidates: {
    list:          (params = {}) => api.get('/hr/candidates', { params }).then(r => r.data),
    get:           (id)          => api.get(`/hr/candidates/${id}`).then(r => r.data),
    create:        (data)        => api.post('/hr/candidates', data).then(r => r.data),
    update:        (id, data)    => api.put(`/hr/candidates/${id}`, data).then(r => r.data),
    updateStage:   (id, stage)   => api.patch(`/hr/candidates/${id}/stage`, { stage }).then(r => r.data),
    updateDecision:(id, decision)=> api.patch(`/hr/candidates/${id}/decision`, { final_decision: decision }).then(r => r.data),
    delete:        (id)          => api.delete(`/hr/candidates/${id}`).then(r => r.data),
    linkedinParse: (url)         => api.post('/hr/candidates/linkedin-parse', { url }).then(r => r.data),
    // Resume
    uploadResume:  (id, file)    => {
      const fd = new FormData()
      fd.append('resume', file)
      return api.post(`/hr/candidates/${id}/resume`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' }
      }).then(r => r.data)
    },
    resumeUrl:     (id)          => `${BASE}/hr/candidates/${id}/resume`,
    deleteResume:  (id)          => api.delete(`/hr/candidates/${id}/resume`).then(r => r.data),
    // Recruiter assignment
    recruiters:    ()            => api.get('/hr/candidates/recruiters').then(r => r.data),
    assign:        (id, recruiterId) => api.patch(`/hr/candidates/${id}/assign`, { recruiter_id: recruiterId }).then(r => r.data),
    // Collaborative notes thread
    notes: {
      list:   (id)          => api.get(`/hr/candidates/${id}/notes`).then(r => r.data),
      add:    (id, body)    => api.post(`/hr/candidates/${id}/notes`, { body }).then(r => r.data),
      delete: (id, noteId)  => api.delete(`/hr/candidates/${id}/notes/${noteId}`).then(r => r.data),
    },
    // Typed documents (beyond the primary resume)
    documents: {
      list:   (id)          => api.get(`/hr/candidates/${id}/documents`).then(r => r.data),
      upload: (id, file, type = 'other') => {
        const fd = new FormData()
        fd.append('document', file)
        fd.append('type', type)
        return api.post(`/hr/candidates/${id}/documents`, fd, {
          headers: { 'Content-Type': 'multipart/form-data' }
        }).then(r => r.data)
      },
      url:    (id, docId)   => `${BASE}/hr/candidates/${id}/documents/${docId}`,
      // Authenticated blob fetch (bearer header) — safe for private files.
      blob:   (id, docId)   => api.get(`/hr/candidates/${id}/documents/${docId}`, { responseType: 'blob' }).then(r => r.data),
      delete: (id, docId)   => api.delete(`/hr/candidates/${id}/documents/${docId}`).then(r => r.data),
    },
  },


  // ── Interviews ──────────────────────────────────────────────────────
  interviews: {
    list:             (params = {}) => api.get('/hr/interviews', { params }).then(r => r.data),
    stats:            ()            => api.get('/hr/interviews/stats').then(r => r.data),
    schedule:         (data)        => api.post('/hr/interviews', data).then(r => r.data),
    get:              (id)          => api.get(`/hr/interviews/${id}`).then(r => r.data),
    update:           (id, data)    => api.put(`/hr/interviews/${id}`, data).then(r => r.data),
    recordFeedback:   (id, data)    => api.patch(`/hr/interviews/${id}/feedback`, data).then(r => r.data),
    cancel:           (id, reason)  => api.patch(`/hr/interviews/${id}/cancel`, { reason }).then(r => r.data),
    generateMeetLink: (id)          => api.post(`/hr/interviews/${id}/meet-link`).then(r => r.data),
    sendNotification: (id, type)    => api.post(`/hr/interviews/${id}/notify`, { type }).then(r => r.data),
    delete:           (id)          => api.delete(`/hr/interviews/${id}`).then(r => r.data),
  },

  // ── Offers ──────────────────────────────────────────────────────────
  offers: {
    list:         (params = {}) => api.get('/hr/offers', { params }).then(r => r.data),
    get:          (id)          => api.get(`/hr/offers/${id}`).then(r => r.data),
    create:       (data)        => api.post('/hr/offers', data).then(r => r.data),
    send:         (id)          => api.patch(`/hr/offers/${id}/send`).then(r => r.data),
    updateStatus: (id, payload)  => api.patch(`/hr/offers/${id}/status`, typeof payload === 'string' ? { status: payload } : payload).then(r => r.data),
    delete:       (id)          => api.delete(`/hr/offers/${id}`).then(r => r.data),
  },

  // ── Onboarding ──────────────────────────────────────────────────────
  onboarding: {
    list:           (params={})   => api.get('/hr/onboarding', { params }).then(r => r.data),
    get:            (id)          => api.get(`/hr/onboarding/${id}`).then(r => r.data),
    start:          (data)        => api.post('/hr/onboarding', data).then(r => r.data),
    toggleStep:     (id, step)    => api.patch(`/hr/onboarding/${id}/step`, { step }).then(r => r.data),
    updateChecklist:(id, checklist)=> api.patch(`/hr/onboarding/${id}/step`, { checklist }).then(r => r.data),
    verify:         (id, data)    => api.patch(`/hr/onboarding/${id}/verify`, data).then(r => r.data),
    documentUrl:    (id, docId)   => `${BASE}/hr/onboarding/${id}/documents/${docId}`,
    documentBlob:   (id, docId)   => api.get(`/hr/onboarding/${id}/documents/${docId}`, { responseType: 'blob' }).then(r => r.data),
    delete:         (id)          => api.delete(`/hr/onboarding/${id}`).then(r => r.data),
  },

  // ── Employees ───────────────────────────────────────────────────────
  employees: {
    list:   (params = {}) => api.get('/hr/employees', { params }).then(r => r.data),
    stats:  ()            => api.get('/hr/employees/stats').then(r => r.data),
    get:    (id)          => api.get(`/hr/employees/${id}`).then(r => r.data),
    create: (data)        => api.post('/hr/employees', data).then(r => r.data),
    update: (id, data)    => api.put(`/hr/employees/${id}`, data).then(r => r.data),
    delete: (id)          => api.delete(`/hr/employees/${id}`).then(r => r.data),
  },
}

export default hrApi
