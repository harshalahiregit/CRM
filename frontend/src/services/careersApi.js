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
}

export default careersApi
