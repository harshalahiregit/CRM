// In-app notifications — the header bell. Scoped to the authenticated user.
import api from '@/lib/api'

const handleErr = (err) => {
  const msg = err?.response?.data?.message || err?.message || 'Request failed'
  throw new Error(msg)
}
const unwrap = (r) => r.data?.data ?? r.data

export const notificationApi = {
  // → { items: [...], unread_count: n }
  list: () => api.get('/notifications').then(unwrap).catch(handleErr),
  markRead: (id) => api.patch(`/notifications/${id}/read`).then(unwrap).catch(handleErr),
  markAllRead: () => api.post('/notifications/read-all').then(unwrap).catch(handleErr),
}

export default notificationApi
