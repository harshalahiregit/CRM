// Shared tags — /api/tags (workspace-wide, not module-owned).
// Attaching tags to a record is done on that record's own endpoint (tags: [...]),
// so this only covers listing and managing the tag vocabulary itself.
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.message || err?.response?.data?.error || 'Something went wrong'
  throw new Error(msg)
}
const unwrap = (r) => r.data?.data ?? r.data

export const tagApi = {
  // type: 'task' | 'project' — narrows to tags actually used on that type.
  list: (type) => api.get('/tags', { params: type ? { type } : {} }).then(unwrap).catch(handleErr),
  rename: (id, name, color) => api.put(`/tags/${id}`, { name, color }).then(unwrap).catch(handleErr),
  remove: (id) => api.delete(`/tags/${id}`).then(unwrap).catch(handleErr),
}

export default tagApi
