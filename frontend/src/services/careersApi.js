/**
 * Public Career Portal API — no auth token (these endpoints are public and
 * tenant-scoped by the {slug} in the path).
 */
import axios from 'axios'

const BASE = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'
const api = axios.create({ baseURL: BASE })

export const careersApi = {
  tenant: (slug)              => api.get(`/careers/${slug}`).then(r => r.data),
  jobs:   (slug, params = {}) => api.get(`/careers/${slug}/jobs`, { params }).then(r => r.data),
  job:    (slug, id)          => api.get(`/careers/${slug}/jobs/${id}`).then(r => r.data),
  apply:  (slug, id, formData) => api.post(`/careers/${slug}/jobs/${id}/apply`, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  }).then(r => r.data),
  // Application tracking (public — matched by the applicant's own email/phone)
  status:       (slug, id, payload) => api.post(`/careers/${slug}/jobs/${id}/status`, payload).then(r => r.data),
  respondOffer: (slug, id, payload) => api.post(`/careers/${slug}/jobs/${id}/offer/respond`, payload).then(r => r.data),
  offerLetterUrl: (slug, id, email) => `${BASE}/careers/${slug}/jobs/${id}/offer/letter?email=${encodeURIComponent(email)}`,
}

export default careersApi
