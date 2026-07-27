// Lead statuses, sources, goals, questionnaires — /api/sales/lead-*
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

export const leadSettingsApi = {
  statuses: {
    list: () => api.get('/sales/lead-statuses').then(r => r.data).catch(handleErr),
    create: (data) => api.post('/sales/lead-statuses', data).then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/sales/lead-statuses/${id}`, data).then(r => r.data).catch(handleErr),
    delete: (id) => api.delete(`/sales/lead-statuses/${id}`).then(r => r.data).catch(handleErr),
  },

  sources: {
    list: () => api.get('/sales/lead-sources').then(r => r.data).catch(handleErr),
    create: (data) => api.post('/sales/lead-sources', data).then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/sales/lead-sources/${id}`, data).then(r => r.data).catch(handleErr),
    delete: (id) => api.delete(`/sales/lead-sources/${id}`).then(r => r.data).catch(handleErr),
  },

  goals: {
    list: (params = {}) => api.get('/sales/lead-goals', { params }).then(r => r.data).catch(handleErr),
    create: (data) => api.post('/sales/lead-goals', data).then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/sales/lead-goals/${id}`, data).then(r => r.data).catch(handleErr),
    delete: (id) => api.delete(`/sales/lead-goals/${id}`).then(r => r.data).catch(handleErr),
  },

  questionnaires: {
    list: () => api.get('/sales/lead-questionnaires').then(r => r.data).catch(handleErr),
    create: (data) => api.post('/sales/lead-questionnaires', data).then(r => r.data).catch(handleErr),
    update: (id, data) => api.put(`/sales/lead-questionnaires/${id}`, data).then(r => r.data).catch(handleErr),
    delete: (id) => api.delete(`/sales/lead-questionnaires/${id}`).then(r => r.data).catch(handleErr),
  },
}

export default leadSettingsApi
