// Web-to-Lead forms — admin CRUD (/api/sales/web-to-lead-forms) + public
// submission (/api/public/web-to-lead). The public calls MUST NOT send an
// auth token, so they use a bare axios instance (not @/lib/api).
import axios from 'axios'
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

const BASE = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'
const publicClient = axios.create({ baseURL: BASE, headers: { 'Content-Type': 'application/json', Accept: 'application/json' } })

export const webToLeadApi = {
  // ── Admin ──
  list: () =>
    api.get('/sales/web-to-lead-forms').then(r => r.data).catch(handleErr),
  get: (id) =>
    api.get(`/sales/web-to-lead-forms/${id}`).then(r => r.data).catch(handleErr),
  create: (data) =>
    api.post('/sales/web-to-lead-forms', data).then(r => r.data).catch(handleErr),
  update: (id, data) =>
    api.put(`/sales/web-to-lead-forms/${id}`, data).then(r => r.data).catch(handleErr),
  delete: (id) =>
    api.delete(`/sales/web-to-lead-forms/${id}`).then(r => r.data).catch(handleErr),

  // ── Public (no auth) ──
  publicForm: (formKey) =>
    publicClient.get(`/public/web-to-lead/${formKey}`).then(r => r.data).catch(handleErr),
  publicSubmit: (formKey, data) =>
    publicClient.post(`/public/web-to-lead/${formKey}`, data).then(r => r.data).catch(handleErr),
}

export default webToLeadApi
