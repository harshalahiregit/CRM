import axios from 'axios'

/**
 * Dedicated axios instance for the Purchase Vendor portal. It carries the
 * Purchase-vendor's OWN Sanctum token (separate storage key from the shared user
 * token), and 401s bounce to the Purchase vendor login — completely independent
 * of the shared user/vendor auth.
 */
const KEY = 'pv_portal_token'

export const pvToken = {
  get: () => localStorage.getItem(KEY),
  set: (t) => localStorage.setItem(KEY, t),
  clear: () => localStorage.removeItem(KEY),
  has: () => !!localStorage.getItem(KEY),
}

const pvApi = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
})

pvApi.interceptors.request.use((config) => {
  const t = pvToken.get()
  if (t) config.headers.Authorization = `Bearer ${t}`
  return config
})

pvApi.interceptors.response.use(
  (r) => r,
  (error) => {
    if (error.response?.status === 401) {
      pvToken.clear()
      if (!window.location.pathname.startsWith('/purchase-portal/login')) {
        window.location.href = '/purchase-portal/login'
      }
    }
    return Promise.reject(error)
  },
)

export default pvApi
