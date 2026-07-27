// Workspace settings — /api/settings/* (admin-only)
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

export const settingsApi = {
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
