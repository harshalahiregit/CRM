/**
 * Kickoff meeting API — the shared scheduling engine.
 *
 * Generic by design (mirrors App\Services\Shared on the backend): meetings
 * attach to any allowlisted subject, so this is not a TPV service even though
 * TPV is currently its only consumer with a nav entry.
 */
import api from '@/lib/api'

const upload = (url, formData) =>
  api.post(url, formData, { headers: { 'Content-Type': undefined } }).then(r => r.data)

export const kickoffApi = {
  list:  (params = {}) => api.get('/kickoff/meetings', { params }).then(r => r.data),
  stats: ()            => api.get('/kickoff/meetings/stats').then(r => r.data),
  get:   (id)          => api.get(`/kickoff/meetings/${id}`).then(r => r.data),
  schedule: (data)     => api.post('/kickoff/meetings', data).then(r => r.data),
  update:   (id, data) => api.put(`/kickoff/meetings/${id}`, data).then(r => r.data),
  transition: (id, data) => api.post(`/kickoff/meetings/${id}/transition`, data).then(r => r.data),
  delete:   (id)       => api.delete(`/kickoff/meetings/${id}`).then(r => r.data),

  // MoM is an uploaded document — no PDF generation dependency (yet).
  uploadMom: (id, file) => {
    const fd = new FormData()
    fd.append('mom', file)
    return upload(`/kickoff/meetings/${id}/mom`, fd)
  },

  // Returns { meeting, ack_token } — the token is disclosed only here. The page
  // composes the link from window.location.origin, as the badge QR does.
  publish: (id) => api.post(`/kickoff/meetings/${id}/publish`).then(r => r.data),

  // Subject pickers — thin wrappers over shared endpoints, mirroring how each
  // module wraps /vendors itself rather than importing another module's service.
  subjects: {
    vendorContacts: (vendorId) => api.get(`/vendors/${vendorId}`).then(r => r.data),
  },
}

export default kickoffApi
