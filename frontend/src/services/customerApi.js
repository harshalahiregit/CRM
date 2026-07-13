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

  // Profile read tabs (loop-ins + rollups)
  taxSummary: (id) => api.get(`/customers/${id}/tax`).then(r => r.data).catch(handleErr),
  tickets:    (id) => api.get(`/customers/${id}/tickets`).then(r => r.data).catch(handleErr),
  invoices:   (id) => api.get(`/customers/${id}/invoices`).then(r => r.data).catch(handleErr),
  estimates:  (id) => api.get(`/customers/${id}/estimates`).then(r => r.data).catch(handleErr),
  proposals:  (id) => api.get(`/customers/${id}/proposals`).then(r => r.data).catch(handleErr),
  creditNotes:(id) => api.get(`/customers/${id}/credit-notes`).then(r => r.data).catch(handleErr),
  payments:   (id) => api.get(`/customers/${id}/payments`).then(r => r.data).catch(handleErr),
  statement:  (id) => api.get(`/customers/${id}/statement`).then(r => r.data).catch(handleErr),

  // Customer admins (account managers)
  admins:     (id) => api.get(`/customers/${id}/admins`).then(r => r.data).catch(handleErr),
  syncAdmins: (id, userIds) => api.put(`/customers/${id}/admins`, { user_ids: userIds }).then(r => r.data).catch(handleErr),

  // Contacts (per-customer)
  contacts: {
    list:   (id) => api.get(`/customers/${id}/contacts`).then(r => r.data).catch(handleErr),
    create: (id, data) => api.post(`/customers/${id}/contacts`, data).then(r => r.data).catch(handleErr),
    update: (id, contactId, data) => api.put(`/customers/${id}/contacts/${contactId}`, data).then(r => r.data).catch(handleErr),
    toggleActive: (id, contactId) => api.patch(`/customers/${id}/contacts/${contactId}/active`).then(r => r.data).catch(handleErr),
    remove: (id, contactId) => api.delete(`/customers/${id}/contacts/${contactId}`).then(r => r.data).catch(handleErr),
  },

  // Notes
  notes: {
    list:   (id) => api.get(`/customers/${id}/notes`).then(r => r.data).catch(handleErr),
    create: (id, data) => api.post(`/customers/${id}/notes`, data).then(r => r.data).catch(handleErr),
    remove: (id, noteId) => api.delete(`/customers/${id}/notes/${noteId}`).then(r => r.data).catch(handleErr),
  },

  // Reminders
  reminders: {
    list:   (id) => api.get(`/customers/${id}/reminders`).then(r => r.data).catch(handleErr),
    create: (id, data) => api.post(`/customers/${id}/reminders`, data).then(r => r.data).catch(handleErr),
    remove: (id, remId) => api.delete(`/customers/${id}/reminders/${remId}`).then(r => r.data).catch(handleErr),
  },

  // Vault (credentials)
  vault: {
    list:   (id) => api.get(`/customers/${id}/vault`).then(r => r.data).catch(handleErr),
    create: (id, data) => api.post(`/customers/${id}/vault`, data).then(r => r.data).catch(handleErr),
    update: (id, entryId, data) => api.put(`/customers/${id}/vault/${entryId}`, data).then(r => r.data).catch(handleErr),
    reveal: (id, entryId) => api.get(`/customers/${id}/vault/${entryId}/reveal`).then(r => r.data).catch(handleErr),
    remove: (id, entryId) => api.delete(`/customers/${id}/vault/${entryId}`).then(r => r.data).catch(handleErr),
  },

  // Attachments (a.k.a. "Files" in the legacy CRM)
  attachments: {
    list:   (id) => api.get(`/customers/${id}/attachments`).then(r => r.data).catch(handleErr),
    upload: (id, file) => {
      const fd = new FormData()
      fd.append('file', file)
      return api.post(`/customers/${id}/attachments`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data).catch(handleErr)
    },
    remove: (id, attId) => api.delete(`/customers/${id}/attachments/${attId}`).then(r => r.data).catch(handleErr),
  },

  // Address Book (customer-scoped shipping addresses)
  addresses: {
    list:   (id) => api.get(`/customers/${id}/addresses`).then(r => r.data).catch(handleErr),
    create: (id, data) => api.post(`/customers/${id}/addresses`, data).then(r => r.data).catch(handleErr),
    update: (id, addrId, data) => api.put(`/customers/${id}/addresses/${addrId}`, data).then(r => r.data).catch(handleErr),
    remove: (id, addrId) => api.delete(`/customers/${id}/addresses/${addrId}`).then(r => r.data).catch(handleErr),
  },

  // Recipients (people/companies goods are shipped to for this customer)
  recipients: {
    list:   (id) => api.get(`/customers/${id}/recipients`).then(r => r.data).catch(handleErr),
    create: (id, data) => api.post(`/customers/${id}/recipients`, data).then(r => r.data).catch(handleErr),
    update: (id, recId, data) => api.put(`/customers/${id}/recipients/${recId}`, data).then(r => r.data).catch(handleErr),
    remove: (id, recId) => api.delete(`/customers/${id}/recipients/${recId}`).then(r => r.data).catch(handleErr),
  },

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
