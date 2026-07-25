/**
 * Vendor Self-Service Portal API.
 *
 * Uses the shared axios client on purpose: a vendor has a real login, so the
 * bearer token + 401→/auth/login behaviour is exactly right here (unlike the
 * public gate-scan / checklist-fill clients, which are bare because their users
 * have no login). Every endpoint resolves the vendor from the token server-side
 * — there is no vendor id to pass.
 */
import api from '@/lib/api'

const upload = (url, formData) =>
  api.post(url, formData, { headers: { 'Content-Type': undefined } }).then(r => r.data)

export const portalApi = {
  me:         () => api.get('/portal/me').then(r => r.data),
  onboarding: () => api.get('/portal/onboarding').then(r => r.data),

  orders:  () => api.get('/portal/orders').then(r => r.data),
  order:   (id) => api.get(`/portal/orders/${id}`).then(r => r.data),
  invoices: () => api.get('/portal/invoices').then(r => r.data),
  invoice: (id) => api.get(`/portal/invoices/${id}`).then(r => r.data),

  // ── Compliance documents — the portal's write actions ──────────────
  documents: () => api.get('/portal/documents').then(r => r.data),
  uploadDocument: (type, file) => {
    const fd = new FormData()
    fd.append('type', type)
    fd.append('file', file)
    return upload('/portal/documents', fd)
  },
  resubmitDocument: (docId, file) => {
    const fd = new FormData()
    fd.append('file', file)
    return upload(`/portal/documents/${docId}/resubmit`, fd)
  },
  // Download streams a file — fetch as a blob so it can be saved.
  downloadDocument: (docId) => api.get(`/portal/documents/${docId}/download`, { responseType: 'blob' }).then(r => r.data),
}

export default portalApi
