// Advanced Status Manager — /api/statuses/{type} (type = task | project).
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

const unwrap = (r) => r.data?.data ?? r.data

export const statusApi = {
  list: (type) => api.get(`/statuses/${type}`).then(unwrap).catch(handleErr),
  create: (type, data) => api.post(`/statuses/${type}`, data).then(unwrap).catch(handleErr),
  update: (type, id, data) => api.put(`/statuses/${type}/${id}`, data).then(unwrap).catch(handleErr),
  remove: (type, id) => api.delete(`/statuses/${type}/${id}`).then(unwrap).catch(handleErr),
  reorder: (type, ordered_ids) => api.post(`/statuses/${type}/reorder`, { ordered_ids }).then(unwrap).catch(handleErr),
}

export default statusApi
