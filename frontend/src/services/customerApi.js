// Customer / Clients module — /api/customers/*
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.message || err?.response?.data?.error || 'Something went wrong'
  throw new Error(msg)
}

export const customerApi = {
  // Clients
  list: (params = {}) =>
    api.get('/customers', { params }).then(r => r.data).catch(handleErr),
  get: (id) =>
    api.get(`/customers/${id}`).then(r => r.data).catch(handleErr),
  create: (data) =>
    api.post('/customers', data).then(r => r.data).catch(handleErr),
  update: (id, data) =>
    api.put(`/customers/${id}`, data).then(r => r.data).catch(handleErr),
  remove: (id) =>
    api.delete(`/customers/${id}`).then(r => r.data).catch(handleErr),

  summary: () =>
    api.get('/customers/summary').then(r => r.data).catch(handleErr),
  taxSummary: (id) =>
    api.get(`/customers/${id}/tax`).then(r => r.data).catch(handleErr),
  tickets: (id) =>
    api.get(`/customers/${id}/tickets`).then(r => r.data).catch(handleErr),

  // Import / Export
  import: (file, simulate = false) => {
    const fd = new FormData()
    fd.append('file', file)
    if (simulate) fd.append('simulate', '1')
    return api.post('/customers/import', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data).catch(handleErr)
  },
  exportUrl: (format = 'csv', search = '') =>
    `${api.defaults.baseURL}/customers/export?format=${format}${search ? `&search=${encodeURIComponent(search)}` : ''}`,

  // Groups
  groups: {
    list: () => api.get('/customers/groups').then(r => r.data).catch(handleErr),
    create: (data) => api.post('/customers/groups', data).then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/customers/groups/${id}`, data).then(r => r.data).catch(handleErr),
    remove: (id) => api.delete(`/customers/groups/${id}`).then(r => r.data).catch(handleErr),
  },

  // Custom field definitions
  customFields: {
    list: (fieldTo = 'customers') =>
      api.get('/customers/custom-fields', { params: { field_to: fieldTo } }).then(r => r.data).catch(handleErr),
    create: (data) => api.post('/customers/custom-fields', data).then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/customers/custom-fields/${id}`, data).then(r => r.data).catch(handleErr),
    remove: (id) => api.delete(`/customers/custom-fields/${id}`).then(r => r.data).catch(handleErr),
  },
}

// Flat list helper for dropdowns in other modules (Sales invoices/estimates).
// Returns [{id, company, ...}] — replaces the old hardcoded salesApi.clients: [].
export const fetchClientOptions = () =>
  api.get('/customers', { params: { per_page: 500 } })
    .then(r => (r.data?.data ?? r.data ?? []).map(c => ({ id: c.id, name: c.company, company: c.company, email: c.primary_contact?.email })))
    .catch(() => [])

export default customerApi
