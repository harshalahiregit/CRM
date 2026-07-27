// Items catalog — /api/sales/items/*
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

export const itemApi = {
  list: (params = {}) =>
    api.get('/sales/items', { params }).then(r => r.data).catch(handleErr),

  get: (id) =>
    api.get(`/sales/items/${id}`).then(r => r.data).catch(handleErr),

  create: (data) =>
    api.post('/sales/items', data).then(r => r.data).catch(handleErr),

  update: (id, data) =>
    api.put(`/sales/items/${id}`, data).then(r => r.data).catch(handleErr),

  delete: (id) =>
    api.delete(`/sales/items/${id}`).then(r => r.data).catch(handleErr),
}

export default itemApi
