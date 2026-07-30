// Workspace settings — /api/settings/* (admin-only)
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

export const settingsApi = {
  general: {
    get: () => api.get('/settings/general').then(r => r.data).catch(handleErr),
    update: (data) => api.put('/settings/general', data).then(r => r.data).catch(handleErr),
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
