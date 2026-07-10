// Proposal Templates CRUD + clone — /api/sales/proposal-templates/*
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.error || err?.response?.data?.message || 'Something went wrong'
  throw new Error(msg)
}

export const proposalTemplateApi = {
  list: () =>
    api.get('/sales/proposal-templates').then(r => r.data).catch(handleErr),

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
