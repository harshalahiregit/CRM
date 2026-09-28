// Workspace settings — /api/settings/* (admin-only)
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

export const settingsApi = {
  general: {
    get: () => api.get('/settings/general').then(r => r.data).catch(handleErr),
    update: (data) => api.put('/settings/general', data).then(r => r.data).catch(handleErr),
  },
  // ST2 — global recycle bin: list soft-deleted records + restore one.
  recycleBin: {
    // Filtering is server-side: with sixty-odd registered types, fetching
    // everything to filter in the browser would mean sixty queries per keystroke.
    list:    (params = {}) => api.get('/settings/recycle-bin', { params }).then(r => r.data).catch(handleErr),
    restore: (type, id) => api.post('/settings/recycle-bin/restore', { type, id }).then(r => r.data).catch(handleErr),
  },
  // Roles & Departments — maintained from Settings instead of by a developer.
  // `roles` retired: /settings/roles managed a second staff-role catalogue
  // (access_roles) beside the live one. staff_roles owns the vocabulary,
  // permissions and scope together; Staff Management maintains it at
  // /admin/roles, which is what RolesModal.jsx already calls.
  departments: {
    list:   () => api.get('/settings/departments').then(r => r.data?.data ?? r.data).catch(handleErr),
    create: (data) => api.post('/settings/departments', data).then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/settings/departments/${id}`, data).then(r => r.data).catch(handleErr),
    remove: (id) => api.delete(`/settings/departments/${id}`).then(r => r.data).catch(handleErr),
  },
  // Document Numbering Engine — configuration, preview, reset, validation.
  numbering: {
    list:     () => api.get('/settings/numbering').then(r => r.data).catch(handleErr),
    get:      (type) => api.get(`/settings/numbering/${type}`).then(r => r.data).catch(handleErr),
    update:   (type, data) => api.put(`/settings/numbering/${type}`, data).then(r => r.data).catch(handleErr),
    // Preview never consumes a number. Pass `config` to preview an unsaved draft.
    preview:  (type, body = {}) => api.post(`/settings/numbering/${type}/preview`, body).then(r => r.data).catch(handleErr),
    reset:    (type, startingNumber = null) =>
      api.post(`/settings/numbering/${type}/reset`, startingNumber ? { starting_number: startingNumber } : {}).then(r => r.data).catch(handleErr),
    validate: (data) => api.post('/settings/numbering/validate', data).then(r => r.data).catch(handleErr),
  },
  // Email Templates — content only; sending stays with the SMTP settings above.
  emailTemplates: {
    list:     (params = {}) => api.get('/settings/email-templates', { params }).then(r => r.data).catch(handleErr),
    get:      (key) => api.get(`/settings/email-templates/${key}`).then(r => r.data).catch(handleErr),
    update:   (key, data) => api.put(`/settings/email-templates/${key}`, data).then(r => r.data).catch(handleErr),
    // Preview renders with sample data — it never sends an email.
    preview:  (key, draft = {}) => api.post(`/settings/email-templates/${key}/preview`, { draft }).then(r => r.data).catch(handleErr),
    restore:  (key) => api.post(`/settings/email-templates/${key}/restore`).then(r => r.data).catch(handleErr),
    validate: (data) => api.post('/settings/email-templates/validate', data).then(r => r.data).catch(handleErr),
  },
  // Generic settings groups — Upload / Security / Notification preferences.
  group: {
    get: (group) => api.get(`/settings/group/${group}`).then(r => r.data).catch(handleErr),
    update: (group, values) => api.put(`/settings/group/${group}`, { values }).then(r => r.data).catch(handleErr),
  },
  mail: {
    get: () => api.get('/settings/mail').then(r => r.data).catch(handleErr),
    update: (data) => api.put('/settings/mail', data).then(r => r.data).catch(handleErr),
    test: (to) => api.post('/settings/mail/test', { to }).then(r => r.data).catch(handleErr),
  },
  whatsapp: {
    get:    ()     => api.get('/settings/whatsapp').then(r => r.data).catch(handleErr),
    update: (data) => api.put('/settings/whatsapp', data).then(r => r.data).catch(handleErr),
    // A read against Meta — proves the token works without spending a message.
    verify: ()     => api.post('/settings/whatsapp/verify').then(r => r.data).catch(handleErr),
    test:   (to)   => api.post('/settings/whatsapp/test', { to }).then(r => r.data).catch(handleErr),
  },
  company: {
    get: () => api.get('/settings/company').then(r => r.data).catch(handleErr),
    update: (data) => api.put('/settings/company', data).then(r => r.data).catch(handleErr),
  },
  staffEmails: {
    list: () => api.get('/settings/staff-emails').then(r => r.data).catch(handleErr),
    toggle: (userId) => api.patch(`/settings/staff-emails/${userId}/toggle`).then(r => r.data).catch(handleErr),
  },
}

export default settingsApi
