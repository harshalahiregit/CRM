// Customer / Clients module — /api/customers/*
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

// CRUD factory for simple per-customer record resources (/customers/{id}/{resource}).
const crud = (resource) => ({
  list:   (id) => api.get(`/customers/${id}/${resource}`).then(r => r.data).catch(handleErr),
  create: (id, data) => api.post(`/customers/${id}/${resource}`, data).then(r => r.data).catch(handleErr),
  update: (id, recId, data) => api.put(`/customers/${id}/${resource}/${recId}`, data).then(r => r.data).catch(handleErr),
  remove: (id, recId) => api.delete(`/customers/${id}/${resource}/${recId}`).then(r => r.data).catch(handleErr),
})

export const customerApi = {
  // Customer 360 overview — live counts from the modules that own the data.
  overview: (id) => api.get(`/customers/${id}/overview`).then(r => r.data).catch(handleErr),

  // Simple per-customer record tabs
  contracts:     crud('contracts'),
  expenses:      crud('expenses'),
  subscriptions: crud('subscriptions'),
  preAlerts:     crud('pre-alerts'),
  packages:      crud('packages'),
  shipments:     crud('shipments'),

  // Clients
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
  toggleActive: (id) =>
    api.patch(`/customers/${id}/active`).then(r => r.data).catch(handleErr),

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

  // Customer admins (account managers) — user_ids array order = fallback order
  admins:     (id) => api.get(`/customers/${id}/admins`).then(r => r.data).catch(handleErr),
  syncAdmins: (id, userIds) => api.put(`/customers/${id}/admins`, { user_ids: userIds }).then(r => r.data).catch(handleErr),
  assignableStaff: () => api.get('/customers/assignable-staff').then(r => r.data).catch(handleErr),
  // Tenant-editable option lists for classification, contact role and note type.
  options: () => api.get('/customers/options').then(r => r.data).catch(handleErr),

  // Contacts (per-customer)
  contacts: {
    list:   (id) => api.get(`/customers/${id}/contacts`).then(r => r.data).catch(handleErr),
    create: (id, data) => api.post(`/customers/${id}/contacts`, data).then(r => r.data).catch(handleErr),
    update: (id, contactId, data) => api.put(`/customers/${id}/contacts/${contactId}`, data).then(r => r.data).catch(handleErr),
    toggleActive: (id, contactId) => api.patch(`/customers/${id}/contacts/${contactId}/active`).then(r => r.data).catch(handleErr),
    remove: (id, contactId) => api.delete(`/customers/${id}/contacts/${contactId}`).then(r => r.data).catch(handleErr),
  },

  // Notes
  expenseCategories: {
    list:   () => api.get('/customers/expense-categories').then(r => r.data).catch(handleErr),
    create: (data) => api.post('/customers/expense-categories', data).then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/customers/expense-categories/${id}`, data).then(r => r.data).catch(handleErr),
    remove: (id) => api.delete(`/customers/expense-categories/${id}`).then(r => r.data).catch(handleErr),
  },
  projects: {
    list: () => api.get('/customers/projects-stub').then(r => r.data).catch(handleErr),
  },
  // Names for the Parent Company picker: existing companies + parent names
  // already in use. `exclude` keeps a company off its own list when editing.
  parentCompanies: (exclude = null) =>
    api.get('/customers/parent-companies', { params: exclude ? { exclude } : {} })
      .then(r => r.data).catch(handleErr),
  notes: {
    list:   (id) => api.get(`/customers/${id}/notes`).then(r => r.data).catch(handleErr),
    create: (id, data) => api.post(`/customers/${id}/notes`, data).then(r => r.data).catch(handleErr),
    update: (id, noteId, data) => api.put(`/customers/${id}/notes/${noteId}`, data).then(r => r.data).catch(handleErr),
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
    reveal: (id, entryId) => api.post(`/customers/${id}/vault/${entryId}/reveal`).then(r => r.data).catch(handleErr),
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
  sampleUrl: (format = 'csv') =>
    `${api.defaults.baseURL}/customers/import/sample?format=${format}`,

  /**
   * Group-wise reports. Downloads go through axios with responseType blob so the
   * Bearer token is sent — a bare <a href> would hit the API unauthenticated.
   */
  groupReports: {
    show:       (params = {}) => api.get('/customers/group-reports', { params }).then(r => r.data).catch(handleErr),
    comparison: (params = {}) => api.get('/customers/group-reports/comparison', { params }).then(r => r.data).catch(handleErr),
    download: async (kind, params = {}) => {
      const path = kind === 'pdf' ? '/customers/group-reports/pdf' : '/customers/group-reports/export'
      const res = await api.get(path, { params, responseType: 'blob' })
      const cd = res.headers?.['content-disposition'] || ''
      const guess = cd.match(/filename="?([^";]+)"?/)?.[1]
      const name = guess || `group-report.${kind === 'pdf' ? 'pdf' : (params.format || 'csv')}`
      const url = URL.createObjectURL(res.data)
      const a = document.createElement('a')
      a.href = url; a.download = name; document.body.appendChild(a); a.click()
      a.remove(); URL.revokeObjectURL(url)
    },
  },

  // Groups
  groups: {
    list: () => api.get('/customers/groups').then(r => r.data).catch(handleErr),
    create: (data) => api.post('/customers/groups', data).then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/customers/groups/${id}`, data).then(r => r.data).catch(handleErr),
    remove: (id) => api.delete(`/customers/groups/${id}`).then(r => r.data).catch(handleErr),
  },

  // Custom field definitions
  // Map location picker
  geocode: (q) => api.get('/customers/geocode', { params: { q } }).then(r => r.data).catch(handleErr),
  updateLocation: (id, data) => api.put(`/customers/${id}/location`, data).then(r => r.data).catch(handleErr),

  customFields: {
    list: (fieldTo = 'customers') =>
      api.get('/customers/custom-fields', { params: { field_to: fieldTo } }).then(r => r.data).catch(handleErr),
    create: (data) => api.post('/customers/custom-fields', data).then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/customers/custom-fields/${id}`, data).then(r => r.data).catch(handleErr),
    remove: (id) => api.delete(`/customers/custom-fields/${id}`).then(r => r.data).catch(handleErr),
    reorder: (ids) => api.post('/customers/custom-fields/reorder', { ids }).then(r => r.data).catch(handleErr),
  },
}

// Flat list helper for dropdowns in other modules (Sales invoices/estimates).
// Returns [{id, company, ...}] — replaces the old hardcoded salesApi.clients: [].
export const fetchClientOptions = () =>
  api.get('/customers', { params: { per_page: 500 } })
    .then(r => (r.data?.data ?? r.data ?? []).map(c => ({ id: c.id, name: c.company, company: c.company, email: c.primary_contact?.email })))
    .catch(() => [])

export default customerApi
