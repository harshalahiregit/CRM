/**
 * Public candidate Offer Letter portal API — no auth token (scoped by {token}).
 */
import axios from 'axios'

const BASE = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'
const api = axios.create({ baseURL: BASE })

export const offerPortalApi = {
  get:     (token) => api.get(`/offer/${token}`).then(r => r.data),
  accept:  (token, payload = {}) => api.post(`/offer/${token}/accept`, { agreed: true, ...payload }).then(r => r.data),
  decline: (token, reason) => api.post(`/offer/${token}/decline`, { reason }).then(r => r.data),
  clarify: (token, message) => api.post(`/offer/${token}/clarify`, { message }).then(r => r.data),
  letterUrl: (token) => `${BASE}/offer/${token}/letter`,
  task: (token, key, value, file) => {
    const fd = new FormData()
    fd.append('key', key)
    if (value != null) fd.append('value', value)
    if (file) fd.append('file', file)
    return api.post(`/offer/${token}/tasks`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data)
  },
}

export default offerPortalApi
