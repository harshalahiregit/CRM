// Login, register, logout — /api/auth/*
import api from '@/lib/api'

export const authApi = {
  login: (payload) => api.post('/auth/login', payload).then(r => r.data),
  register: (payload) => api.post('/auth/register', payload).then(r => r.data),
  registerVendor: (payload) => api.post('/auth/register/vendor', payload).then(r => r.data),
  registerTPV: (payload) => api.post('/auth/register/tpv', payload).then(r => r.data),
  registerClient: (payload) => api.post('/auth/register/client', payload).then(r => r.data),
  logout: () => api.post('/auth/logout').then(r => r.data),
  me: () => api.get('/auth/me').then(r => r.data),

  // ── Session management (Phase 3) ──────────────────────────────────
  sessions: () => api.get('/auth/sessions').then(r => r.data),
  revokeSession: (id) => api.delete(`/auth/sessions/${id}`).then(r => r.data),
  logoutOthers: () => api.post('/auth/sessions/logout-others').then(r => r.data),
  heartbeat: () => api.post('/auth/heartbeat').then(r => r.data),
  forceLogout: (userId) => api.post(`/admin/users/${userId}/force-logout`).then(r => r.data),
}

export default authApi
