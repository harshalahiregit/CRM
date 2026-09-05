import axios from 'axios'
import { attachMediaCompression } from './mediaCompress'
import { getToken, clearAuth } from '@/lib/authStorage'
import { isSessionFailure } from '@/lib/sessionFailure'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  withCredentials: false, // Changed from true - not needed for token-based auth
})

// Uploads are shrunk on the way out — see src/lib/mediaCompress.js. Hooked
// here rather than at the ~50 upload sites, so every one is covered.
attachMediaCompression(api)

// ── Request interceptor: attach token ────────────────────────────────
api.interceptors.request.use((config) => {
  const token = getToken()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// ── Response interceptor: end the session only when it has actually ended ──
//
// #45 — this used to sign the user out on ANY 401, so a single endpoint that
// answered a permission problem with 401 instead of 403 logged them out. A 403
// never reaches here, and a 401 that is not auth-shaped is passed to the caller
// to display.
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (isSessionFailure(error, !!getToken())) {
      clearAuth()
      if (!window.location.pathname.startsWith('/auth')) {
        window.location.href = '/auth/login'
      }
    }
    return Promise.reject(error)
  },
)

export default api
