/**
 * Public candidate Onboarding portal API — no auth token (scoped by the {token}).
 */
import axios from 'axios'

const BASE = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'
const api = axios.create({ baseURL: BASE })

export const onboardingApi = {
  get: (token) => api.get(`/onboarding/${token}`).then(r => r.data),
  submit: (token, submission, documents = {}) => {
    const fd = new FormData()
    fd.append('submission', JSON.stringify(submission || {}))
    Object.entries(documents).forEach(([type, file]) => { if (file) fd.append(`documents[${type}]`, file) })
    return api.post(`/onboarding/${token}/submit`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  uploadDocument: (token, type, file) => {
    const fd = new FormData()
    fd.append('type', type)
    fd.append('document', file)
    return api.post(`/onboarding/${token}/documents`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
}

export default onboardingApi
