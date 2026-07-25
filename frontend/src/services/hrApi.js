/**
 * HR Module API Service
 * All HTTP calls to /api/hr/* endpoints
 */

import axios from 'axios'
import { getToken, clearAuth } from '@/lib/authStorage'

const BASE = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'

// Create axios instance with auth token
const api = axios.create({ baseURL: BASE })

api.interceptors.request.use(cfg => {
  const token = getToken() // reads local- or sessionStorage (remember-me aware)
  if (token) cfg.headers.Authorization = `Bearer ${token}`
  return cfg
})

api.interceptors.response.use(
  res => res,
  err => {
    if (err.response?.status === 401) {
      clearAuth()
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

  // ── Manpower Requests ───────────────────────────────────────────────
  manpower: {
    list:         (params = {}) => api.get('/hr/manpower-requests', { params }).then(r => r.data), // Backend returns array directly
    get:          (id)          => api.get(`/hr/manpower-requests/${id}`).then(r => r.data),
    create:       (data)        => api.post('/hr/manpower-requests', data).then(r => r.data),
    updateStatus: (id, payload) => api.patch(`/hr/manpower-requests/${id}/status`, typeof payload === 'string' ? { status: payload } : payload).then(r => r.data),
    delete:       (id)          => api.delete(`/hr/manpower-requests/${id}`).then(r => r.data),
  },

  // ── Job Postings ────────────────────────────────────────────────────
  jobs: {
    list:         (params = {}) => api.get('/hr/jobs', { params }).then(r => r.data),
    get:          (id)          => api.get(`/hr/jobs/${id}`).then(r => r.data),
    create:       (data)        => api.post('/hr/jobs', data).then(r => r.data),
    update:       (id, data)    => api.put(`/hr/jobs/${id}`, data).then(r => r.data),
    updateStatus: (id, status)  => api.patch(`/hr/jobs/${id}/status`, { status }).then(r => r.data),
    updateExternalId: (id, platform, external_id) => api.patch(`/hr/jobs/${id}/external-id`, { platform, external_id }).then(r => r.data),
    delete:       (id)          => api.delete(`/hr/jobs/${id}`).then(r => r.data),
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
  },


  // ── Interviews ──────────────────────────────────────────────────────
  interviews: {
    list:             (params = {}) => api.get('/hr/interviews', { params }).then(r => r.data),
    schedule:         (data)        => api.post('/hr/interviews', data).then(r => r.data),
    get:              (id)          => api.get(`/hr/interviews/${id}`).then(r => r.data),
    recordFeedback:   (id, data)    => api.patch(`/hr/interviews/${id}/feedback`, data).then(r => r.data),
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
