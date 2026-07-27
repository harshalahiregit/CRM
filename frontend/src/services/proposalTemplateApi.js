// Proposal Templates CRUD + clone — /api/sales/proposal-templates/*
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

export const proposalTemplateApi = {
  list: () =>
    api.get('/sales/proposal-templates').then(r => r.data).catch(handleErr),

  categories: () =>
    api.get('/sales/proposal-templates/categories').then(r => r.data).catch(handleErr),

  create: (data) =>
    api.post('/sales/proposal-templates', data).then(r => r.data).catch(handleErr),

  update: (id, data) =>
    api.put(`/sales/proposal-templates/${id}`, data).then(r => r.data).catch(handleErr),

  delete: (id) =>
    api.delete(`/sales/proposal-templates/${id}`).then(r => r.data).catch(handleErr),

  clone: (id) =>
    api.post(`/sales/proposal-templates/${id}/clone`).then(r => r.data).catch(handleErr),
}

export default proposalTemplateApi
